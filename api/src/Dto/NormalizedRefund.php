<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A refund, translated out of one payment gateway's raw record shape — see
 * NormalizedCharge. No processorFee field: the ledger's rule (see
 * PaymentService) is that a payment processor's own fee is never reversed,
 * only the application fee is, so there is nothing for a gateway to report
 * here.
 */
final readonly class NormalizedRefund
{
    public function __construct(
        public string $providerId,
        public string $relatedChargeProviderId,
        public int $grossAmount,
        public int $applicationFee,
        public int $netAmount,
        public \DateTimeImmutable $availableOn,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
