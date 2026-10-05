<?php

declare(strict_types=1);

namespace App\Dto;

use OpenApi\Attributes as OA;

/**
 * One "pending" bucket: the net balance of Payment rows sharing an
 * availableOn date that are not yet linked to any Settlement. Can be
 * negative (see README.md — a refund landing after its charge was already
 * paid out shows up here as a negative bucket, which is the point).
 */
#[OA\Schema(
    schema: 'PendingBucket',
    description: 'Net balance of payments sharing an availability date that have not yet been swept into a payout. Amount can be negative — see the README\'s "post-payout refund" case.',
)]
final readonly class PendingBucket
{
    public function __construct(
        #[OA\Property(description: 'Date these funds become/became eligible for payout (Y-m-d).', example: '2026-06-20')]
        public string $availableOn,
        #[OA\Property(description: 'Net amount, integer minor units. Negative when a refund lands after its charge was already paid out.', example: -4380)]
        public int $amount,
    ) {
    }
}
