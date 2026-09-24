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
use Qoliber\TridentCache\Model\Instance;
use Qoliber\TridentCache\Model\PurgeAfterCommit;
use Qoliber\TridentCache\Model\TridentClient;
use Qoliber\TridentCache\Test\Unit\Model\Fake\TransactionalOutbox;

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
    private TransactionalOutbox $outbox;
    private ResourceConnection&MockObject $resource;
    private int $level = 0;

    /** @var array<int, Instance> X03: what the client reports as configured */
    private array $instances;

    /** @var array<string, TridentClient&MockObject> X03: per-instance clients; absent = $this->client */
    private array $bound = [];

    protected function setUp(): void
    {
        $this->client = $this->createMock(TridentClient::class);
        $this->instances = [new Instance('default', 'http://trident:9301', 'token')];
        $this->client->method('instances')->willReturnCallback(fn (): array => $this->instances);
        $this->client->method('forInstance')->willReturnCallback(
            fn (Instance $i): TridentClient => $this->bound[$i->name] ?? $this->client
        );
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('getTransactionLevel')->willReturnCallback(fn (): int => $this->level);
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->outbox = new TransactionalOutbox(fn (): bool => $this->level > 0);
        $this->purge = $this->newProcess();
        $this->commitCallbacks = new ExecuteCommitCallbacks(new NullLogger());
        CallbackPool::clear(spl_object_hash($this->connection));
    }

    protected function tearDown(): void
    {
        CallbackPool::clear(spl_object_hash($this->connection));
    }

    public function testOutsideATransactionThePurgeGoesOutAtOnce(): void
    {
        $this->client->expects($this->once())->method('deliverTags')->with(['cat_p_1', 'cat_p']);

        $this->purge->purgeTags(['cat_p_1', 'cat_p']);
    }

    /**
     * The defect this class exists for: the purge left while the save
     * transaction was still open, so Trident re-fetched the old page.
     */
    public function testInsideATransactionNothingIsSentBeforeTheCommit(): void
    {
        $this->level = 1;
        $this->client->expects($this->never())->method('deliverTags');

        $this->purge->purgeTags(['cat_p_1']);
    }

    public function testAnInnerCommitSendsNothing(): void
    {
        $this->client->expects($this->never())->method('deliverTags');

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
        $this->client->expects($this->once())->method('deliverTags')
            ->with($this->identicalTo(['123', 'cat_p_1']))->willReturn(true);

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
        $this->client->expects($this->never())->method('deliverTags');

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->rollBack();
    }

    public function testPurgeAllInsideATransactionSupersedesPendingTags(): void
    {
        $this->client->expects($this->never())->method('deliverTags');
        $this->client->expects($this->once())->method('deliverAll')->willReturn(true);

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->purge->purgeAll();
        $this->commitTo(0);
    }

    public function testPurgeAllOutsideATransactionGoesOutAtOnce(): void
    {
        $this->client->expects($this->once())->method('deliverAll')->willReturn(true);

        $this->purge->purgeAll();
    }

    public function testASecondFlushSendsNothing(): void
    {
        $this->client->expects($this->once())->method('deliverTags')->willReturn(true);

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->commitTo(0);
        $this->purge->flush();
    }

    /**
     * A config value is only readable after the configuration reloads, so its
     * purge must not go out with the commit — it would be spent re-storing the
     * old value, which is how the edge ended up one change behind on 2.4.x.
     */
    public function testConfigTagsAreNotSentByTheCommit(): void
    {
        $this->client->expects($this->never())->method('deliverTags');

        $this->level = 1;
        $this->purge->purgeConfigTags(['robots_1']);
        $this->commitTo(0);
    }

    public function testConfigTagsGoOutWhenTheConfigurationReloads(): void
    {
        $this->client->expects($this->once())->method('deliverTags')
            ->with(['robots_1', 'robots_2'])->willReturn(true);

        $this->level = 1;
        $this->purge->purgeConfigTags(['robots_1']);
        $this->purge->purgeConfigTags(['robots_2']);
        $this->commitTo(0);

        $this->purge->flushConfigTags();
    }

    public function testASecondReloadSendsNothing(): void
    {
        $this->client->expects($this->once())->method('deliverTags')->willReturn(true);

        $this->purge->purgeConfigTags(['robots_1']);
        $this->purge->flushConfigTags();
        $this->purge->flushConfigTags();
    }

    public function testPurgeAllSupersedesHeldConfigTags(): void
    {
        $this->client->expects($this->never())->method('deliverTags');
        $this->client->expects($this->once())->method('deliverAll')->willReturn(true);

        $this->level = 1;
        $this->purge->purgeConfigTags(['robots_1']);
        $this->purge->purgeAll();
        $this->commitTo(0);
    }

    // ---- X02: durable delivery ------------------------------------------

    /**
     * The defect: pending state was cleared before delivery was checked, so a
     * purge Trident refused (401 after a token rotation, 429, 503, timeout)
     * was simply gone. It must stay and go out with the next drain.
     */
    public function testAnUnacknowledgedPurgeIsKeptAndResentByTheNextDrain(): void
    {
        $answers = [false, true];
        $sent = [];
        $this->client->method('deliverTags')->willReturnCallback(
            function (array $tags) use (&$answers, &$sent): bool {
                $sent[] = $tags;
                return array_shift($answers);
            }
        );

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->commitTo(0);
        $this->assertCount(1, $this->outbox->rows, 'refused, so still pending');
        $this->assertNotEmpty($this->outbox->failures, 'and the failure is recorded');

        $this->outbox->backingOff = []; // the backoff has passed
        $this->purge->drain(50);

        $this->assertSame([['cat_p_1'], ['cat_p_1']], $sent);
        $this->assertSame([], $this->outbox->rows, 'acknowledged, so removed');
    }

    /**
     * The transaction commits and the process dies before the commit
     * callback runs — the purge must survive in the database and be sent by
     * the next process (cron or the next commit).
     */
    public function testAPurgeSurvivesTheProcessDyingAfterTheCommit(): void
    {
        $sent = $this->recordTagRequests();

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->commitAndDie();
        $this->assertSame([], $sent->requests, 'nothing was sent before the death');

        $this->newProcess()->drain(500);

        $this->assertSame([['cat_p_1']], $sent->requests);
        $this->assertSame([], $this->outbox->rows);
    }

    /** Sent, then the process died before removing the entry: sent again. */
    public function testDyingBetweenSendAndRemoveCostsADuplicateNotALoss(): void
    {
        $sent = $this->recordTagRequests();
        $this->outbox->enqueue('tags', ['cat_p_1'], 'default');
        $this->client->method('deliverTags'); // recorded above
        // Delivered by a process that died before remove(): the row is still there.
        $this->newProcess()->drain(500);
        $this->outbox->enqueue('tags', ['cat_p_1'], 'default');
        $this->newProcess()->drain(500);

        $this->assertSame([['cat_p_1'], ['cat_p_1']], $sent->requests, 'idempotent re-delivery');
        $this->assertSame([], $this->outbox->rows);
    }

    public function testARollBackLeavesNoIntentBehind(): void
    {
        $this->client->expects($this->never())->method('deliverTags');

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->rollBack();
        $this->newProcess()->drain(500);

        $this->assertSame([], $this->outbox->rows);
    }

    /**
     * A full clear supersedes only the entries its drain READ before sending
     * it. A row an open transaction wrote with a LOWER id, committed after
     * the clear went out, describes a change the clear never saw.
     */
    public function testAClearRemovesOnlyTheEntriesItRead(): void
    {
        $sent = $this->recordTagRequests();
        $late = $this->outbox->reserveForOtherTransaction('tags', ['cat_p_late']);
        $this->outbox->enqueue('tags', ['cat_p_1'], 'default');
        $this->outbox->enqueue('all', [], 'default');
        $this->client->method('deliverAll')->willReturnCallback(function () use ($late): bool {
            // The other transaction commits while the clear is on the wire.
            $this->outbox->commitOther($late);
            return true;
        });

        $this->newProcess()->drain(500);
        $this->assertSame([], $sent->requests, 'cat_p_1 was covered by the clear');
        $this->assertArrayHasKey($late->id, $this->outbox->rows, 'the late row survives the clear');

        $this->newProcess()->drain(500);
        $this->assertSame([['cat_p_late']], $sent->requests);
    }

    public function testADrainStopsAfterThreeConsecutiveFailures(): void
    {
        $calls = 0;
        $this->client->method('deliverTags')->willReturnCallback(function () use (&$calls): bool {
            $calls++;
            return false;
        });
        for ($i = 0; $i < 5; $i++) {
            $this->outbox->enqueue('tags', array_map(fn (int $n): string => "t{$i}_$n", range(1, 1000)), 'default');
        }

        $this->newProcess()->drain(500);

        $this->assertSame(3, $calls, 'a down edge is not waited out five times');
        $this->assertCount(5, $this->outbox->rows, 'nothing is dropped');
    }

    public function testEntriesAreMergedIntoRequestsOfAtMostAThousandTags(): void
    {
        $sent = $this->recordTagRequests();
        $this->outbox->enqueue('tags', ['a', 'b'], 'default');
        $this->outbox->enqueue('tags', ['b', 'c'], 'default');
        $this->outbox->enqueue('tags', array_map(fn (int $n): string => "x$n", range(1, 999)), 'default');

        $this->newProcess()->drain(500);

        $this->assertSame(['a', 'b', 'c'], $sent->requests[0]);
        $this->assertCount(999, $sent->requests[1]);
        $this->assertSame([], $this->outbox->rows);
    }

    /**
     * A refused entry waits out its backoff for automatic retries — but the
     * operator's `trident:purge:drain`, run after fixing the token, delivers
     * it now (found on the live stack: the command delivered nothing).
     */
    public function testAForcedDrainIgnoresTheBackoffAnAutomaticOneKeeps(): void
    {
        $answers = [false, true];
        $this->client->method('deliverTags')->willReturnCallback(
            function () use (&$answers): bool {
                return array_shift($answers);
            }
        );
        $this->purge->purgeTags(['cat_p_1']);
        $this->assertCount(1, $this->outbox->rows, 'refused');

        $this->assertSame(0, $this->newProcess()->drain(500), 'backing off: an automatic drain waits');
        $this->assertSame(1, $this->newProcess()->drain(500, true), 'the operator drain delivers now');
        $this->assertSame([], $this->outbox->rows);
    }

    /** Before `setup:upgrade` creates the table, purges still go out. */
    public function testWithoutItsTableThePurgeStillGoesOutDirectly(): void
    {
        $this->outbox->broken = true;
        $this->client->expects($this->once())->method('deliverTags')->with(['cat_p_1'])->willReturn(true);

        $this->purge->purgeTags(['cat_p_1']);
    }

    // =========================================================================
    // X03 — several instances
    // =========================================================================

    /**
     * Two instances, each with its own client whose answers the test scripts.
     *
     * @param array<string, array<int, bool>> $answers per instance, in order; missing = true
     * @return object{sent: array<string, array<int, array<string>>>}
     */
    private function twoInstances(array $answers = []): object
    {
        $this->instances = [
            new Instance('edge-1', 'http://edge-1:9301', 't1'),
            new Instance('edge-2', 'http://edge-2:9301', 't2'),
        ];
        $log = new class {
            /** @var array<string, array<int, array<string>>> */
            public array $sent = [];

            /** @var array<string, array<int, bool>> */
            public array $answers = [];
        };
        $log->answers = $answers;
        foreach (['edge-1', 'edge-2'] as $name) {
            $client = $this->createMock(TridentClient::class);
            $client->method('deliverTags')->willReturnCallback(
                function (array $tags) use ($name, $log): bool {
                    $log->sent[$name][] = $tags;
                    return ($log->answers[$name] ?? []) === [] ? true : array_shift($log->answers[$name]);
                }
            );
            $client->method('deliverAll')->willReturnCallback(function () use ($name, $log): bool {
                $log->sent[$name][] = ['*'];
                return true;
            });
            $client->method('lastFailure')->willReturn($name . ': HTTP 503');
            $this->bound[$name] = $client;
        }
        $this->client->expects($this->never())->method('deliverTags');
        return $log;
    }

    public function testAPurgeIsRecordedAndDeliveredOncePerInstance(): void
    {
        $log = $this->twoInstances();

        $this->level = 1;
        $this->purge->purgeTags(['cat_p_1']);
        $this->assertSame([], $log->sent, 'held until the commit');
        $this->commitAndDie();
        $this->assertSame(['edge-1', 'edge-2'], array_map(fn ($e) => $e->instance, $this->outbox->all()));
        $this->newProcess()->drain(50);

        $this->assertSame(['edge-1' => [['cat_p_1']], 'edge-2' => [['cat_p_1']]], $log->sent);
        $this->assertSame([], $this->outbox->rows);
    }

    /**
     * edge-2 is down: edge-1's purge is done and gone, edge-2's stays — and
     * the next drain sends it to edge-2 alone, not to edge-1 again.
     */
    public function testAnInstanceThatIsDownKeepsOnlyItsOwnPurgePending(): void
    {
        $log = $this->twoInstances(['edge-2' => [false, true]]);

        $this->purge->purgeTags(['cat_p_1']);
        $this->assertSame(['edge-2'], array_map(fn ($e) => $e->instance, $this->outbox->all()));
        $this->assertSame('edge-2: HTTP 503', end($this->outbox->failures));

        $this->outbox->backingOff = [];
        $this->purge->drain(50);

        $this->assertSame([['cat_p_1']], $log->sent['edge-1'], 'edge-1 was not sent it twice');
        $this->assertSame([['cat_p_1'], ['cat_p_1']], $log->sent['edge-2']);
        $this->assertSame([], $this->outbox->rows);
    }

    /** Three failures stop one instance's drain, never the other's. */
    public function testOneInstancesFailuresDoNotStopAnother(): void
    {
        $log = $this->twoInstances(['edge-1' => [false, false, false, false, false]]);
        foreach (range(1, 5) as $i) {
            $this->outbox->enqueue('tags', array_map(fn (int $j): string => "t{$i}_$j", range(1, 1000)), 'edge-1');
            $this->outbox->enqueue('tags', array_map(fn (int $j): string => "t{$i}_$j", range(1, 1000)), 'edge-2');
        }

        $this->purge->drain(50);

        $this->assertCount(3, $log->sent['edge-1'], 'edge-1 gave up after three');
        $this->assertCount(5, $log->sent['edge-2'], 'edge-2 delivered everything');
        $this->assertSame(['edge-1'], array_values(array_unique(array_map(fn ($e) => $e->instance, $this->outbox->all()))));
    }

    /** A row written before X03 has no instance: every instance is owed it. */
    public function testAPurgeRecordedBeforeInstancesExistedGoesToEveryInstance(): void
    {
        $log = $this->twoInstances();
        $this->outbox->enqueue('tags', ['cat_p_1']);

        $this->purge->drain(50);
        $this->assertSame(['edge-1', 'edge-2'], array_map(fn ($e) => $e->instance, $this->outbox->all()), 'split');
        $this->purge->drain(50);

        $this->assertSame(['edge-1' => [['cat_p_1']], 'edge-2' => [['cat_p_1']]], $log->sent);
        $this->assertSame([], $this->outbox->rows);
    }

    /**
     * X02 may leave thousands of rows behind; the first request after the
     * upgrade must split only its own batch, not all of them.
     */
    public function testSplittingOldRowsStaysWithinTheDrainLimit(): void
    {
        $this->twoInstances();
        foreach (range(1, 120) as $i) {
            $this->outbox->enqueue('tags', ["t$i"]);
        }

        $this->purge->drain(50);

        $legacy = array_filter($this->outbox->all(), fn ($e) => $e->instance === null);
        $this->assertCount(70, $legacy, 'only the 50 read were split');
        $this->assertCount(170, $this->outbox->all(), '50 split in two, 70 untouched');
    }

    /** A split row keeps its attempts and its backoff: it is not "new". */
    public function testASplitRowKeepsItsHistory(): void
    {
        $this->twoInstances();
        $this->outbox->enqueue('tags', ['cat_p_1']);
        $this->outbox->fail([1], 'HTTP 503');
        $this->outbox->fail([1], 'HTTP 503');

        $this->purge->drain(50, true);

        $this->assertSame([2, 2], array_map(fn ($e) => $e->attempts, $this->outbox->all()));
        $this->assertSame([], $this->outbox->due(50), 'still backing off');
    }

    /**
     * The column compares names case-insensitively; PHP does not. A row for
     * "Edge-1" matched by "edge-1" must be skipped — not crash the save that
     * triggered the drain, and not go to the wrong instance.
     */
    public function testARowWhoseNameMatchesOnlyCaseInsensitivelyIsSkipped(): void
    {
        $log = $this->twoInstances();
        $this->outbox->caseInsensitive = true;
        $this->outbox->enqueue('tags', ['old'], 'Edge-1');
        $this->outbox->enqueue('tags', ['cat_p_1'], 'edge-1');

        $this->assertSame(1, $this->purge->drain(50));

        $this->assertSame(['edge-1' => [['cat_p_1']]], $log->sent);
        $this->assertSame(['Edge-1'], array_map(fn ($e) => $e->instance, $this->outbox->all()));
    }

    /**
     * An instance removed from env.php: its purges are neither sent anywhere
     * nor dropped behind the operator's back, and they do not take the
     * drain's slots from the instances that exist.
     */
    public function testPurgesForARemovedInstanceAreKeptButDoNotBlockTheOthers(): void
    {
        $log = $this->twoInstances();
        foreach (range(1, 60) as $i) {
            $this->outbox->enqueue('tags', ["old_$i"], 'edge-gone');
        }
        $this->outbox->enqueue('tags', ['cat_p_1'], 'edge-1');

        $this->purge->drain(50);

        $this->assertSame(['edge-1' => [['cat_p_1']]], $log->sent);
        $this->assertCount(60, $this->outbox->rows, 'kept, not sent');
        $this->assertSame(60, $this->outbox->forgetInstance('edge-gone'));
        $this->assertSame([], $this->outbox->rows);
    }

    public function testAFullClearIsOwedToEveryInstance(): void
    {
        $log = $this->twoInstances();

        $this->purge->purgeAll();

        $this->assertSame(['edge-1' => [['*']], 'edge-2' => [['*']]], $log->sent);
    }

    /** A PurgeAfterCommit in a fresh PHP process: same database, no memory. */
    private function newProcess(): PurgeAfterCommit
    {
        return new PurgeAfterCommit($this->resource, $this->client, $this->outbox, new NullLogger());
    }

    private function commitTo(int $level): void
    {
        $this->level = $level;
        if ($level === 0) {
            $this->outbox->commit();
        }
        $this->commitCallbacks->afterCommit($this->connection, $this->connection);
    }

    /** The transaction commits, then the process dies before the callbacks. */
    private function commitAndDie(): void
    {
        $this->level = 0;
        $this->outbox->commit();
        CallbackPool::clear(spl_object_hash($this->connection));
    }

    private function rollBack(): void
    {
        $this->level = 0;
        $this->outbox->rollBack();
        $this->commitCallbacks->afterRollBack($this->connection, $this->connection);
    }

    private function recordTagRequests(): object
    {
        $sent = new class {
            /** @var array<int, array<string>> */
            public array $requests = [];
        };
        $this->client->method('deliverTags')->willReturnCallback(function (array $tags) use ($sent) {
            $sent->requests[] = $tags;
            return true;
        });
        return $sent;
    }
}
