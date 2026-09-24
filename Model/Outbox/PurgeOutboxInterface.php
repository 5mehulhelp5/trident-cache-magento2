<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Model\Outbox;

/**
 * X02: committed invalidation intent, kept until Trident acknowledges it.
 *
 * `enqueue()` writes through the connection the entity was saved with, so
 * inside a transaction the intent commits — or rolls back — WITH the data.
 * That is the point: a purge recorded only after the commit is lost if the
 * process dies between the two, and one recorded before the commit is sent
 * for data nobody can read yet.
 */
interface PurgeOutboxInterface
{
    /**
     * Record an invalidation in the current transaction (or autocommit).
     *
     * @param string $kind OutboxEntry::KIND_TAGS or OutboxEntry::KIND_ALL
     * @param array<string> $tags
     * @param string|null $instance X03: the instance that owes it. One row
     *        per instance, so each is acknowledged — and retried — on its own.
     * @return void
     */
    public function enqueue(string $kind, array $tags = [], ?string $instance = null): void;

    /**
     * Committed entries whose next attempt is due, oldest first.
     *
     * @param int $limit
     * @param bool $ignoreBackoff Also entries still waiting out a backoff —
     *        for an operator's "deliver now", not for automatic retries.
     * @param array<int, string> $instances X03: only entries owed to these
     *        instances, plus those written before X03. Empty: all entries.
     * @return array<int, OutboxEntry>
     */
    public function due(int $limit, bool $ignoreBackoff = false, array $instances = []): array;

    /**
     * X03: drop everything owed to an instance that is gone for good.
     *
     * @param string $instance
     * @return int Entries removed.
     */
    public function forgetInstance(string $instance): int;

    /**
     * Drop acknowledged entries. Unknown ids are ignored: a concurrent drain
     * may have delivered them already (delivery is idempotent).
     *
     * @param array<int> $ids
     * @return void
     */
    public function remove(array $ids): void;

    /**
     * Keep entries after a failed attempt and schedule the next one.
     *
     * @param array<int> $ids
     * @param string $reason
     * @return void
     */
    public function fail(array $ids, string $reason): void;

    /**
     * Pending count, oldest age in seconds, latest failure, and (X03) the
     * pending count per instance ('' for rows written before X03).
     *
     * @return array{pending: int, oldest_age: int|null, last_error: string|null, last_error_at: string|null, by_instance: array<string, int>}
     */
    public function stats(): array;
}
