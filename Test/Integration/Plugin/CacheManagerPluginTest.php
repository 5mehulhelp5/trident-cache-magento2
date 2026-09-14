<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Plugin;

use Magento\Framework\App\Cache\Manager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Qoliber\TridentCache\Model\TridentClient;
use Qoliber\TridentCache\Plugin\CacheManagerPlugin;

/**
 * `bin/magento cache:flush` reaches the cache backend directly, so neither the
 * cache-type plugin nor the adminhtml events fire. Before this plugin the edge
 * kept serving pages built from templates a deploy had just replaced.
 */
class CacheManagerPluginTest extends TestCase
{
    private TridentClient&MockObject $client;
    private PurgeAfterCommit&MockObject $purge;
    private Manager&MockObject $manager;
    private CacheManagerPlugin $plugin;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TridentClient::class);
        $this->purge = $this->createMock(PurgeAfterCommit::class);
        $this->manager = $this->createMock(Manager::class);
        $this->plugin = new CacheManagerPlugin($this->client, $this->purge);
    }

    public function testFlushPurgesEverything(): void
    {
        $this->client->method('isEnabled')->willReturn(true);
        $this->purge->expects($this->once())->method('purgeAll');

        $this->plugin->afterFlush($this->manager, null, ['config', 'full_page']);
    }

    public function testCleaningThePageCachePurgesEverything(): void
    {
        $this->client->method('isEnabled')->willReturn(true);
        $this->purge->expects($this->once())->method('purgeAll');

        $this->plugin->afterClean($this->manager, null, ['full_page']);
    }

    /**
     * A routine `cache:clean config` must not cold the edge — the stored pages
     * are still valid until something actually invalidates them.
     */
    public function testCleaningOtherTypesPurgesNothing(): void
    {
        $this->client->method('isEnabled')->willReturn(true);
        $this->purge->expects($this->never())->method('purgeAll');

        $this->plugin->afterClean($this->manager, null, ['config', 'layout', 'block_html']);
    }

    public function testDisabledClientDoesNothing(): void
    {
        $this->client->method('isEnabled')->willReturn(false);
        $this->purge->expects($this->never())->method('purgeAll');

        $this->plugin->afterFlush($this->manager, null, ['full_page']);
        $this->plugin->afterClean($this->manager, null, ['full_page']);
    }

    public function testReturnValuePassedThrough(): void
    {
        $this->client->method('isEnabled')->willReturn(true);

        $this->assertSame('r', $this->plugin->afterFlush($this->manager, 'r', []));
        $this->assertSame('r', $this->plugin->afterClean($this->manager, 'r', []));
    }
}
