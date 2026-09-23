<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Qoliber\TridentCache\Model\TridentClient;

class CacheFlushObserver implements ObserverInterface
{
    public function __construct(
        private readonly TridentClient $tridentClient,
        private readonly PurgeAfterCommit $purgeAfterCommit
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->tridentClient->isEnabled()) {
            return;
        }

        // Clear all Trident cache when Magento cache is flushed — through the
        // outbox, so a refused clear is retried instead of lost (X02).
        $this->purgeAfterCommit->purgeAll();
    }
}
