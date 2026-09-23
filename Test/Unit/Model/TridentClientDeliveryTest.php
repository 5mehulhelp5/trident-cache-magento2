<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model;

use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\TridentClient;

/**
 * X02: a purge counts as delivered only when Trident ACKNOWLEDGED it — a 200
 * whose body is the engine's purge schema. Magento's Curl does not throw on
 * an HTTP error status, and the client used to hand back whatever body came
 * with it: a 401 (`{"error": ...}`) decoded to a non-null array and looked
 * exactly like a completed purge.
 */
class TridentClientDeliveryTest extends TestCase
{
    private Curl&MockObject $curl;
    private TridentClient $client;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $config = $this->createMock(Config::class);
        $config->method('isTridentEnabled')->willReturn(true);
        $config->method('getApiUrl')->willReturn('http://127.0.0.1:9000');
        $config->method('getApiToken')->willReturn('token');
        $config->method('isSoftPurgeEnabled')->willReturn(false);
        $this->client = new TridentClient($this->curl, new NullLogger(), $config);
    }

    private function answer(int $status, string $body): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
    }

    private const PURGE_ACK = '{"purged":3,"mode":"hard","state":"applied","barrier":"delivery"}';

    /**
     * @return array<string, array{int, string}>
     */
    public static function unacknowledged(): array
    {
        return [
            'unauthorized' => [401, '{"error":"unauthorized"}'],
            'rate limited' => [429, '{"error":"too many requests"}'],
            'unavailable' => [503, '{"error":"unavailable"}'],
            'server error' => [500, ''],
            'queue full' => [503, '{"error":"queue full"}'],
            'not json' => [200, '<html>proxy error</html>'],
            'error body with 200' => [200, '{"error":"something"}'],
            'missing purged' => [200, '{"mode":"hard","state":"applied"}'],
            'missing mode' => [200, '{"purged":1}'],
            'state not a string' => [200, '{"purged":1,"mode":"hard","state":7}'],
            'purged not a number' => [200, '{"purged":"3","mode":"hard","state":"applied"}'],
            'redirect' => [302, ''],
        ];
    }

    #[DataProvider('unacknowledged')]
    public function testAnUnacknowledgedTagPurgeIsNotReportedAsDelivered(int $status, string $body): void
    {
        $this->answer($status, $body);

        $this->assertNull($this->client->purgeTags(['cat_p_1']), 'purgeTags() must not return a result');
        $this->assertFalse($this->client->deliverTags(['cat_p_1']));
    }

    public function testAnAcknowledgedTagPurgeIsDelivered(): void
    {
        $this->answer(200, self::PURGE_ACK);

        $this->assertTrue($this->client->deliverTags(['cat_p_1']));
        $this->assertSame(3, $this->client->purgeTags(['cat_p_1'])['purged']);
    }

    /**
     * The released 1.6/1.7 engines answer without `state` — the fleet's
     * engines. Found on the live Magento stack: requiring the 1.8 schema
     * retried every purge against a 1.7.0 edge forever.
     */
    public function testAnOlderEngineAcknowledgementIsDelivered(): void
    {
        $this->answer(200, '{"purged":0,"mode":"soft","queued_refresh":0}');

        $this->assertTrue($this->client->deliverTags(['cat_p_1']));
    }

    public function testATransportFailureIsNotDelivered(): void
    {
        $this->curl->method('post')->willThrowException(new \Exception('Connection timed out'));

        $this->assertFalse($this->client->deliverTags(['cat_p_1']));
        $this->assertNull($this->client->purgeTags(['cat_p_1']));
    }

    public function testAFullClearNeedsClearedTrue(): void
    {
        $this->answer(200, '{"cleared":false,"entries_removed":0,"bytes_freed":0,"error":"busy"}');
        $this->assertFalse($this->client->deliverAll());
        $this->assertNull($this->client->purgeAll());
    }

    public function testAnAcknowledgedFullClearIsDelivered(): void
    {
        $this->answer(200, '{"cleared":true,"entries_removed":10,"bytes_freed":4096}');
        $this->assertTrue($this->client->deliverAll());
    }

    public function testAnUnconfiguredClientDeliversNothing(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isTridentEnabled')->willReturn(true);
        $config->method('getApiUrl')->willReturn('http://127.0.0.1:9000');
        $config->method('getApiToken')->willReturn('');
        $client = new TridentClient($this->curl, new NullLogger(), $config);
        $this->curl->expects($this->never())->method('post');

        $this->assertFalse($client->deliverTags(['cat_p_1']));
    }
}
