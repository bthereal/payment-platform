<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Dto\PendingBucket;
use App\Entity\Organisation;
use App\Enum\PaymentDirection;
use App\Enum\PaymentSource;
use App\Repository\PaymentRepository;
use App\Repository\SettlementPaymentRepository;
use App\Services\DashboardService;
use PHPUnit\Framework\TestCase;

/**
 * DashboardService is pure orchestration since its raw-SQL/DBAL layer moved
 * onto PaymentRepository/SettlementPaymentRepository as QueryBuilder
 * methods (see App\Tests\Application\Services\DashboardServiceTest for the
 * database-backed version, which is what actually proves those aggregate
 * queries are correct). This test mocks both repositories and checks only
 * DashboardService's own logic: the platformFeeNet subtraction, the
 * pending-bucket-array-to-DTO mapping, and the day/week/month date-boundary
 * arithmetic — none of which needs a database to verify. The figures used
 * are the same canonical scenario tests/Fixtures/records.json encodes
 * (ch_A/ch_B/ch_C + re_1), so this test can be cross-checked against the
 * integration one.
 */
final class DashboardServiceTest extends TestCase
{
    public function testSummarizeAssemblesTheDtoFromRepositoryAggregates(): void
    {
        $organisation = new Organisation();
        $organisation->setName('Fixture Organisation');
        $dateFrom = new \DateTimeImmutable('2026-01-20');

        // Pure return-value doubles — nothing here asserts an interaction
        // happened, only what DashboardService does with the values it gets
        // back, so these are stubs, not mocks (see PHPUnit's own guidance:
        // createMock() without expects() is deprecated as of PHPUnit 13).
        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('sumGrossAmount')->willReturnMap([
            [$organisation, PaymentDirection::CREDIT, 4500],
        ]);
        $paymentRepository->method('sumGrossAmountBySource')->willReturnMap([
            [$organisation, PaymentDirection::DEBIT, PaymentSource::REFUND, 2000],
            [$organisation, PaymentDirection::DEBIT, PaymentSource::DISPUTE, 300],
        ]);
        $paymentRepository->method('sumApplicationFee')->willReturnMap([
            [$organisation, PaymentDirection::CREDIT, 225],
            [$organisation, PaymentDirection::DEBIT, 100],
        ]);
        $paymentRepository->method('sumStripeFee')->willReturn(135);
        $paymentRepository->method('sumNetAmount')->willReturn(2300);
        $paymentRepository->method('sumGrossAmountBetween')->willReturn(0);
        $paymentRepository->method('pendingBuckets')->willReturn([
            ['availableOn' => '2026-01-10', 'amount' => 2300],
            ['availableOn' => '2026-01-15', 'amount' => -1840],
        ]);

        $settlementPaymentRepository = $this->createStub(SettlementPaymentRepository::class);
        $settlementPaymentRepository->method('sumPaidOutToDate')->willReturn(1840);

        $service = new DashboardService($paymentRepository, $settlementPaymentRepository);

        $summary = $service->summarize($organisation, $dateFrom);

        self::assertSame('2026-01-20', $summary->dateFrom);
        self::assertSame(4500, $summary->grossSales);
        self::assertSame(225, $summary->platformFeeTaken);
        self::assertSame(100, $summary->platformFeeReversed);
        self::assertSame(125, $summary->platformFeeNet, 'platformFeeNet must be taken minus reversed, not re-queried');
        self::assertSame(135, $summary->stripeFee);
        self::assertSame(2000, $summary->refundsIssued);
        self::assertSame(300, $summary->disputesIssued);
        self::assertSame(2300, $summary->netEarned);
        self::assertSame(1840, $summary->paidOutToDate);

        self::assertCount(2, $summary->pending);
        self::assertInstanceOf(PendingBucket::class, $summary->pending[0]);
        self::assertSame('2026-01-10', $summary->pending[0]->availableOn);
        self::assertSame(2300, $summary->pending[0]->amount);
        self::assertSame('2026-01-15', $summary->pending[1]->availableOn);
        self::assertSame(-1840, $summary->pending[1]->amount, 'a refund landing after its charge was paid out must survive as a negative bucket');
    }

    public function testSalesWindowUsesCalendarInclusiveDayWeekMonthBoundaries(): void
    {
        $organisation = new Organisation();
        $dateFrom = new \DateTimeImmutable('2026-06-20');

        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('sumGrossAmount')->willReturn(0);
        $paymentRepository->method('sumGrossAmountBySource')->willReturn(0);
        $paymentRepository->method('sumApplicationFee')->willReturn(0);
        $paymentRepository->method('sumStripeFee')->willReturn(0);
        $paymentRepository->method('sumNetAmount')->willReturn(0);
        $paymentRepository->method('pendingBuckets')->willReturn([]);

        $captured = [];
        $paymentRepository->method('sumGrossAmountBetween')->willReturnCallback(
            function (
                Organisation $org,
                PaymentDirection $direction,
                \DateTimeImmutable $since,
                \DateTimeImmutable $until,
            ) use (&$captured): int {
                $captured[] = [$since->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s')];

                return 0;
            },
        );

        $settlementPaymentRepository = $this->createStub(SettlementPaymentRepository::class);
        $settlementPaymentRepository->method('sumPaidOutToDate')->willReturn(0);

        $service = new DashboardService($paymentRepository, $settlementPaymentRepository);
        $service->summarize($organisation, $dateFrom);

        self::assertCount(3, $captured, 'summarize() must call sumGrossAmountBetween exactly once each for day, week, month');
        [$day, $week, $month] = [$captured[0], $captured[1], $captured[2]];

        self::assertSame(['2026-06-20 00:00:00', '2026-06-20 23:59:59'], $day);
        self::assertSame(['2026-06-14 00:00:00', '2026-06-20 23:59:59'], $week, 'the 7 calendar days ending on dateFrom, inclusive of both ends');
        self::assertSame(['2026-05-21 00:00:00', '2026-06-20 23:59:59'], $month);
    }

    public function testFindLatestActivityDateDelegatesToThePaymentRepository(): void
    {
        $organisation = new Organisation();
        $latest = new \DateTimeImmutable('2026-06-20T10:00:00Z');

        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('findLatestActivityDate')->willReturn($latest);

        $service = new DashboardService($paymentRepository, $this->createStub(SettlementPaymentRepository::class));

        self::assertSame($latest, $service->findLatestActivityDate($organisation));
    }
}
