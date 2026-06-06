<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Dns;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\TridentClient;

class Refresh extends Action
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::dns';

    public function __construct(
        Context $context,
        private readonly TridentClient $tridentClient,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            $result = $this->tridentClient->refreshDiscovery();

            if ($result !== null) {
                $this->messageManager->addSuccessMessage(
                    __('DNS discovery refresh triggered successfully.')
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __('Failed to refresh DNS discovery. Please check the logs.')
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Trident DNS discovery refresh error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Error refreshing DNS discovery: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setPath('trident/dns/index');
    }
}
