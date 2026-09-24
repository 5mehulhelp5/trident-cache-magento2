<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Plugin;

use Magento\Framework\App\Cache\Manager;
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Qoliber\TridentCache\Model\TridentClient;

/**
 * Purges the edge when Magento's own cache is cleaned or flushed from code.
 *
 * `bin/magento cache:clean` and `cache:flush` — the last line of most deploy
 * scripts — go through `Cache\Manager`, and `flush()` wipes the **backend**
 * without going through the cache-type objects at all. The admin events
 * (`adminhtml_cache_flush_*`) never fire either, since no admin controller ran.
 *
 * Measured on 2.4.x before this plugin existed: `cache:flush` emptied every
 * Magento cache and sent Trident **nothing** — the edge kept serving pages
 * built from the templates and configuration that had just been replaced. A
 * deploy looked clean and the site did not change.
 *
 * `flush()` always purges: the backend is gone, so nothing the edge holds can
 * still be vouched for. `clean()` purges only when the page cache is among the
 * types — cleaning `config` or `layout` alone leaves the stored pages valid
 * until something actually invalidates them, and purging on every partial
 * clean would turn a routine `cache:clean config` into a cold edge.
 */
class CacheManagerPlugin
{
    /** @var string The Magento cache type whose contents mirror the edge. */
    private const PAGE_CACHE_TYPE = 'full_page';

    /**
     * @param TridentClient $tridentClient
     * @param PurgeAfterCommit $purgeAfterCommit
     */
    public function __construct(
        private readonly TridentClient $tridentClient,
        private readonly PurgeAfterCommit $purgeAfterCommit
    ) {
    }

    /**
     * @param Manager $subject
     * @param mixed $result
     * @param array<string> $types
     * @return mixed
     */
    public function afterClean(Manager $subject, $result, array $types = [])
    {
        if ($this->tridentClient->isEnabled() && in_array(self::PAGE_CACHE_TYPE, $types, true)) {
            $this->purgeAfterCommit->purgeAll();
        }

        return $result;
    }

    /**
     * @param Manager $subject
     * @param mixed $result
     * @param array<string> $types
     * @return mixed
     */
    public function afterFlush(Manager $subject, $result, array $types = [])
    {
        if ($this->tridentClient->isEnabled()) {
            $this->purgeAfterCommit->purgeAll();
        }

        return $result;
    }
}
