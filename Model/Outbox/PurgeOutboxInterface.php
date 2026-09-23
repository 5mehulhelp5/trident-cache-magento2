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
     * @return void
     */
    public function enqueue(string $kind, array $tags = []): void;

    /**
     * Committed entries whose next attempt is due, oldest first.
     *
     * @param int $limit
     * @return array<int, OutboxEntry>
     */
    public function due(int $limit): array;

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
     * Pending count, oldest age in seconds, latest failure.
     *
     * @return array{pending: int, oldest_age: int|null, last_error: string|null, last_error_at: string|null}
     */
    public function stats(): array;
}
