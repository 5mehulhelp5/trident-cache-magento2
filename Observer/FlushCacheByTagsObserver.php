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

use Magento\Framework\App\Cache\Tag\Resolver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Qoliber\TridentCache\Model\PurgeStrategy;

/**
 * The event fires from AbstractModel::afterSave(), inside the save
 * transaction. Tags are resolved now, while the entity still knows what
 * changed; the purge itself waits for the commit (see PurgeAfterCommit).
 */
class FlushCacheByTagsObserver implements ObserverInterface
{
    public function __construct(
        private readonly PurgeAfterCommit $purgeAfterCommit,
        private readonly Config $config,
        private readonly Resolver $tagResolver,
        private readonly PurgeStrategy $purgeStrategy
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isTridentEnabled()) {
            return;
        }

        $object = $observer->getEvent()->getObject();
        if (!is_object($object)) {
            return;
        }

        $tags = $this->tagResolver->getTags($object);

        if (!empty($tags)) {
            $tags = $this->purgeStrategy->filterTags($object, $tags);
            $normalizedTags = array_unique(array_map('strtolower', $tags));
            $this->purgeAfterCommit->purgeTags($normalizedTags);
        }
    }
}
