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
 * One committed, not yet acknowledged invalidation.
 */
class OutboxEntry
{
    public const KIND_TAGS = 'tags';
    public const KIND_ALL = 'all';

    /**
     * @param int $id
     * @param string $kind
     * @param array<string> $tags
     * @param int $attempts
     */
    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly array $tags,
        public readonly int $attempts = 0
    ) {
    }
}
