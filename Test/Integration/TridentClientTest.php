<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration;

use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\TridentClient;

class TridentClientTest extends TestCase
{
    private TridentClient $client;
    private Curl&MockObject $curlMock;
    private Config&MockObject $configMock;
    private LoggerInterface&MockObject $loggerMock;

    protected function setUp(): void
    {
        $this->curlMock = $this->createMock(Curl::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->configMock = $this->createMock(Config::class);

        $this->client = new TridentClient(
            $this->curlMock,
            $this->loggerMock,
            $this->configMock
        );
    }

    public function testPurgeTagsSendsCorrectRequest(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isSoftPurgeEnabled')->willReturn(true);
        $this->configMock->method('isDebugEnabled')->willReturn(false);

        $this->curlMock->expects($this->once())
            ->method('setHeaders')
            ->with([
                'Authorization' => 'Bearer test-token',
                'Content-Type' => 'application/json',
            ]);

        $expectedPayload = json_encode([
            'tags' => ['cat_p_1', 'cat_p_2'],
            'mode' => 'soft',
        ]);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with('http://127.0.0.1:6085/admin/purge/tags', $expectedPayload);

        $this->curlMock->method('getBody')
            ->willReturn('{"purged": 5}');

        $result = $this->client->purgeTags(['cat_p_1', 'cat_p_2']);

        $this->assertIsArray($result);
        $this->assertEquals(5, $result['purged']);
    }

    public function testPurgeTagsWithExcludeTags(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isSoftPurgeEnabled')->willReturn(false);
        $this->configMock->method('isDebugEnabled')->willReturn(false);

        $expectedPayload = json_encode([
            'tags' => ['cat_p_1'],
            'mode' => 'hard',
            'exclude_tags' => ['cat_c_1'],
        ]);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with('http://127.0.0.1:6085/admin/purge/tags', $expectedPayload);

        $this->curlMock->method('getBody')->willReturn('{"purged": 1}');

        $result = $this->client->purgeTags(['cat_p_1'], ['cat_c_1']);

        $this->assertIsArray($result);
    }

    public function testPurgeAllSendsCorrectRequest(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isDebugEnabled')->willReturn(false);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with(
                'http://127.0.0.1:6085/admin/cache/clear',
                json_encode(['confirm' => true])
            );

        $this->curlMock->method('getBody')->willReturn('{"cleared": true}');

        $result = $this->client->purgeAll();

        $this->assertIsArray($result);
        $this->assertTrue($result['cleared']);
    }

    public function testPurgePatternSendsCorrectRequest(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isSoftPurgeEnabled')->willReturn(true);
        $this->configMock->method('isDebugEnabled')->willReturn(false);

        $expectedPayload = json_encode([
            'pattern' => '/catalog/product/*',
            'mode' => 'soft',
        ]);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with('http://127.0.0.1:6085/admin/purge/urls', $expectedPayload);

        $this->curlMock->method('getBody')->willReturn('{"purged": 3}');

        $result = $this->client->purgePattern('/catalog/product/*');

        $this->assertIsArray($result);
    }

    public function testGetHealthSendsCorrectRequest(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');

        $this->curlMock->expects($this->once())
            ->method('get')
            ->with('http://127.0.0.1:6085/admin/health');

        $this->curlMock->method('getBody')
            ->willReturn('{"status": "ok", "version": "0.1.0"}');

        $result = $this->client->getHealth();

        $this->assertIsArray($result);
        $this->assertEquals('ok', $result['status']);
        $this->assertEquals('0.1.0', $result['version']);
    }

    public function testDisabledClientMakesNoHttpCalls(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(false);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');

        $this->curlMock->expects($this->never())->method('post');
        $this->curlMock->expects($this->never())->method('get');

        $this->assertNull($this->client->purgeTags(['cat_p_1']));
        $this->assertNull($this->client->purgeAll());
        $this->assertNull($this->client->purgePattern('/test'));
        $this->assertNull($this->client->getStats());
        $this->assertNull($this->client->getRules());
        $this->assertNull($this->client->getHealth());
    }

    public function testPurgeTagsReturnsNullOnEmptyTags(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');

        $this->curlMock->expects($this->never())->method('post');

        $this->assertNull($this->client->purgeTags([]));
    }

    public function testPurgeTagsReturnsNullOnException(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isSoftPurgeEnabled')->willReturn(true);
        $this->configMock->method('isDebugEnabled')->willReturn(false);

        $this->curlMock->method('post')
            ->willThrowException(new \Exception('Connection refused'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Trident cache purge failed', $this->anything());

        $result = $this->client->purgeTags(['cat_p_1']);

        $this->assertNull($result);
    }

    public function testPurgeTagsDeduplicatesTags(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isSoftPurgeEnabled')->willReturn(true);
        $this->configMock->method('isDebugEnabled')->willReturn(false);

        $expectedPayload = json_encode([
            'tags' => ['cat_p_1', 'cat_p_2'],
            'mode' => 'soft',
        ]);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with('http://127.0.0.1:6085/admin/purge/tags', $expectedPayload);

        $this->curlMock->method('getBody')->willReturn('{}');

        $this->client->purgeTags(['cat_p_1', 'cat_p_2', 'cat_p_1']);
    }

    // =====================================================================
    // Trident 1.5.0 additions: getStatus() / explain() / purgeTagPattern()
    //
    // These guard the client-quality issues flagged in the module review:
    //   * an explicit HTTP timeout is set (no hang on a wedged admin port);
    //   * a non-2xx response (esp. 401) is surfaced, not silently swallowed;
    //   * a prior DELETE's CURLOPT_CUSTOMREQUEST cannot leak into a later
    //     POST/GET on the shared Curl handle.
    // =====================================================================

    /** Configure the Config mock as an enabled Trident instance. */
    private function enableTrident(bool $soft = false, bool $debug = false): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');
        $this->configMock->method('getApiToken')->willReturn('test-token');
        $this->configMock->method('isSoftPurgeEnabled')->willReturn($soft);
        $this->configMock->method('isDebugEnabled')->willReturn($debug);
    }

    // --- getStatus() --------------------------------------------------------

    public function testGetStatusSendsCorrectRequest(): void
    {
        $this->enableTrident();

        $this->curlMock->expects($this->once())
            ->method('setHeaders')
            ->with(['Authorization' => 'Bearer test-token']);

        $this->curlMock->expects($this->once())
            ->method('get')
            ->with('http://127.0.0.1:6085/admin/status');

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')
            ->willReturn('{"status":"ok","version":"1.5.0","license":"valid","mode":"licensed"}');

        $result = $this->client->getStatus();

        $this->assertIsArray($result);
        $this->assertSame('licensed', $result['mode']);
        $this->assertSame('1.5.0', $result['version']);
    }

    public function testGetStatusTrimsTrailingSlashFromBaseUrl(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(true);
        $this->configMock->method('getApiUrl')->willReturn('http://trident:9301/');
        $this->configMock->method('getApiToken')->willReturn('t');

        $this->curlMock->expects($this->once())
            ->method('get')
            ->with('http://trident:9301/admin/status');

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{}');

        $this->client->getStatus();
    }

    public function testGetStatusSetsHttpTimeout(): void
    {
        $this->enableTrident();

        $this->curlMock->expects($this->once())
            ->method('setOptions')
            ->with($this->callback(static function (array $opts): bool {
                return array_key_exists(CURLOPT_TIMEOUT, $opts)
                    && $opts[CURLOPT_TIMEOUT] > 0
                    && array_key_exists(CURLOPT_CONNECTTIMEOUT, $opts)
                    && $opts[CURLOPT_CONNECTTIMEOUT] > 0;
            }));

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{}');

        $this->client->getStatus();
    }

    public function testGetStatusSurfacesUnauthorized(): void
    {
        $this->enableTrident();

        // Magento's Curl does NOT throw on an HTTP error status, so a 401 from a
        // bad/expired api_token used to be lost. It must be logged, not swallowed.
        $this->curlMock->method('getStatus')->willReturn(401);
        $this->curlMock->method('getBody')->willReturn('{"error":"unauthorized"}');

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $ctx): bool => ($ctx['status'] ?? null) === 401)
            );

        $this->client->getStatus();
    }

    // --- explain() ----------------------------------------------------------

    public function testExplainSendsCorrectRequest(): void
    {
        $this->enableTrident();

        $this->curlMock->expects($this->once())
            ->method('setHeaders')
            ->with([
                'Authorization' => 'Bearer test-token',
                'Content-Type' => 'application/json',
            ]);

        $expectedPayload = json_encode([
            'method' => 'GET',
            'url' => 'https://example.com/p.html',
            'headers' => (object) ['Cookie' => 'x=1'],
            'detail' => true,
        ]);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with('http://127.0.0.1:6085/admin/explain', $expectedPayload);

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{"cacheable":false,"reason":"cookie"}');

        $result = $this->client->explain('GET', 'https://example.com/p.html', ['Cookie' => 'x=1']);

        $this->assertIsArray($result);
        $this->assertFalse($result['cacheable']);
    }

    public function testExplainSerialisesEmptyHeadersAsJsonObject(): void
    {
        $this->enableTrident();

        // Trident's ExplainRequest.headers is a map; an empty PHP array would
        // serialise as [] and be rejected — it must be {}.
        $this->curlMock->expects($this->once())
            ->method('post')
            ->with(
                'http://127.0.0.1:6085/admin/explain',
                $this->callback(static fn (string $p): bool => str_contains($p, '"headers":{}'))
            );

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{}');

        $this->client->explain('GET', 'https://example.com/');
    }

    public function testExplainSurfacesServerError(): void
    {
        $this->enableTrident();

        $this->curlMock->method('getStatus')->willReturn(503);
        $this->curlMock->method('getBody')->willReturn('');

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $ctx): bool => ($ctx['status'] ?? null) === 503)
            );

        $this->client->explain('GET', 'https://example.com/');
    }

    // --- purgeTagPattern() --------------------------------------------------

    public function testPurgeTagPatternWildcardHardMode(): void
    {
        $this->enableTrident(false);

        $expectedPayload = json_encode([
            'pattern' => 'catalog_product_*',
            'pattern_type' => 'wildcard',
            'mode' => 'hard',
        ]);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with('http://127.0.0.1:6085/admin/purge/tag/pattern', $expectedPayload);

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{"purged":12}');

        $result = $this->client->purgeTagPattern('catalog_product_*');

        $this->assertIsArray($result);
        $this->assertSame(12, $result['purged']);
    }

    public function testPurgeTagPatternRegexSoftMode(): void
    {
        $this->enableTrident(true);

        $expectedPayload = json_encode([
            'pattern' => '^cat_c_\d+$',
            'pattern_type' => 'regex',
            'mode' => 'soft',
        ]);

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with('http://127.0.0.1:6085/admin/purge/tag/pattern', $expectedPayload);

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{}');

        $this->client->purgeTagPattern('^cat_c_\d+$', true);
    }

    public function testPurgeTagPatternSetsHttpTimeout(): void
    {
        $this->enableTrident();

        $this->curlMock->expects($this->once())
            ->method('setOptions')
            ->with($this->callback(static function (array $opts): bool {
                return ($opts[CURLOPT_TIMEOUT] ?? 0) > 0
                    && ($opts[CURLOPT_CONNECTTIMEOUT] ?? 0) > 0;
            }));

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{}');

        $this->client->purgeTagPattern('cat_*');
    }

    public function testPurgeTagPatternPinsPostVerb(): void
    {
        $this->enableTrident();

        // The POST must explicitly pin its verb so a leaked CURLOPT_CUSTOMREQUEST
        // (e.g. DELETE from a prior deleteBan()) cannot turn this into a DELETE.
        $this->curlMock->expects($this->once())
            ->method('setOptions')
            ->with($this->callback(
                static fn (array $opts): bool => ($opts[CURLOPT_CUSTOMREQUEST] ?? null) === 'POST'
            ));

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{}');

        $this->client->purgeTagPattern('cat_*');
    }

    // --- shared-state leakage (real DELETE -> POST sequence) ---------------

    public function testDeleteThenPurgeTagPatternDoesNotLeakVerb(): void
    {
        $this->enableTrident();

        // A stateful fake Curl that records the effective CURLOPT_CUSTOMREQUEST,
        // exactly the shared-handle state that leaks in production.
        $curl = new class extends Curl {
            /** @var array<int,mixed> */
            public array $options = [];
            public int $status = 200;

            public function setHeaders(array $headers): void
            {
            }

            public function setOption($option, $value): void
            {
                $this->options[$option] = $value;
            }

            public function setOptions(array $arr): void
            {
                $this->options = array_replace($this->options, $arr);
            }

            public function get(string $uri): void
            {
            }

            public function post(string $uri, $params): void
            {
            }

            public function getBody(): string
            {
                return '{}';
            }

            public function getStatus(): int
            {
                return $this->status;
            }
        };

        $client = new TridentClient($curl, $this->loggerMock, $this->configMock);

        // 1) deleteBan() sets CURLOPT_CUSTOMREQUEST = DELETE on the shared handle.
        $client->deleteBan('abc');
        $this->assertSame('DELETE', $curl->options[CURLOPT_CUSTOMREQUEST]);

        // 2) A subsequent tag-pattern purge (a POST) must NOT still be a DELETE.
        $client->purgeTagPattern('cat_*');
        $this->assertSame(
            'POST',
            $curl->options[CURLOPT_CUSTOMREQUEST],
            'purgeTagPattern leaked the prior DELETE verb'
        );
    }

    // --- disabled short-circuit --------------------------------------------

    public function testDisabledClientSkipsNewMethods(): void
    {
        $this->configMock->method('isTridentEnabled')->willReturn(false);
        $this->configMock->method('getApiUrl')->willReturn('http://127.0.0.1:6085');

        $this->curlMock->expects($this->never())->method('get');
        $this->curlMock->expects($this->never())->method('post');
        $this->curlMock->expects($this->never())->method('setOptions');

        $this->assertNull($this->client->getStatus());
        $this->assertNull($this->client->explain('GET', 'https://example.com/'));
        $this->assertNull($this->client->purgeTagPattern('cat_*'));
    }
}
