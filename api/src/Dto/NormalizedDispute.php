<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A dispute, translated out of one payment gateway's raw record shape — see
 * NormalizedCharge. No applicationFee field: a dispute isn't a platform
 * application-fee event, only a processor-fee one (the dispute_fee
 * Stripe charges for handling it), so there is nothing for a gateway to
 * report here.
 */
final readonly class NormalizedDispute
{
    public function __construct(
        public string $providerId,
        public string $relatedChargeProviderId,
        public int $grossAmount,
        public int $disputeFee,
        public int $netAmount,
        public \DateTimeImmutable $availableOn,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
