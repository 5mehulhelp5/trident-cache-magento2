<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Controller\Adminhtml\Events;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\Config;

class Poll extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Qoliber_TridentCache::events';

    /** @var array<int, string> */
    private const STREAMS = ['requests', 'cache', 'backends', 'errors'];

    private const POLL_TIMEOUT = 2;

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Curl $curl,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        if (!$this->config->isTridentEnabled()) {
            return $resultJson->setData(['events' => [], 'error' => 'Trident is not configured.']);
        }

        $stream = (string) $this->getRequest()->getParam('stream', 'requests');
        if (!in_array($stream, self::STREAMS, true)) {
            $stream = 'requests';
        }

        try {
            $apiUrl = rtrim($this->config->getApiUrl(), '/');

            $this->curl->setHeaders(['Authorization' => 'Bearer ' . $this->config->getApiToken()]);
            $this->curl->setOption(CURLOPT_TIMEOUT, self::POLL_TIMEOUT);
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->get($apiUrl . '/admin/events/' . $stream);

            $events = $this->parseEvents((string) $this->curl->getBody());

            return $resultJson->setData(['events' => $events, 'stream' => $stream]);
        } catch (\Exception $e) {
            $this->logger->error('Trident events poll failed', ['stream' => $stream, 'error' => $e->getMessage()]);
            return $resultJson->setData(['events' => [], 'error' => $e->getMessage()]);
        }
    }

    /**
     * Parse `data:` lines out of a buffered SSE chunk into decoded JSON event objects.
     *
     * @param string $chunk
     * @return array<int, array<string, mixed>|string>
     */
    private function parseEvents(string $chunk): array
    {
        $events = [];

        foreach (preg_split('/\r\n|\r|\n/', $chunk) ?: [] as $line) {
            if (strpos($line, 'data:') !== 0) {
                continue;
            }

            $payload = trim(substr($line, 5));
            if ($payload === '') {
                continue;
            }

            $decoded = json_decode($payload, true);
            $events[] = $decoded !== null ? $decoded : $payload;
        }

        return $events;
    }
}
