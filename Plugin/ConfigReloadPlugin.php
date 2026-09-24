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

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Qoliber\TridentCache\Model\PurgeAfterCommit;

/**
 * Sends the config purge once the new configuration is actually readable.
 *
 * A configuration save is two steps: the values are written, and then the
 * configuration is reinitialised. Between them the storefront still answers
 * with the OLD value, so a purge sent at save time is spent on a refresh that
 * re-stores exactly what was purged. Measured on 2.4.x against `/robots.txt`:
 * the edge settled one change behind for good — save A then B, and visitors
 * get A.
 *
 * Reinit is the first moment the new value can be served, which makes it the
 * right moment to invalidate.
 */
class ConfigReloadPlugin
{
    /**
     * @param PurgeAfterCommit $purgeAfterCommit
     */
    public function __construct(
        private readonly PurgeAfterCommit $purgeAfterCommit
    ) {
    }

    /**
     * @param ReinitableConfigInterface $subject
     * @param ReinitableConfigInterface $result
     * @return ReinitableConfigInterface
     */
    public function afterReinit(
        ReinitableConfigInterface $subject,
        $result
    ) {
        $this->purgeAfterCommit->flushConfigTags();

        return $result;
    }
}
