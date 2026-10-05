<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway;

use App\Dto\NormalizedCharge;
use App\Dto\NormalizedDispute;
use App\Dto\NormalizedRefund;

/**
 * Knows Stripe's (specifically this fixture's Stripe-Connect-style) raw
 * record shape — field names, the fee_details array, signed vs. magnitude
 * amounts — and nothing else. All of that stays here, out of
 * PaymentService, so PaymentService has no idea it's Stripe on the other
 * end.
 */
final class StripePaymentGateway implements PaymentGatewayInterface
{
    #[\Override]
    public function normalizeCharge(array $record): NormalizedCharge
    {
        return new NormalizedCharge(
            providerId: $record['id'],
            customerReference: $record['order_ref'] ?? null,
            grossAmount: $record['amount'],
            processorFee: $this->feeAmount($record, 'stripe_fee'),
            applicationFee: $this->feeAmount($record, 'application_fee'),
            netAmount: $record['net'],
            availableOn: new \DateTimeImmutable($record['available_on']),
            createdAt: new \DateTimeImmutable($record['created']),
        );
    }

    #[\Override]
    public function normalizeRefund(array $record): NormalizedRefund
    {
        return new NormalizedRefund(
            providerId: $record['id'],
            relatedChargeProviderId: $record['charge'],
            grossAmount: $record['amount'],
            applicationFee: $this->feeAmount($record, 'application_fee'),
            netAmount: $record['net'],
            availableOn: new \DateTimeImmutable($record['available_on']),
            createdAt: new \DateTimeImmutable($record['created']),
        );
    }

    #[\Override]
    public function normalizeDispute(array $record): NormalizedDispute
    {
        return new NormalizedDispute(
            providerId: $record['id'],
            relatedChargeProviderId: $record['charge'],
            grossAmount: $record['amount'],
            disputeFee: $this->feeAmount($record, 'dispute_fee'),
            netAmount: $record['net'],
            availableOn: new \DateTimeImmutable($record['available_on']),
            createdAt: new \DateTimeImmutable($record['created']),
        );
    }

    /** @param array<string, mixed> $record */
    private function feeAmount(array $record, string $type): int
    {
        foreach ($record['fee_details'] ?? [] as $fee) {
            if (($fee['type'] ?? null) === $type) {
                return abs((int) $fee['amount']);
            }
        }

        return 0;
    }
}
