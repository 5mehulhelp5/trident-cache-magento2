<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Observer;

use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Qoliber\TridentCache\Model\TridentClient;
use Qoliber\TridentCache\Observer\CacheFlushObserver;

class CacheFlushObserverTest extends TestCase
{
    private CacheFlushObserver $observer;
    private TridentClient&MockObject $clientMock;
    private PurgeAfterCommit&MockObject $purgeMock;

    protected function setUp(): void
    {
        $this->clientMock = $this->createMock(TridentClient::class);
        $this->purgeMock = $this->createMock(PurgeAfterCommit::class);
        $this->observer = new CacheFlushObserver($this->clientMock, $this->purgeMock);
    }

    /** X02: the flush goes through the outbox, so a refused clear is retried. */
    public function testCacheFlushRecordsAFullPurge(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(true);

        $this->purgeMock->expects($this->once())->method('purgeAll');
        $this->clientMock->expects($this->never())->method('purgeAll');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testObserverDoesNothingWhenDisabled(): void
    {
        $this->clientMock->method('isEnabled')->willReturn(false);

        $this->purgeMock->expects($this->never())->method('purgeAll');

        $this->observer->execute($this->createMock(Observer::class));
    }
}
