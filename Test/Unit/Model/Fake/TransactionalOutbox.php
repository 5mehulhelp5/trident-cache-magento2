<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model\Fake;

use Qoliber\TridentCache\Model\Outbox\OutboxEntry;
use Qoliber\TridentCache\Model\Outbox\PurgeOutboxInterface;

/**
 * An outbox with InnoDB's visibility rules, for unit tests: a row written
 * while `$inTransaction()` is true is invisible until `commit()` and gone on
 * `rollBack()`. Ids are allocated at write time, like AUTO_INCREMENT — so a
 * row can carry a LOWER id than a row committed before it.
 */
class TransactionalOutbox implements PurgeOutboxInterface
{
    /** @var array<int, OutboxEntry> committed rows by id */
    public array $rows = [];

    /** @var array<int, OutboxEntry> written in the open transaction */
    private array $uncommitted = [];

    /** @var array<int, string> */
    public array $failures = [];

    private int $nextId = 1;

    /** @var \Closure(): bool */
    private \Closure $inTransaction;

    public bool $broken = false;

    public function __construct(callable $inTransaction)
    {
        $this->inTransaction = \Closure::fromCallable($inTransaction);
    }

    public function enqueue(string $kind, array $tags = [], ?string $instance = null): void
    {
        if ($this->broken) {
            throw new \RuntimeException("Base table or view not found: qoliber_trident_purge_outbox");
        }
        $entry = new OutboxEntry($this->nextId++, $kind, array_values($tags), 0, $instance);
        if (($this->inTransaction)()) {
            $this->uncommitted[$entry->id] = $entry;
        } else {
            $this->rows[$entry->id] = $entry;
        }
    }

    /** Reserve an id for another connection's still-open write. */
    public function reserveForOtherTransaction(string $kind, array $tags, ?string $instance = 'default'): OutboxEntry
    {
        return new OutboxEntry($this->nextId++, $kind, $tags, 0, $instance);
    }

    public function commitOther(OutboxEntry $entry): void
    {
        $this->rows[$entry->id] = $entry;
        ksort($this->rows);
    }

    public function commit(): void
    {
        $this->rows += $this->uncommitted;
        ksort($this->rows);
        $this->uncommitted = [];
    }

    public function rollBack(): void
    {
        $this->uncommitted = [];
    }

    /** @var array<int, true> ids waiting out a backoff */
    public array $backingOff = [];

    public function due(int $limit, bool $ignoreBackoff = false, array $instances = []): array
    {
        if ($this->broken) {
            throw new \RuntimeException('Base table or view not found');
        }
        $rows = array_values(array_filter(
            $this->rows,
            fn (OutboxEntry $e): bool => ($ignoreBackoff || !isset($this->backingOff[$e->id]))
                && ($instances === [] || $e->instance === null || in_array($e->instance, $instances, true))
        ));
        return array_slice($rows, 0, $limit);
    }

    public function remove(array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->rows[$id]);
        }
    }

    public function fail(array $ids, string $reason): void
    {
        foreach ($ids as $id) {
            if (isset($this->rows[$id])) {
                $this->failures[$id] = $reason;
                $this->backingOff[$id] = true;
                $e = $this->rows[$id];
                $this->rows[$id] = new OutboxEntry($e->id, $e->kind, $e->tags, $e->attempts + 1, $e->instance);
            }
        }
    }

    public function forgetInstance(string $instance): int
    {
        $before = count($this->rows);
        $this->rows = array_filter($this->rows, fn (OutboxEntry $e): bool => $e->instance !== $instance);
        return $before - count($this->rows);
    }

    /** @return array<int, OutboxEntry> every committed row */
    public function all(): array
    {
        return array_values($this->rows);
    }

    public function stats(): array
    {
        return [
            'pending' => count($this->rows),
            'oldest_age' => $this->rows === [] ? null : 0,
            'last_error' => $this->failures === [] ? null : end($this->failures),
            'last_error_at' => null,
            'by_instance' => array_count_values(array_map(
                fn (OutboxEntry $e): string => $e->instance ?? '',
                array_values($this->rows)
            )),
        ];
    }
}
