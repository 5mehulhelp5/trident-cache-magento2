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

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * The outbox in `qoliber_trident_purge_outbox`, on the `default` connection —
 * the connection {@see \Qoliber\TridentCache\Model\PurgeAfterCommit} checks
 * for an open transaction, so the row shares the entity save's transaction.
 */
class DbPurgeOutbox implements PurgeOutboxInterface
{
    public const TABLE = 'qoliber_trident_purge_outbox';

    /** Longest wait between attempts, seconds. */
    private const MAX_BACKOFF = 300;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function enqueue(string $kind, array $tags = []): void
    {
        $this->resourceConnection->getConnection()->insert($this->table(), [
            'kind' => $kind,
            'tags' => (string) json_encode(array_values($tags)),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function due(int $limit, bool $ignoreBackoff = false): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->table(), ['entity_id', 'kind', 'tags', 'attempts'])
            ->order('entity_id ASC')
            ->limit($limit);
        if (!$ignoreBackoff) {
            $select->where('next_attempt_at <= ?', new Expression('CURRENT_TIMESTAMP'));
        }
        $entries = [];
        foreach ($connection->fetchAll($select) as $row) {
            $tags = json_decode((string) $row['tags'], true);
            $entries[] = new OutboxEntry(
                (int) $row['entity_id'],
                (string) $row['kind'],
                is_array($tags) ? array_map('strval', $tags) : [],
                (int) $row['attempts']
            );
        }
        return $entries;
    }

    /**
     * @inheritDoc
     */
    public function remove(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->resourceConnection->getConnection()->delete($this->table(), ['entity_id IN (?)' => $ids]);
    }

    /**
     * @inheritDoc
     */
    public function fail(array $ids, string $reason): void
    {
        if ($ids === []) {
            return;
        }
        $this->resourceConnection->getConnection()->update(
            $this->table(),
            [
                'attempts' => new Expression('attempts + 1'),
                'last_error' => mb_substr($reason, 0, 1000),
                'last_error_at' => new Expression('CURRENT_TIMESTAMP'),
                // 1, 2, 4 … seconds, capped: a Trident that is down is retried
                // every few minutes, not hammered, and never given up on.
                'next_attempt_at' => new Expression(sprintf(
                    'CURRENT_TIMESTAMP + INTERVAL LEAST(POW(2, LEAST(attempts, 16)), %d) SECOND',
                    self::MAX_BACKOFF
                )),
            ],
            ['entity_id IN (?)' => $ids]
        );
    }

    /**
     * @inheritDoc
     */
    public function stats(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->table(), [
                'pending' => new Expression('COUNT(*)'),
                'oldest_age' => new Expression('TIMESTAMPDIFF(SECOND, MIN(created_at), CURRENT_TIMESTAMP)'),
            ])
        ) ?: [];
        $failure = $connection->fetchRow(
            $connection->select()
                ->from($this->table(), ['last_error', 'last_error_at'])
                ->where('last_error IS NOT NULL')
                ->order('last_error_at DESC')
                ->limit(1)
        ) ?: [];
        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'oldest_age' => isset($row['oldest_age']) ? (int) $row['oldest_age'] : null,
            'last_error' => $failure['last_error'] ?? null,
            'last_error_at' => $failure['last_error_at'] ?? null,
        ];
    }

    /**
     * @return string
     */
    private function table(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
