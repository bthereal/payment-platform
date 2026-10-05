<?php

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;

/**
 * Rolling gross-sales figures ending at DashboardSummary::$dateFrom — answers
 * "how much have I made over the last month/week/day".
 */
#[OA\Schema(
    schema: 'SalesWindow',
    description: 'Rolling gross-sales figures, all ending on DashboardSummary.dateFrom. Integer minor units (pence).',
)]
final readonly class SalesWindow
{
    public function __construct(
        #[OA\Property(description: 'Gross sales in the calendar day ending on dateFrom.', example: 0)]
        public int $day,
        #[OA\Property(description: 'Gross sales in the 7 calendar days ending on dateFrom.', example: 60350)]
        public int $week,
        #[OA\Property(description: 'Gross sales in the calendar month ending on dateFrom.', example: 116250)]
        public int $month,
    ) {
    }
}
