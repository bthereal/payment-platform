<?php

declare(strict_types=1);

namespace App\Tests\Application\Services;

use App\Repository\OrganisationRepository;
use App\Services\DashboardService;
use App\Tests\Application\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Seeds tests/Fixtures/records.json (see SeedLedgerCommandTest for the
 * scenario it encodes) and asserts on DashboardService's aggregates
 * directly — the fee split, and the pending buckets including the
 * negative one produced by a refund landing after its charge was paid out.
 */
final class DashboardServiceTest extends DatabaseTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(self::bootKernel());
        $command = $application->find('app:seed-ledger');
        (new CommandTester($command))->execute([
            '--file' => __DIR__ . '/../../Fixtures/records.json',
            '--no-interaction' => true,
        ]);
    }

    public function testSummarize(): void
    {
        $organisation = self::getContainer()->get(OrganisationRepository::class)->findFirst();
        self::assertNotNull($organisation);

        $service = self::getContainer()->get(DashboardService::class);
        $summary = $service->summarize($organisation, new \DateTimeImmutable('2026-01-20'));

        self::assertSame(4500, $summary->grossSales); // 1000 + 2000 + 1500
        self::assertSame(135, $summary->stripeFee); // 30 + 60 + 45
        self::assertSame(225, $summary->platformFeeTaken); // 50 + 100 + 75
        self::assertSame(100, $summary->platformFeeReversed);
        self::assertSame(125, $summary->platformFeeNet); // 225 - 100
        self::assertSame(2000, $summary->refundsIssued);
        self::assertSame(0, $summary->disputesIssued, 'no disputes in this fixture');
        self::assertSame(2300, $summary->netEarned); // 920 + 1840 + 1380 - 1840
        self::assertSame(1840, $summary->paidOutToDate); // only ch_B was swept

        $buckets = [];
        foreach ($summary->pending as $bucket) {
            $buckets[$bucket->availableOn] = $bucket->amount;
        }

        self::assertSame(2300, $buckets['2026-01-10']); // ch_A (920) + ch_C (1380), ch_B excluded (swept)
        self::assertSame(-1840, $buckets['2026-01-15']); // re_1: negative, unswept, post-payout refund

        // Paid out + pending must always reconcile to net earned — that
        // reconciliation is the dashboard's entire point.
        self::assertSame($summary->netEarned, $summary->paidOutToDate + array_sum($buckets));
    }

    public function testUnknownDateFromStillProducesConsistentTotals(): void
    {
        $organisation = self::getContainer()->get(OrganisationRepository::class)->findFirst();
        self::assertNotNull($organisation);

        $service = self::getContainer()->get(DashboardService::class);

        // The headline totals are all-time, not scoped to dateFrom — only
        // the rolling sales window is.
        $summary = $service->summarize($organisation, new \DateTimeImmutable('2099-01-01'));

        self::assertSame(4500, $summary->grossSales);
        self::assertSame(0, $summary->salesWindow->day);
        self::assertSame(0, $summary->salesWindow->week);
        self::assertSame(0, $summary->salesWindow->month, 'no charges were created within a month of 2099-01-01');
    }
}
