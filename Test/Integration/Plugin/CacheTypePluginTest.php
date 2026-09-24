<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Plugin;

use Magento\PageCache\Model\Cache\Type as PageCacheType;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Qoliber\TridentCache\Model\TridentClient;
use Qoliber\TridentCache\Plugin\CacheTypePlugin;
use Zend_Cache;

class CacheTypePluginTest extends TestCase
{
    private CacheTypePlugin $plugin;
    private TridentClient&MockObject $clientMock;
    private PurgeAfterCommit&MockObject $purgeMock;
    private PageCacheType&MockObject $subjectMock;

    protected function setUp(): void
    {
        $this->clientMock = $this->createMock(TridentClient::class);
        $this->purgeMock = $this->createMock(PurgeAfterCommit::class);
        $this->plugin = new CacheTypePlugin($this->clientMock, $this->purgeMock);
        // A purge sent straight from here would leave before the commit again —
        // the plugin keeps the client only to ask whether it is enabled.
        $this->clientMock->expects($this->never())->method('purgeTags');
        $this->clientMock->expects($this->never())->method('purgeAll');
        $this->subjectMock = $this->createMock(PageCacheType::class);
    }

    public function testCleanAllTriggersPurgeAll(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(true);

        $this->purgeMock->expects($this->once())->method('purgeAll');
        $this->purgeMock->expects($this->never())->method('purgeTags');

        $this->plugin->afterClean($this->subjectMock, true, Zend_Cache::CLEANING_MODE_ALL);
    }

    public function testCleanWithTagsTriggersPurgeTags(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(true);

        $this->purgeMock->expects($this->never())->method('purgeAll');
        $this->purgeMock->expects($this->once())
            ->method('purgeTags')
            ->with(['cat_p_1', 'cat_c_2']);

        $this->plugin->afterClean(
            $this->subjectMock,
            true,
            Zend_Cache::CLEANING_MODE_MATCHING_TAG,
            ['cat_p_1', 'cat_c_2']
        );
    }

    public function testInternalFpcTagFilteredOut(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(true);

        $this->purgeMock->expects($this->once())
            ->method('purgeTags')
            ->with(['cat_p_1']);

        $this->plugin->afterClean(
            $this->subjectMock,
            true,
            Zend_Cache::CLEANING_MODE_MATCHING_TAG,
            ['cat_p_1', 'FPC']
        );
    }

    public function testOnlyFpcTagResultsInNoPurge(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(true);

        $this->purgeMock->expects($this->never())->method('purgeTags');
        $this->purgeMock->expects($this->never())->method('purgeAll');

        $this->plugin->afterClean(
            $this->subjectMock,
            true,
            Zend_Cache::CLEANING_MODE_MATCHING_TAG,
            ['FPC']
        );
    }

    public function testDisabledClientDoesNothing(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(false);

        $this->purgeMock->expects($this->never())->method('purgeAll');
        $this->purgeMock->expects($this->never())->method('purgeTags');

        $result = $this->plugin->afterClean($this->subjectMock, true, Zend_Cache::CLEANING_MODE_ALL);

        $this->assertTrue($result);
    }

    public function testReturnValuePassedThrough(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(true);

        $result = $this->plugin->afterClean($this->subjectMock, true, Zend_Cache::CLEANING_MODE_ALL);

        $this->assertTrue($result);
    }
}
