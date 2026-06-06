<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Qoliber\TridentCache\Model\Config;
use Qoliber\TridentCache\Model\TridentClient;

class Bans implements ArgumentInterface
{
    public function __construct(
        private readonly TridentClient $tridentClient,
        private readonly Config $config
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->tridentClient->isEnabled();
    }

    public function isTridentConfigured(): bool
    {
        return $this->config->isTridentEnabled();
    }

    public function getApiUrl(): string
    {
        return $this->config->getApiUrl();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBans(): ?array
    {
        return $this->tridentClient->getBans();
    }

    /**
     * Returns the list of bans, guarding against a null/empty payload.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBanList(): array
    {
        $bans = $this->getBans();

        if ($bans === null || empty($bans['bans']) || !is_array($bans['bans'])) {
            return [];
        }

        return array_values($bans['bans']);
    }

    /**
     * A ban is expired when expires_at is set and lies in the past.
     *
     * @param array<string, mixed> $ban
     */
    public function isExpired(array $ban): bool
    {
        $expiresAt = $ban['expires_at'] ?? null;

        if ($expiresAt === null || $expiresAt === '') {
            return false;
        }

        $expiresTs = is_numeric($expiresAt) ? (int)$expiresAt : strtotime((string)$expiresAt);

        if ($expiresTs === false || $expiresTs === 0) {
            return false;
        }

        return $expiresTs < time();
    }

    /**
     * Counts how many of the given bans are still active (not expired).
     *
     * @param array<int, array<string, mixed>> $bans
     */
    public function countActive(array $bans): int
    {
        $active = 0;

        foreach ($bans as $ban) {
            if (!$this->isExpired($ban)) {
                $active++;
            }
        }

        return $active;
    }

    /**
     * Counts how many of the given bans are expired.
     *
     * @param array<int, array<string, mixed>> $bans
     */
    public function countExpired(array $bans): int
    {
        return count($bans) - $this->countActive($bans);
    }
}
