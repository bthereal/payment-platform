<?php

declare(strict_types=1);

namespace App\Services;

use App\Dto\DashboardSummary;
use App\Dto\PendingBucket;
use App\Dto\SalesWindow;
use App\Entity\Organisation;
use App\Enum\PaymentDirection;
use App\Enum\PaymentSource;
use App\Repository\PaymentRepository;
use App\Repository\SettlementPaymentRepository;

/**
 * Read-model for the dashboard. Pure orchestration: every actual aggregate
 * (SUM(...) by direction, the NOT EXISTS(settlement_payment) "pending"
 * query, the paid-out join) lives on PaymentRepository/
 * SettlementPaymentRepository as a QueryBuilder method
 */
final class DashboardService
{
    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly SettlementPaymentRepository $settlementPaymentRepository,
    ) {
    }

    public function findLatestActivityDate(Organisation $organisation): ?\DateTimeImmutable
    {
        return $this->paymentRepository->findLatestActivityDate($organisation);
    }

    public function summarize(Organisation $organisation, \DateTimeImmutable $dateFrom): DashboardSummary
    {
        $platformFeeTaken = $this->paymentRepository->sumApplicationFee($organisation, PaymentDirection::CREDIT);
        $platformFeeReversed = $this->paymentRepository->sumApplicationFee($organisation, PaymentDirection::DEBIT);

        return new DashboardSummary(
            dateFrom: $dateFrom->format('Y-m-d'),
            grossSales: $this->paymentRepository->sumGrossAmount($organisation, PaymentDirection::CREDIT),
            platformFeeTaken: $platformFeeTaken,
            platformFeeReversed: $platformFeeReversed,
            platformFeeNet: $platformFeeTaken - $platformFeeReversed,
            stripeFee: $this->paymentRepository->sumStripeFee($organisation, PaymentDirection::CREDIT),
            refundsIssued: $this->paymentRepository->sumGrossAmountBySource($organisation, PaymentDirection::DEBIT, PaymentSource::REFUND),
            disputesIssued: $this->paymentRepository->sumGrossAmountBySource($organisation, PaymentDirection::DEBIT, PaymentSource::DISPUTE),
            netEarned: $this->paymentRepository->sumNetAmount($organisation),
            paidOutToDate: $this->settlementPaymentRepository->sumPaidOutToDate($organisation, new \DateTimeImmutable()),
            pending: $this->pendingBuckets($organisation),
            salesWindow: $this->salesWindow($organisation, $dateFrom),
        );
    }

    /**
     * @return PendingBucket[]
     */
    private function pendingBuckets(Organisation $organisation): array
    {
        return array_map(
            static fn (array $row): PendingBucket => new PendingBucket($row['availableOn'], $row['amount']),
            $this->paymentRepository->pendingBuckets($organisation),
        );
    }

    private function salesWindow(Organisation $organisation, \DateTimeImmutable $dateFrom): SalesWindow
    {
        // Calendar-day-inclusive boundaries: "last week" ending on dateFrom's
        // date covers the 7 calendar days up to and including that date, so
        // a date exactly 7 days back (e.g. dateFrom 06-20, week start
        // 06-14) is fully included rather than clipped by a 23:59:59-to-N-
        // days-back subtraction landing mid-day.
        $until = $dateFrom->setTime(23, 59, 59);

        return new SalesWindow(
            day: $this->paymentRepository->sumGrossAmountBetween(
                $organisation,
                PaymentDirection::CREDIT,
                $dateFrom->setTime(0, 0, 0),
                $until,
            ),
            week: $this->paymentRepository->sumGrossAmountBetween(
                $organisation,
                PaymentDirection::CREDIT,
                $dateFrom->modify('-6 days')->setTime(0, 0, 0),
                $until,
            ),
            month: $this->paymentRepository->sumGrossAmountBetween(
                $organisation,
                PaymentDirection::CREDIT,
                $dateFrom->modify('-1 month')->modify('+1 day')->setTime(0, 0, 0),
                $until,
            ),
        );
    }
}
