<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Model\CallbackPool;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\Outbox\OutboxEntry;
use Qoliber\TridentCache\Model\Outbox\PurgeOutboxInterface;

/**
 * Sends a purge only once the transaction that caused it has committed.
 *
 * Magento dispatches `clean_cache_by_tags` from AbstractModel::afterSave(),
 * which runs INSIDE the save transaction. A purge sent from there reaches
 * Trident while the new data is still invisible to every other connection.
 * With soft purge the refresh worker re-fetches the page at once, the
 * storefront renders it from the old, committed state, and that old page is
 * stored again as fresh — for the whole TTL, because the only purge has
 * already been spent. The same render also refills the storefront's own
 * block_html cache with the old content, so a later purge re-fetches the old
 * block too. Measured on the e2e stack: a price change held 4 s between
 * afterSave and commit stayed old on the edge AND at the origin until the
 * indexer cron ran; with no cron, nothing else clears it before the TTL.
 *
 * Outside a transaction the purge goes out immediately, as before. Inside
 * one, purges are merged and sent from a commit callback. Magento 2.4 runs
 * those through the `execute_commit_callbacks` plugin on every commit that
 * brings the level back to zero, raw adapter commits included, and clears
 * them on rollback. Tags a rollback leaves pending go out with the next
 * flush — an unneeded purge, which is what the module sent before this class
 * existed — or not at all if no transaction follows; the data did not change.
 *
 * X02 — durable delivery. Every purge is first RECORDED in the outbox
 * ({@see PurgeOutboxInterface}) through the same connection, so inside a
 * transaction the record commits or rolls back with the data. It is removed
 * only when Trident acknowledges it ({@see TridentClient::deliverTags()}: a
 * 200 with the engine's purge schema). A 401, 429, 5xx, timeout or dead
 * process leaves it in the outbox, and the next drain — the next commit, or
 * the cron job — sends it again. Purges are idempotent, so a duplicate after
 * a crash between "sent" and "removed" costs one extra purge, never a lost one.
 *
 * X03 — several instances. A purge is recorded once per instance and each
 * record is acknowledged, backed off and retried on its own: an edge that is
 * down keeps its own purges pending without holding back the others.
 *
 * Supported setup: the single `default` connection, which is all Open Source
 * has. Both the transaction check and the callback key use it, so an entity
 * saved through another connection (Commerce split database, a module's own
 * connection) is checked against the wrong transaction.
 */
class PurgeAfterCommit
{
    /**
     * Tags per request. Trident's admin API refuses a body over
     * `max_body_size` (1 MiB by default) with 413 — so a large transaction
     * merged into one request would be refused whole. 1000 tags is a few
     * dozen KiB.
     */
    private const MAX_TAGS_PER_REQUEST = 1000;

    /** Entries a drain looks at from a request thread. */
    private const REQUEST_DRAIN_LIMIT = 50;

    /**
     * Consecutive failed deliveries after which a drain stops: an edge that
     * is down is not waited out N times from a storefront request. The
     * entries stay; the next drain (or cron) retries them.
     */
    private const MAX_CONSECUTIVE_FAILURES = 3;

    /**
     * Fallback only — used when the outbox cannot be written (the module was
     * upgraded but `setup:upgrade` has not created its table yet). Tags
     * waiting for the commit, as keys for deduplication.
     *
     * @var array<string, true>
     */
    private array $pendingTags = [];

    /**
     * Fallback only: a full purge waiting for the commit.
     *
     * @var bool
     */
    private bool $pendingAll = false;

    /**
     * Config-derived tags, held until the configuration is reloaded.
     *
     * @var array<string, true>
     */
    private array $pendingConfigTags = [];

    /**
     * Whether the end-of-request floor for config tags is armed.
     *
     * @var bool
     */
    private bool $configFloorRegistered = false;

    /**
     * @param ResourceConnection $resourceConnection
     * @param TridentClient $tridentClient
     * @param PurgeOutboxInterface $outbox
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TridentClient $tridentClient,
        private readonly PurgeOutboxInterface $outbox,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Record a purge by tags; send it now, or after the commit when a
     * transaction is open.
     *
     * @param array<string> $tags
     * @return void
     */
    public function purgeTags(array $tags): void
    {
        if ($tags === []) {
            return;
        }
        $tags = array_values(array_map('strval', $tags));
        try {
            foreach ($this->instanceNames() as $instance) {
                foreach (array_chunk($tags, self::MAX_TAGS_PER_REQUEST) as $chunk) {
                    $this->outbox->enqueue(OutboxEntry::KIND_TAGS, $chunk, $instance);
                }
            }
        } catch (\Throwable $e) {
            $this->outboxUnavailable($e);
            if (!$this->inTransaction()) {
                $this->sendDirect($tags);
                return;
            }
            foreach ($tags as $tag) {
                $this->pendingTags[$tag] = true;
            }
        }
        $this->afterRecording();
    }

    /**
     * Hold config-derived tags until the configuration has been reloaded.
     *
     * Saving a configuration value does not make the new value visible: the
     * admin save runs `configStorage->save()` and only afterwards
     * `ReinitableConfig::reinit()`. A purge sent at save time therefore hands
     * the refresh a storefront that still answers with the OLD value, and the
     * edge stores it again — measured on 2.4.x with `/robots.txt`, where the
     * edge ended up permanently ONE change behind: save A then B, and the edge
     * serves A.
     *
     * These tags are flushed by the reinit plugin instead — deliberately NOT
     * by the commit callback, which runs before the reload and would spend the
     * purge on the old value again. A shutdown flush is the floor for a CLI
     * path that never reinitialises: late beats never.
     *
     * @param array<string> $tags
     * @return void
     */
    public function purgeConfigTags(array $tags): void
    {
        if ($tags === []) {
            return;
        }
        foreach ($tags as $tag) {
            $this->pendingConfigTags[(string) $tag] = true;
        }
        if (!$this->configFloorRegistered) {
            // Floor, not the intended path: a CLI save that never reinitialises
            // would otherwise never purge at all. End of process is late, and
            // late beats never.
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            register_shutdown_function([$this, 'flushConfigTags']);
            $this->configFloorRegistered = true;
        }
    }

    /**
     * The configuration is now reloaded: record and send the held tags.
     *
     * @return void
     */
    public function flushConfigTags(): void
    {
        if ($this->pendingConfigTags === []) {
            return;
        }
        $tags = array_map('strval', array_keys($this->pendingConfigTags));
        $this->pendingConfigTags = [];
        try {
            $this->purgeTags($tags);
        } catch (\Throwable $e) {
            // Also runs as a shutdown function: never turn a lost purge into a
            // fatal error at the end of the request.
            $this->logger->error('Trident config purge failed', ['error' => $e->getMessage(), 'tags' => $tags]);
        }
    }

    /**
     * Record a full purge; send it now, or after the commit.
     *
     * @return void
     */
    public function purgeAll(): void
    {
        // A full clear covers every config tag still held for the reload.
        $this->pendingConfigTags = [];
        try {
            foreach ($this->instanceNames() as $instance) {
                $this->outbox->enqueue(OutboxEntry::KIND_ALL, [], $instance);
            }
        } catch (\Throwable $e) {
            $this->outboxUnavailable($e);
            if (!$this->inTransaction()) {
                $this->tridentClient->deliverAll();
                return;
            }
            $this->pendingAll = true;
        }
        $this->afterRecording();
    }

    /**
     * Commit callback: send what the commit made durable.
     *
     * Every deferral attaches its own callback, so this runs more than once
     * per commit; all but the first find nothing to send.
     *
     * @return void
     */
    public function flush(): void
    {
        if ($this->pendingAll) {
            $this->pendingAll = false;
            $this->pendingTags = [];
            $this->tridentClient->deliverAll();
        } elseif ($this->pendingTags !== []) {
            $tags = array_map('strval', array_keys($this->pendingTags));
            $this->pendingTags = [];
            $this->sendDirect($tags);
        }
        $this->drain(self::REQUEST_DRAIN_LIMIT);
    }

    /**
     * Deliver due outbox entries; remove each one Trident acknowledges.
     *
     * A due full clear goes first and, once acknowledged, removes the tag
     * entries this drain READ before sending it — and only those. Removing
     * by id range instead would also take a row an open transaction wrote
     * earlier but committed after the clear was sent: that change would never
     * be purged. An entry not read here is simply delivered later (one
     * redundant purge, never a lost one).
     *
     * Remaining tag entries are merged into requests of at most 1000 unique
     * tags, oldest first; an acknowledged request removes exactly the entries
     * it carried.
     *
     * @param int $limit Entries to read.
     * @param bool $ignoreBackoff Deliver entries still in backoff too — an
     *        operator's "deliver now" after fixing the cause (a token, an
     *        outage) must not wait out a retry schedule.
     * @return int Entries removed.
     */
    public function drain(int $limit, bool $ignoreBackoff = false): int
    {
        if ($this->inTransaction()) {
            return 0;
        }
        $instances = [];
        foreach ($this->tridentClient->instances() as $instance) {
            $instances[$instance->name] = $instance;
        }
        try {
            // Only instances that are still configured: a row owed to one
            // that was removed must not take the drain's slots forever.
            $entries = $this->outbox->due($limit, $ignoreBackoff, array_keys($instances));
        } catch (\Throwable $e) {
            $this->outboxUnavailable($e);
            return 0;
        }
        if ($entries === []) {
            return 0;
        }

        // X03: a row written before instances existed is owed to every one.
        // Only this batch's are split — the work stays bounded by $limit even
        // when X02 left thousands behind — and they keep their age, attempts
        // and backoff. The copies go out with the next drain.
        $legacy = array_values(array_filter($entries, fn (OutboxEntry $e): bool => $e->instance === null));
        if ($legacy !== []) {
            try {
                $this->outbox->splitAmong(
                    array_map(fn (OutboxEntry $e): int => $e->id, $legacy),
                    array_map('strval', array_keys($instances))
                );
            } catch (\Throwable $e) {
                $this->outboxUnavailable($e);
                return 0;
            }
            $entries = array_values(array_filter($entries, fn (OutboxEntry $e): bool => $e->instance !== null));
        }

        $byInstance = [];
        foreach ($entries as $entry) {
            if (!isset($instances[(string) $entry->instance])) {
                // due() matched it, so only a name differing in a way SQL
                // ignores gets here. Never hand it to the wrong instance.
                $this->logger->warning('Trident purge owed to an unknown instance skipped', [
                    'instance' => $entry->instance,
                    'id' => $entry->id,
                ]);
                continue;
            }
            $byInstance[(string) $entry->instance][] = $entry;
        }
        $removed = 0;
        foreach ($byInstance as $name => $owed) {
            $removed += $this->deliver($this->tridentClient->forInstance($instances[$name]), $owed);
        }
        return $removed;
    }

    /**
     * Deliver one instance's due entries; see {@see drain()}.
     *
     * @param TridentClient $client Bound to the instance.
     * @param array<int, OutboxEntry> $entries
     * @return int Entries removed.
     */
    private function deliver(TridentClient $client, array $entries): int
    {
        $removed = 0;
        $failures = 0;
        $clears = array_values(array_filter($entries, fn (OutboxEntry $e): bool => $e->kind === OutboxEntry::KIND_ALL));
        if ($clears !== []) {
            $last = end($clears);
            $covered = array_values(array_filter($entries, fn (OutboxEntry $e): bool => $e->id <= $last->id));
            $ids = array_map(fn (OutboxEntry $e): int => $e->id, $covered);
            if ($client->deliverAll()) {
                $this->outbox->remove($ids);
                $removed += count($ids);
            } else {
                $this->outbox->fail(array_map(fn (OutboxEntry $e): int => $e->id, $clears), $this->failureReason($client));
                $failures++;
            }
            $entries = array_values(array_filter($entries, fn (OutboxEntry $e): bool => $e->id > $last->id));
        }

        foreach ($this->pack($entries) as [$ids, $tags]) {
            if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
                break;
            }
            if ($client->deliverTags($tags)) {
                $this->outbox->remove($ids);
                $removed += count($ids);
                $failures = 0;
            } else {
                $this->outbox->fail($ids, $this->failureReason($client));
                $failures++;
            }
        }
        return $removed;
    }

    /**
     * X03: the instances a new purge is owed to.
     *
     * @return array<int, string>
     */
    private function instanceNames(): array
    {
        return array_map(fn (Instance $i): string => $i->name, $this->tridentClient->instances());
    }

    /**
     * Merge tag entries, in order, into requests of at most 1000 unique tags.
     *
     * @param array<int, OutboxEntry> $entries
     * @return array<int, array{0: array<int>, 1: array<string>}>
     */
    private function pack(array $entries): array
    {
        $requests = [];
        $ids = [];
        $tags = [];
        foreach ($entries as $entry) {
            $merged = $tags + array_fill_keys($entry->tags, true);
            if ($ids !== [] && count($merged) > self::MAX_TAGS_PER_REQUEST) {
                $requests[] = [$ids, array_map('strval', array_keys($tags))];
                $ids = [];
                $merged = array_fill_keys($entry->tags, true);
            }
            $ids[] = $entry->id;
            $tags = $merged;
        }
        if ($ids !== []) {
            $requests[] = [$ids, array_map('strval', array_keys($tags))];
        }
        return $requests;
    }

    /**
     * Send now when the record is already durable, else after the commit.
     *
     * @return void
     */
    private function afterRecording(): void
    {
        if ($this->inTransaction()) {
            $this->deferFlush();
            return;
        }
        $this->flush();
    }

    /**
     * Fallback send (no outbox): best effort, as before X02.
     *
     * @param array<string> $tags
     * @return void
     */
    private function sendDirect(array $tags): void
    {
        foreach (array_chunk($tags, self::MAX_TAGS_PER_REQUEST) as $chunk) {
            $this->tridentClient->deliverTags($chunk);
        }
    }

    /**
     * @param TridentClient $client
     * @return string
     */
    private function failureReason(TridentClient $client): string
    {
        return $client->lastFailure() ?? 'not acknowledged';
    }

    /**
     * @param \Throwable $e
     * @return void
     */
    private function outboxUnavailable(\Throwable $e): void
    {
        $this->logger->error(
            'Trident purge outbox unavailable — purges are sent best-effort and can be lost '
            . 'until it is (run bin/magento setup:upgrade)',
            ['error' => $e->getMessage()]
        );
    }

    /**
     * Whether the default connection has a transaction open.
     *
     * @return bool
     */
    private function inTransaction(): bool
    {
        return $this->resourceConnection->getConnection()->getTransactionLevel() > 0;
    }

    /**
     * Register a flush for the commit of the open transaction.
     *
     * One callback per deferral, not one per request: a rollback clears the
     * pool, and the next transaction still needs its own flush.
     *
     * @return void
     */
    private function deferFlush(): void
    {
        CallbackPool::attach(spl_object_hash($this->resourceConnection->getConnection()), [$this, 'flush']);
    }
}
