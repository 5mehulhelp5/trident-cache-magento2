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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\Instance;
use Qoliber\TridentCache\Model\TridentClient;

/**
 * X03: an invalidation goes to every instance and succeeds only if every one
 * acknowledged it; everything else goes to the first instance.
 */
class TridentClientInstancesTest extends TestCase
{
    /** @var array<int, array{string, string, string}> method, url, Authorization */
    private array $requests = [];

    /** @var array<string, array{int, string}> host => [status, body] */
    private array $answers = [];

    private string $lastUrl = '';

    /** @var array<string, string> */
    private array $headers = [];

    private TridentClient $client;

    protected function setUp(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('setHeaders')->willReturnCallback(function (array $h): void {
            $this->headers = $h;
        });
        $record = function (string $method) {
            return function (string $url) use ($method): void {
                $this->lastUrl = $url;
                $this->requests[] = [$method, $url, $this->headers['Authorization'] ?? ''];
            };
        };
        $curl->method('post')->willReturnCallback($record('POST'));
        $curl->method('get')->willReturnCallback($record('GET'));
        $curl->method('getStatus')->willReturnCallback(fn (): int => $this->answer()[0]);
        $curl->method('getBody')->willReturnCallback(fn (): string => $this->answer()[1]);

        $config = $this->createMock(Config::class);
        $config->method('isTridentEnabled')->willReturn(true);
        $config->method('getApiUrl')->willReturn('http://admin-edge:9301');
        $config->method('getApiToken')->willReturn('admin-token');
        $config->method('getInstances')->willReturn([
            new Instance('edge-1', 'http://edge-1:9301', 'token-1'),
            new Instance('edge-2', 'http://edge-2:9301/', 'token-2'),
        ]);
        $this->client = new TridentClient($curl, new NullLogger(), $config);
    }

    /** @return array{int, string} */
    private function answer(): array
    {
        $host = (string) parse_url($this->lastUrl, PHP_URL_HOST);
        return $this->answers[$host] ?? [200, '{"purged":2,"mode":"hard"}'];
    }

    public function testATagPurgeGoesToEveryInstanceWithItsOwnToken(): void
    {
        $result = $this->client->purgeTags(['cat_p_1']);

        $this->assertSame([
            ['POST', 'http://edge-1:9301/admin/purge/tags', 'Bearer token-1'],
            ['POST', 'http://edge-2:9301/admin/purge/tags', 'Bearer token-2'],
        ], $this->requests);
        $this->assertSame(4, $result['purged'], 'counts are summed');
        $this->assertSame(['edge-1', 'edge-2'], array_keys($result['instances']));
    }

    /**
     * The point of X03: edge-2 still serves the old page, so the purge did
     * not happen — even though edge-1 acknowledged it.
     */
    public function testOneInstanceRefusingFailsTheWholePurgeAndNamesIt(): void
    {
        $this->answers['edge-2'] = [401, '{"error":"unauthorized"}'];

        $this->assertNull($this->client->purgeTags(['cat_p_1']));
        $this->assertFalse($this->client->deliverTags(['cat_p_1']));
        $this->assertStringContainsString('edge-2: purge_tags: HTTP 401', (string) $this->client->lastFailure());
        $this->assertStringNotContainsString('edge-1', (string) $this->client->lastFailure());
    }

    public function testAFullClearGoesToEveryInstance(): void
    {
        $this->answers['edge-1'] = [200, '{"cleared":true,"entries_removed":3,"bytes_freed":10}'];
        $this->answers['edge-2'] = [200, '{"cleared":true,"entries_removed":4,"bytes_freed":20}'];

        $result = $this->client->purgeAll();

        $this->assertSame(['http://edge-1:9301/admin/cache/clear', 'http://edge-2:9301/admin/cache/clear'], array_column($this->requests, 1));
        $this->assertSame(7, $result['entries_removed']);
    }

    public function testAdminPurgesFanOutToo(): void
    {
        $this->client->purgeUrl('/p.html', 'shop.example');
        $this->client->purgeHost('shop.example');
        $this->client->purgeVary('X-Magento-Vary', 'abc');
        $this->client->purgeTagPattern('cat_p_*');
        $this->client->purgePattern('/catalog/*');

        $hosts = array_map(fn (array $r): string => (string) parse_url($r[1], PHP_URL_HOST), $this->requests);
        $this->assertSame(array_fill(0, 5, ['edge-1', 'edge-2']), array_chunk($hosts, 2));
    }

    public function testReadsGoToTheFirstInstanceOnly(): void
    {
        $this->answers['edge-1'] = [200, '{"entries":1}'];

        $this->client->getStats();
        $this->client->getWarmerStatus();

        $this->assertSame(['edge-1', 'edge-1'], array_map(
            fn (array $r): string => (string) parse_url($r[1], PHP_URL_HOST),
            $this->requests
        ));
    }

    public function testABoundClientTalksToItsInstanceOnly(): void
    {
        $edge2 = $this->client->forInstance(new Instance('edge-2', 'http://edge-2:9301', 'token-2'));

        $this->assertTrue($edge2->deliverTags(['cat_p_1']));
        $this->assertSame([['POST', 'http://edge-2:9301/admin/purge/tags', 'Bearer token-2']], $this->requests);
    }
}
