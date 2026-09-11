<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\CallbackPool;
use Magento\Framework\Model\ExecuteCommitCallbacks;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Qoliber\TridentCache\Model\TridentClient;

/**
 * Commits and rollbacks go through Magento's own `execute_commit_callbacks`
 * plugin rather than a copy of its loop, so a framework change that alters
 * when callbacks run breaks these tests instead of passing them.
 */
class PurgeAfterCommitTest extends TestCase
{
    private TridentClient&MockObject $client;
    private AdapterInterface&MockObject $connection;
    private PurgeAfterCommit $purge;
    private ExecuteCommitCallbacks $commitCallbacks;
    private int $level = 0;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TridentClient::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('getTransactionLevel')->willReturnCallback(fn (): int => $this->level);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $this->purge = new PurgeAfterCommit($resource, $this->client);
        $this->commitCallbacks = new ExecuteCommitCallbacks(new NullLogger());
        CallbackPool::clear(spl_object_hash($this->connection));
    }

    protected function tearDown(): void
    {
        CallbackPool::clear(spl_object_hash($this->connection));
    }

    public function testOutsideATransactionThePurgeGoesOutAtOnce(): void
    {
        $this->client->expects($this->once())->method('purgeTags')->with(['cat_p_1', 'cat_p']);

        $this->purge->purgeTags(['cat_p_1', 'cat_p']);
    }

    /**
     * The defect this class exists for: the purge left while the save
     * transaction was still open, so Trident re-fetched the old page.
     */
    public function testInsideATransactionNothingIsSentBeforeTheCommit(): void
    {
        $this->level = 1;
        $this->client->expects($this->never())->method('purgeTags');

        $this->purge->purgeTags(['cat_p_1']);
    }

    public function testAnInnerCommitSendsNothing(): void
    {
        $this->client->expects($this->never())->method('purgeTags');

        $this->level = 2;
        $this->purge->purgeTags(['cat_p_1']);
        $this->commitTo(1);
    }

    public function testTheOutermostCommitSendsThePendingTagsOnce(): void
    {
        $sent = $this->recordTagRequests();

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1', 'cat_p']);
        $this->purge->purgeTags(['cat_p', 'cat_c_3']);
        $this->assertSame([], $sent->requests, 'held until the commit');

        $this->commitTo(0);

        $this->assertSame([['cat_p_1', 'cat_p', 'cat_c_3']], $sent->requests, 'one request, merged and deduplicated');
    }

    public function testATagThatLooksNumericStaysAString(): void
    {
        $this->client->expects($this->once())->method('purgeTags')
            ->with($this->identicalTo(['123', 'cat_p_1']));

        $this->level = 1;
        $this->purge->purgeTags(['123', 'cat_p_1']);
        $this->commitTo(0);
    }

    /**
     * Merging a whole transaction into one request must not produce a body the
     * admin API refuses with 413 — that would lose every purge of the commit.
     */
    public function testALargeTransactionIsSentInRequestsOfAtMostAThousandTags(): void
    {
        $sent = $this->recordTagRequests();
        $tags = array_map(fn (int $i): string => "cat_p_$i", range(1, 2500));

        $this->level = 1;
        $this->purge->purgeTags($tags);
        $this->commitTo(0);

        $this->assertSame([1000, 1000, 500], array_map('count', $sent->requests));
        $this->assertSame($tags, array_merge(...$sent->requests), 'every tag sent exactly once, in order');
    }

    public function testOutsideATransactionALargePurgeIsChunkedToo(): void
    {
        $sent = $this->recordTagRequests();

        $this->purge->purgeTags(array_map(fn (int $i): string => "cat_p_$i", range(1, 1001)));

        $this->assertSame([1000, 1], array_map('count', $sent->requests));
    }

    public function testAfterARollbackTheNextTransactionStillFlushes(): void
    {
        $sent = $this->recordTagRequests();

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->rollBack();

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_2']);
        $this->commitTo(0);

        $this->assertCount(1, $sent->requests);
        $this->assertContains('cat_p_2', $sent->requests[0], 'the committed change must be purged');
    }

    public function testARollbackAloneSendsNothing(): void
    {
        $this->client->expects($this->never())->method('purgeTags');

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->rollBack();
    }

    public function testPurgeAllInsideATransactionSupersedesPendingTags(): void
    {
        $this->client->expects($this->never())->method('purgeTags');
        $this->client->expects($this->once())->method('purgeAll');

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->purge->purgeAll();
        $this->commitTo(0);
    }

    public function testPurgeAllOutsideATransactionGoesOutAtOnce(): void
    {
        $this->client->expects($this->once())->method('purgeAll');

        $this->purge->purgeAll();
    }

    public function testASecondFlushSendsNothing(): void
    {
        $this->client->expects($this->once())->method('purgeTags');

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->commitTo(0);
        $this->purge->flush();
    }

    private function commitTo(int $level): void
    {
        $this->level = $level;
        $this->commitCallbacks->afterCommit($this->connection, $this->connection);
    }

    private function rollBack(): void
    {
        $this->level = 0;
        $this->commitCallbacks->afterRollBack($this->connection, $this->connection);
    }

    private function recordTagRequests(): object
    {
        $sent = new class {
            /** @var array<int, array<string>> */
            public array $requests = [];
        };
        $this->client->method('purgeTags')->willReturnCallback(function (array $tags) use ($sent) {
            $sent->requests[] = $tags;
            return [];
        });
        return $sent;
    }
}
