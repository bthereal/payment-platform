<?php

declare(strict_types=1);

namespace App\Dto;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The full payload behind GET /api/dashboard. All amounts are
 * integer minor units (pence), matching the source data throughout.
 */
#[OA\Schema(
    schema: 'DashboardSummary',
    description: 'The whole dashboard screen in one payload. Headline totals (everything except salesWindow) are all-time, not scoped to dateFrom.',
)]
final readonly class DashboardSummary
{
    /**
     * @param PendingBucket[] $pending
     */
    public function __construct(
        #[OA\Property(description: 'Reference date the rolling salesWindow figures end on (Y-m-d). Defaults to the latest activity in the data; overridable via ?date_from=.', example: '2026-06-20')]
        public string $dateFrom,
        #[OA\Property(description: 'Sum of everything buyers paid (CREDIT payments), before any fees or refunds.', example: 116250)]
        public int $grossSales,
        #[OA\Property(description: "The platform's application fee taken on sales, gross — not adjusted for refunds.", example: 4125)]
        public int $platformFeeTaken,
        #[OA\Property(description: "The platform's application fee returned to the organisation on refunds.", example: 320)]
        public int $platformFeeReversed,
        #[OA\Property(description: 'platformFeeTaken minus platformFeeReversed.', example: 3805)]
        public int $platformFeeNet,
        #[OA\Property(description: "Stripe's processing fee on sales. Never reversed on refund, per Stripe's rules.", example: 2831)]
        public int $stripeFee,
        #[OA\Property(description: 'Sum of amounts refunded to buyers.', example: 10750)]
        public int $refundsIssued,
        #[OA\Property(description: 'Sum of disputed (charged-back) amounts withheld by the card network, excluding the dispute fee.', example: 800)]
        public int $disputesIssued,
        #[OA\Property(description: 'Net effect of every payment on the organisation balance: SUM(net_amount) across all payments.', example: 98864)]
        public int $netEarned,
        #[OA\Property(description: 'Sum of net amounts actually swept into a paid-out settlement.', example: 44536)]
        public int $paidOutToDate,
        #[OA\Property(
            description: 'Net balance not yet swept into any payout, grouped by availability date. paidOutToDate + sum(pending.amount) always equals netEarned.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: PendingBucket::class)),
        )]
        public array $pending,
        #[OA\Property(ref: new Model(type: SalesWindow::class))]
        public SalesWindow $salesWindow,
    ) {
    }
}
