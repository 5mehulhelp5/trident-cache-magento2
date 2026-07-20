<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Model;

use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class TridentClient
{
    /** Total request timeout (seconds) — guards against a wedged Trident admin port. */
    private const REQUEST_TIMEOUT = 10;

    /** Connection-establishment timeout (seconds). */
    private const CONNECT_TIMEOUT = 5;

    public function __construct(
        private readonly Curl $curl,
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    /**
     * Prepare the shared Curl handle for a request: apply an explicit timeout and
     * pin the HTTP verb, so state from a prior call (notably a DELETE's
     * CURLOPT_CUSTOMREQUEST) cannot leak into this one.
     *
     * @param string $method GET|POST
     */
    private function prepareRequest(string $method): void
    {
        $this->curl->setOptions([
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
    }

    /**
     * Surface a non-2xx admin response instead of silently swallowing it. Magento's
     * Curl does not throw on an HTTP error status, so a 401 (bad/expired api_token)
     * or 5xx would otherwise be lost.
     */
    private function logHttpError(string $context): void
    {
        $status = (int) $this->curl->getStatus();
        if ($status >= 400) {
            $this->logger->error('Trident admin request failed', [
                'context' => $context,
                'status' => $status,
            ]);
        }
    }

    public function isEnabled(): bool
    {
        return $this->config->isTridentEnabled() && !empty($this->config->getApiUrl());
    }

    /**
     * Purge cache by tags (soft or hard purge based on config)
     *
     * @param array<string> $tags
     * @return array<string, mixed>|null
     */
    /**
     * @param array<string> $tags
     * @param array<string> $excludeTags
     * @return array<string, mixed>|null
     */
    public function purgeTags(array $tags, array $excludeTags = []): ?array
    {
        if (!$this->isEnabled() || empty($tags)) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
                'Content-Type' => 'application/json',
            ]);

            $data = [
                'tags' => array_values(array_unique($tags)),
                'mode' => $this->config->isSoftPurgeEnabled() ? 'soft' : 'hard',
            ];

            if (!empty($excludeTags)) {
                $data['exclude_tags'] = array_values(array_unique($excludeTags));
            }

            $payload = json_encode($data);

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->post($apiUrl . '/admin/purge/tags', $payload);

            $response = $this->curl->getBody();
            $result = json_decode($response, true);

            if ($this->config->isDebugEnabled()) {
                $this->logger->info('Trident cache purge', [
                    'tags' => $tags,
                    'exclude_tags' => $excludeTags,
                    'mode' => $this->config->isSoftPurgeEnabled() ? 'soft' : 'hard',
                    'result' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Trident cache purge failed', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Purge all cache entries
     *
     * @return array<string, mixed>|null
     */
    public function purgeAll(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
                'Content-Type' => 'application/json',
            ]);

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->post($apiUrl . '/admin/cache/clear', json_encode(['confirm' => true]));

            $response = $this->curl->getBody();
            $result = json_decode($response, true);

            if ($this->config->isDebugEnabled()) {
                $this->logger->info('Trident cache cleared', ['result' => $result]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Trident cache clear failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get cache statistics
     *
     * @return array<string, mixed>|null
     */
    public function getStats(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/stats');

            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->logger->error('Trident get stats failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get cache rules
     *
     * @return array<string, mixed>|null
     */
    public function getRules(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/rules');

            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->logger->error('Trident get rules failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get Trident health status
     *
     * @return array<string, mixed>|null
     */
    public function getHealth(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/health');

            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->logger->error('Trident health check failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Trident 1.5.0 license / degraded-mode status: GET /admin/status.
     *
     * Returns ['status','version','license','mode'] where `mode` is 'licensed'
     * (caching active), 'degraded' (license check failed → pass-through, no
     * caching), or 'unknown'. Distinct from getHealth() (mere reachability).
     *
     * @return array<string, mixed>|null
     */
    public function getStatus(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $this->prepareRequest('GET');

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/status');
            $this->logHttpError('GET /admin/status');

            return json_decode($this->curl->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->error('Trident status check failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Trident 1.5.0 dry-run cacheability diagnostic: POST /admin/explain.
     *
     * Answers "will this request cache, and if not, why?" without mutating the
     * cache. Useful for debugging why a Magento page/URL is not being cached.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>|null
     */
    public function explain(string $method, string $url, array $headers = [], bool $detail = true): ?array
    {
        return $this->apiPost('/admin/explain', [
            'method' => $method,
            'url' => $url,
            // Cast so an empty header set serialises as {} (object), not [] —
            // Trident's ExplainRequest.headers is a map.
            'headers' => (object) $headers,
            'detail' => $detail,
        ]);
    }

    /**
     * Get cache entries with optional filtering
     *
     * @param int $offset
     * @param int $limit
     * @param string|null $tag
     * @param string $sort
     * @return array<string, mixed>|null
     */
    public function getEntries(int $offset = 0, int $limit = 50, ?string $tag = null, string $sort = 'age'): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $params = ['offset' => $offset, 'limit' => $limit, 'sort' => $sort];
            if ($tag !== null && $tag !== '') {
                $params['tag'] = $tag;
            }

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/cache/entries?' . http_build_query($params));

            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->logger->error('Trident get entries failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get cache tags with optional prefix filtering
     *
     * @param int $offset
     * @param int $limit
     * @param string|null $prefix
     * @param string $sort
     * @return array<string, mixed>|null
     */
    public function getTags(int $offset = 0, int $limit = 100, ?string $prefix = null, string $sort = 'count'): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $params = ['offset' => $offset, 'limit' => $limit, 'sort' => $sort];
            if ($prefix !== null && $prefix !== '') {
                $params['prefix'] = $prefix;
            }

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/cache/tags?' . http_build_query($params));

            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->logger->error('Trident get tags failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get top URLs by request count
     *
     * @param int $limit
     * @param string $sort
     * @return array<string, mixed>|null
     */
    public function getTopUrls(int $limit = 20, string $sort = 'requests'): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
            ]);

            $params = ['limit' => $limit, 'sort' => $sort];

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->get($apiUrl . '/admin/stats/top?' . http_build_query($params));

            $response = $this->curl->getBody();
            return json_decode($response, true);
        } catch (\Exception $e) {
            $this->logger->error('Trident get top urls failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Purge a single URL from cache
     *
     * @param string $url
     * @param string|null $host
     * @return array<string, mixed>|null
     */
    public function purgeUrl(string $url, ?string $host = null): ?array
    {
        if (!$this->isEnabled() || empty($url)) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
                'Content-Type' => 'application/json',
            ]);

            $data = [
                'url' => $url,
                'mode' => $this->config->isSoftPurgeEnabled() ? 'soft' : 'hard',
            ];

            if ($host !== null && $host !== '') {
                $data['host'] = $host;
            }

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->post($apiUrl . '/admin/purge/url', json_encode($data));

            $response = $this->curl->getBody();
            $result = json_decode($response, true);

            if ($this->config->isDebugEnabled()) {
                $this->logger->info('Trident cache purge url', [
                    'url' => $url,
                    'host' => $host,
                    'result' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Trident cache purge url failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Purge cache by URL pattern
     *
     * @param string $pattern
     * @return array<string, mixed>|null
     */
    public function purgePattern(string $pattern): ?array
    {
        if (!$this->isEnabled() || empty($pattern)) {
            return null;
        }

        try {
            $this->curl->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getApiToken(),
                'Content-Type' => 'application/json',
            ]);

            $payload = json_encode([
                'pattern' => $pattern,
                'mode' => $this->config->isSoftPurgeEnabled() ? 'soft' : 'hard',
            ]);

            $apiUrl = rtrim($this->config->getApiUrl(), '/');
            $this->curl->post($apiUrl . '/admin/purge/urls', $payload);

            $response = $this->curl->getBody();
            $result = json_decode($response, true);

            if ($this->config->isDebugEnabled()) {
                $this->logger->info('Trident cache purge by pattern', [
                    'pattern' => $pattern,
                    'result' => $result,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Trident cache purge by pattern failed', [
                'pattern' => $pattern,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =========================================================================
    // Generic request helpers (used by the feature methods below)
    // =========================================================================

    /**
     * @return array<string, string>
     */
    private function authHeaders(bool $json = false): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->config->getApiToken()];
        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    /**
     * Perform an authenticated GET and decode the JSON body.
     *
     * @return array<string, mixed>|null
     */
    private function apiGet(string $path): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders($this->authHeaders());
            $this->curl->get(rtrim($this->config->getApiUrl(), '/') . $path);

            return json_decode($this->curl->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->error('Trident GET failed', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Perform an authenticated POST with a JSON body and decode the response.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function apiPost(string $path, array $data = []): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders($this->authHeaders(true));
            $this->prepareRequest('POST');
            $this->curl->post(rtrim($this->config->getApiUrl(), '/') . $path, json_encode($data));
            $this->logHttpError('POST ' . $path);
            $result = json_decode($this->curl->getBody(), true);

            if ($this->config->isDebugEnabled()) {
                $this->logger->info('Trident POST', ['path' => $path, 'data' => $data, 'result' => $result]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Trident POST failed', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Perform an authenticated DELETE and decode the JSON body.
     *
     * @return array<string, mixed>|null
     */
    private function apiDelete(string $path): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $this->curl->setHeaders($this->authHeaders());
            $this->curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
            $this->curl->get(rtrim($this->config->getApiUrl(), '/') . $path);

            return json_decode($this->curl->getBody(), true);
        } catch (\Exception $e) {
            $this->logger->error('Trident DELETE failed', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================================
    // Cache Warmer
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getWarmerStatus(): ?array
    {
        return $this->apiGet('/admin/warmer/status');
    }

    /**
     * @param array<int, string> $urls Optional explicit URL list; empty = warm configured sources.
     * @return array<string, mixed>|null
     */
    public function warmerRun(array $urls = []): ?array
    {
        $data = [];
        if (!empty($urls)) {
            $data['urls'] = array_values($urls);
        }

        return $this->apiPost('/admin/warmer/run', $data);
    }

    /**
     * @param array<int, string> $urls
     * @return array<string, mixed>|null
     */
    public function warmerQueue(array $urls): ?array
    {
        return $this->apiPost('/admin/warmer/queue', ['urls' => array_values($urls)]);
    }

    /** @return array<string, mixed>|null */
    public function warmerCancel(): ?array
    {
        return $this->apiPost('/admin/warmer/cancel');
    }

    // =========================================================================
    // Cache Coverage
    // =========================================================================

    /**
     * Batch cache-membership check: per-URL cached/not + aggregate percentage.
     *
     * @param array<int, string> $urls
     * @return array<string, mixed>|null
     */
    public function cacheCoverage(
        array $urls,
        ?string $host = null,
        string $scheme = 'https',
        string $method = 'GET'
    ): ?array {
        $payload = [
            'urls' => array_values($urls),
            'scheme' => $scheme,
            'method' => $method,
        ];

        if ($host !== null && $host !== '') {
            $payload['host'] = $host;
        }

        return $this->apiPost('/admin/cache/coverage', $payload);
    }

    // =========================================================================
    // Launch Mode
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getLaunchStatus(): ?array
    {
        return $this->apiGet('/admin/launch/status');
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function launchStart(array $options = []): ?array
    {
        return $this->apiPost('/admin/launch/start', $options);
    }

    /** @return array<string, mixed>|null */
    public function launchComplete(): ?array
    {
        return $this->apiPost('/admin/launch/complete');
    }

    /** @return array<string, mixed>|null */
    public function launchAbort(?string $reason = null): ?array
    {
        $data = [];
        if ($reason !== null && $reason !== '') {
            $data['reason'] = $reason;
        }

        return $this->apiPost('/admin/launch/abort', $data);
    }

    // =========================================================================
    // Reflect Mode
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getReflectStatus(): ?array
    {
        return $this->apiGet('/admin/reflect/status');
    }

    /** @return array<string, mixed>|null */
    public function getReflectQueue(): ?array
    {
        return $this->apiGet('/admin/reflect/queue');
    }

    /**
     * @param string $level full|selective|ttl_extension
     * @return array<string, mixed>|null
     */
    public function reflectEnable(string $level = 'full', ?string $duration = null, ?string $reason = null): ?array
    {
        $data = ['level' => $level];
        if ($duration !== null && $duration !== '') {
            $data['duration'] = $duration;
        }
        if ($reason !== null && $reason !== '') {
            $data['reason'] = $reason;
        }

        return $this->apiPost('/admin/reflect/enable', $data);
    }

    /**
     * @param string $mode replay|hard
     * @return array<string, mixed>|null
     */
    public function reflectDisable(string $mode = 'replay'): ?array
    {
        return $this->apiPost('/admin/reflect/disable', ['mode' => $mode]);
    }

    // =========================================================================
    // Denoisers
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getDenoiserReport(): ?array
    {
        return $this->apiGet('/admin/denoisers/report');
    }

    /** @return array<string, mixed>|null */
    public function getDenoiserQueryScopes(): ?array
    {
        return $this->apiGet('/admin/denoisers/query/scopes');
    }

    /** @return array<string, mixed>|null */
    public function getDenoiserPathZones(): ?array
    {
        return $this->apiGet('/admin/denoisers/path/zones');
    }

    /** @return array<string, mixed>|null */
    public function denoiserQueryPin(string $param, ?string $pathPrefix = null): ?array
    {
        $data = ['param' => $param];
        if ($pathPrefix !== null && $pathPrefix !== '') {
            $data['path_prefix'] = $pathPrefix;
        }

        return $this->apiPost('/admin/denoisers/query/pin', $data);
    }

    /** @return array<string, mixed>|null */
    public function denoiserQueryUnpin(string $param, ?string $pathPrefix = null): ?array
    {
        $data = ['param' => $param];
        if ($pathPrefix !== null && $pathPrefix !== '') {
            $data['path_prefix'] = $pathPrefix;
        }

        return $this->apiPost('/admin/denoisers/query/unpin', $data);
    }

    /** @return array<string, mixed>|null */
    public function denoiserQueryReset(): ?array
    {
        return $this->apiPost('/admin/denoisers/query/reset');
    }

    /** @return array<string, mixed>|null */
    public function denoiserPathPin(string $host, string $pathPrefix): ?array
    {
        return $this->apiPost('/admin/denoisers/path/pin', ['host' => $host, 'path_prefix' => $pathPrefix]);
    }

    /** @return array<string, mixed>|null */
    public function denoiserPathUnpin(string $host, string $pathPrefix): ?array
    {
        return $this->apiPost('/admin/denoisers/path/unpin', ['host' => $host, 'path_prefix' => $pathPrefix]);
    }

    /** @return array<string, mixed>|null */
    public function denoiserPathReset(): ?array
    {
        return $this->apiPost('/admin/denoisers/path/reset', []);
    }

    /** @return array<string, mixed>|null */
    public function getDenoiserWafExport(): ?array
    {
        return $this->apiGet('/admin/denoisers/export/waf');
    }

    // =========================================================================
    // Bans (soft purge)
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getBans(): ?array
    {
        return $this->apiGet('/admin/bans');
    }

    /**
     * @param string $type url|tag|pattern|bulkurl|tags (Trident BanType enum)
     * @return array<string, mixed>|null
     */
    public function createBan(string $pattern, string $type = 'url'): ?array
    {
        // The /admin/bans request body field is `type` (serde rename of ban_type).
        return $this->apiPost('/admin/bans', ['pattern' => $pattern, 'type' => $type]);
    }

    /** @return array<string, mixed>|null */
    public function deleteBan(string $id): ?array
    {
        return $this->apiDelete('/admin/bans/' . rawurlencode($id));
    }

    // =========================================================================
    // Backends + connection pools
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getBackends(): ?array
    {
        return $this->apiGet('/admin/backends');
    }

    /** @return array<string, mixed>|null */
    public function getBackendDetail(string $name): ?array
    {
        return $this->apiGet('/admin/backends/detail?' . http_build_query(['name' => $name]));
    }

    /** @return array<string, mixed>|null */
    public function getConnections(): ?array
    {
        return $this->apiGet('/admin/connections');
    }

    /** @return array<string, mixed>|null */
    public function drainBackend(string $name): ?array
    {
        return $this->apiPost('/admin/backends/drain', ['name' => $name]);
    }

    /** @return array<string, mixed>|null */
    public function restoreBackend(string $name): ?array
    {
        return $this->apiPost('/admin/backends/restore', ['name' => $name]);
    }

    // =========================================================================
    // DNS discovery
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getDiscovery(): ?array
    {
        return $this->apiGet('/admin/discovery');
    }

    /** @return array<string, mixed>|null */
    public function getDiscoveryDetail(string $name): ?array
    {
        return $this->apiGet('/admin/discovery/detail?' . http_build_query(['name' => $name]));
    }

    /**
     * Force a DNS re-resolve for a single discovery target. Trident's
     * /admin/discovery/refresh requires the backend `name` as a query param.
     *
     * @return array<string, mixed>|null
     */
    public function refreshDiscovery(string $name): ?array
    {
        return $this->apiPost('/admin/discovery/refresh?' . http_build_query(['name' => $name]));
    }

    // =========================================================================
    // Extended statistics + memory
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function getLatencyStats(): ?array
    {
        return $this->apiGet('/admin/stats/latency');
    }

    /** @return array<string, mixed>|null */
    public function getErrorStats(int $limit = 20): ?array
    {
        return $this->apiGet('/admin/stats/errors?' . http_build_query(['limit' => $limit]));
    }

    /** @return array<string, mixed>|null */
    public function getProtectionStats(): ?array
    {
        return $this->apiGet('/admin/stats/protection');
    }

    /** @return array<string, mixed>|null */
    public function getMemory(): ?array
    {
        return $this->apiGet('/admin/memory');
    }

    /** @return array<string, mixed>|null */
    public function getRefreshQueue(): ?array
    {
        return $this->apiGet('/admin/refresh/queue');
    }

    // =========================================================================
    // Extended purge (host / vary)
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function purgeHost(string $host): ?array
    {
        return $this->apiPost('/admin/purge/host', [
            'host' => $host,
            'mode' => $this->config->isSoftPurgeEnabled() ? 'soft' : 'hard',
        ]);
    }

    /** @return array<string, mixed>|null */
    public function purgeVary(string $header, string $value): ?array
    {
        return $this->apiPost('/admin/purge/vary', [
            'header' => $header,
            'value' => $value,
            'mode' => $this->config->isSoftPurgeEnabled() ? 'soft' : 'hard',
        ]);
    }

    /**
     * Trident 1.5.0 tag-pattern purge: POST /admin/purge/tag/pattern.
     *
     * Purge every entry whose tag matches a wildcard (default) or regex pattern
     * — e.g. `catalog_product_*` or `cat_c_*`. Distinct from purgePattern(),
     * which matches URL globs via /admin/purge/urls.
     *
     * @return array<string, mixed>|null
     */
    public function purgeTagPattern(string $pattern, bool $regex = false): ?array
    {
        return $this->apiPost('/admin/purge/tag/pattern', [
            'pattern' => $pattern,
            'pattern_type' => $regex ? 'regex' : 'wildcard',
            'mode' => $this->config->isSoftPurgeEnabled() ? 'soft' : 'hard',
        ]);
    }
}
