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
 * Supported setup: the single `default` connection, which is all Open Source
 * has. Both the transaction check and the callback key use it, so an entity
 * saved through another connection (Commerce split database, a module's own
 * connection) is checked against the wrong transaction.
 */
class PurgeAfterCommit
{
    /**
     * Tags per request. Trident's admin API refuses a body over
     * `max_body_size` (1 MiB by default) with 413, and the client only logs a
     * failure — so a large transaction merged into one request would lose every
     * purge for data that did commit. 1000 tags is a few dozen KiB.
     */
    private const MAX_TAGS_PER_REQUEST = 1000;

    /**
     * Tags waiting for the commit, as keys for deduplication.
     *
     * @var array<string, true>
     */
    private array $pendingTags = [];

    /**
     * Whether a full purge is waiting for the commit; it supersedes the tags.
     *
     * @var bool
     */
    private bool $pendingAll = false;

    /**
     * @param ResourceConnection $resourceConnection
     * @param TridentClient $tridentClient
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TridentClient $tridentClient
    ) {
    }

    /**
     * Purge by tags now, or after the commit when a transaction is open.
     *
     * @param array<string> $tags
     * @return void
     */
    public function purgeTags(array $tags): void
    {
        if ($tags === []) {
            return;
        }
        if (!$this->inTransaction()) {
            $this->sendTags(array_values($tags));
            return;
        }
        foreach ($tags as $tag) {
            $this->pendingTags[(string) $tag] = true;
        }
        $this->deferFlush();
    }

    /**
     * Purge everything now, or after the commit when a transaction is open.
     *
     * @return void
     */
    public function purgeAll(): void
    {
        if (!$this->inTransaction()) {
            $this->tridentClient->purgeAll();
            return;
        }
        $this->pendingAll = true;
        $this->deferFlush();
    }

    /**
     * Send everything pending.
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
            $this->tridentClient->purgeAll();
            return;
        }
        if ($this->pendingTags === []) {
            return;
        }
        $tags = array_map('strval', array_keys($this->pendingTags));
        $this->pendingTags = [];
        $this->sendTags($tags);
    }

    /**
     * Send tags in requests the admin API accepts.
     *
     * @param array<string> $tags
     * @return void
     */
    private function sendTags(array $tags): void
    {
        foreach (array_chunk($tags, self::MAX_TAGS_PER_REQUEST) as $chunk) {
            $this->tridentClient->purgeTags($chunk);
        }
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
