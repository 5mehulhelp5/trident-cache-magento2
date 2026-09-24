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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    public const TRIDENT = 3;

    public const XML_CACHING_APPLICATION = 'system/full_page_cache/caching_application';
    public const XML_TRIDENT_ENABLED = 'system/full_page_cache/trident/enabled';
    public const XML_TRIDENT_API_URL = 'system/full_page_cache/trident/api_url';
    public const XML_TRIDENT_API_TOKEN = 'system/full_page_cache/trident/api_token';
    public const XML_TRIDENT_SOFT_PURGE = 'system/full_page_cache/trident/soft_purge';
    public const XML_TRIDENT_DEBUG = 'system/full_page_cache/trident/debug';
    public const XML_TRIDENT_TTL = 'system/full_page_cache/trident/ttl';
    public const XML_TRIDENT_GRACE_PERIOD = 'system/full_page_cache/trident/grace_period';
    public const XML_TRIDENT_TTL_STATIC = 'system/full_page_cache/trident/ttl_static';
    public const XML_TRIDENT_ESI_ENABLED = 'system/full_page_cache/trident/esi_enabled';
    public const XML_TRIDENT_ESI_MAX_DEPTH = 'system/full_page_cache/trident/esi_max_depth';
    public const XML_TRIDENT_INSTANCES = 'system/full_page_cache/trident/instances';

    /** The name of the single instance configured in the admin. */
    public const DEFAULT_INSTANCE = 'default';

    /** Instance names are stored with each pending purge. */
    public const MAX_INSTANCE_NAME = 64;

    /** @var array{0: array<int, Instance>, 1: array<int, string>}|null */
    private ?array $instances = null;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isTridentEnabled(): bool
    {
        return (int) $this->scopeConfig->getValue(self::XML_CACHING_APPLICATION) === self::TRIDENT;
    }

    public function getApiUrl(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_TRIDENT_API_URL) ?: 'http://trident:9301';
    }

    public function getApiToken(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_TRIDENT_API_TOKEN);

        return $value !== '' ? $this->encryptor->decrypt($value) : '';
    }

    /**
     * X03: every Trident edge this store invalidates.
     *
     * Configured in app/etc/env.php, which Magento layers over the values
     * saved in the admin — so env.php takes precedence, and anything an
     * instance leaves out (today: its token) is taken from the admin setting.
     * An `api_token` given here is plain text — env.php is already the file
     * that holds the store's secrets:
     *
     *     'system' => ['default' => ['system' => ['full_page_cache' => ['trident' => [
     *         'instances' => [
     *             'edge-1' => ['api_url' => 'http://10.0.0.11:9301'],
     *             'edge-2' => ['api_url' => 'http://10.0.0.12:9301', 'api_token' => '...'],
     *         ],
     *     ]]]]],
     *
     * After changing the list, run `bin/magento app:config:import` — as after
     * any change to the `system` section of env.php. Until then Magento
     * answers every storefront request with a 500 ("The configuration file
     * has changed").
     *
     * Without `instances`, the admin's single API URL and token are the one
     * instance, exactly as before. Entries without an `api_url` are skipped
     * and reported by {@see getInstanceErrors()} (and `trident:purge:status`);
     * if none is usable, the admin setting is used rather than nothing.
     *
     * @return array<int, Instance> Never empty; the first is the dashboard's.
     */
    public function getInstances(): array
    {
        return $this->readInstances()[0];
    }

    /**
     * X03: why configured instances were skipped.
     *
     * @return array<int, string>
     */
    public function getInstanceErrors(): array
    {
        return $this->readInstances()[1];
    }

    /**
     * @return array{0: array<int, Instance>, 1: array<int, string>}
     */
    private function readInstances(): array
    {
        // Read once per process: the client asks several times per request.
        // A change needs app:config:import anyway, and cron runs a new process.
        return $this->instances ??= $this->parseInstances();
    }

    /**
     * @return array{0: array<int, Instance>, 1: array<int, string>}
     */
    private function parseInstances(): array
    {
        $configured = $this->scopeConfig->getValue(self::XML_TRIDENT_INSTANCES);
        $fallback = fn (): array => [new Instance(self::DEFAULT_INSTANCE, $this->getApiUrl(), $this->getApiToken())];
        if (!is_array($configured) || $configured === []) {
            return [$fallback(), []];
        }
        $adminToken = null;

        $instances = [];
        $errors = [];
        $position = 0;
        foreach ($configured as $key => $entry) {
            $position++;
            $name = is_string($key) && $key !== '' ? $key : 'instance-' . $position;
            if (!is_array($entry)) {
                $errors[] = sprintf('%s: expected an array with api_url', $name);
                continue;
            }
            $url = trim((string) ($entry['api_url'] ?? ''));
            if ($url === '') {
                $errors[] = sprintf('%s: no api_url', $name);
                continue;
            }
            if (strlen($name) > self::MAX_INSTANCE_NAME) {
                $errors[] = sprintf('%s: name longer than %d characters', $name, self::MAX_INSTANCE_NAME);
                continue;
            }
            $token = isset($entry['api_token']) && (string) $entry['api_token'] !== ''
                ? (string) $entry['api_token']
                : ($adminToken ??= $this->getApiToken());
            $instances[] = new Instance($name, $url, $token);
        }

        return [$instances !== [] ? $instances : $fallback(), $errors];
    }

    public function isSoftPurgeEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_TRIDENT_SOFT_PURGE);
    }

    public function isDebugEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_TRIDENT_DEBUG);
    }

    public function getTtl(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_TRIDENT_TTL) ?: 86400);
    }

    public function getGracePeriod(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_TRIDENT_GRACE_PERIOD) ?: 86400);
    }

    public function getStaticTtl(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_TRIDENT_TTL_STATIC) ?: 2592000);
    }

    public function isEsiEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_TRIDENT_ESI_ENABLED);
    }

    public function getEsiMaxDepth(): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_TRIDENT_ESI_MAX_DEPTH) ?: 3);
    }
}
