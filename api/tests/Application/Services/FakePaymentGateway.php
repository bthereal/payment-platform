<?php

declare(strict_types=1);

namespace App\Tests\Application\Services;

use App\Dto\NormalizedCharge;
use App\Dto\NormalizedDispute;
use App\Dto\NormalizedRefund;
use App\Services\PaymentGateway\PaymentGatewayInterface;

/**
 * A second PaymentGatewayInterface implementation, used only in tests, with
 * a raw record shape that looks nothing like Stripe's (different keys, no
 * fee_details array, no signed amounts). PaymentServiceTest wires this in
 * instead of StripePaymentGateway to prove PaymentService really doesn't
 * know or care which gateway it's talking to — exactly the seam a future
 * ApplePayPaymentGateway would occupy.
 */
final class FakePaymentGateway implements PaymentGatewayInterface
{
    /**
     * @param array<string, mixed> $record
     */
    #[\Override]
    public function normalizeCharge(array $record): NormalizedCharge
    {
        return new NormalizedCharge(
            providerId: $record['id'],
            customerReference: $record['customer'] ?? null,
            grossAmount: $record['gross'],
            processorFee: $record['fee'],
            applicationFee: $record['appFee'],
            netAmount: $record['net'],
            availableOn: new \DateTimeImmutable($record['availableOn']),
            createdAt: new \DateTimeImmutable($record['createdAt']),
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    #[\Override]
    public function normalizeRefund(array $record): NormalizedRefund
    {
        return new NormalizedRefund(
            providerId: $record['id'],
            relatedChargeProviderId: $record['relatedChargeId'],
            grossAmount: $record['gross'],
            applicationFee: $record['appFee'],
            netAmount: $record['net'],
            availableOn: new \DateTimeImmutable($record['availableOn']),
            createdAt: new \DateTimeImmutable($record['createdAt']),
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    #[\Override]
    public function normalizeDispute(array $record): NormalizedDispute
    {
        return new NormalizedDispute(
            providerId: $record['id'],
            relatedChargeProviderId: $record['relatedChargeId'],
            grossAmount: $record['gross'],
            disputeFee: $record['fee'],
            netAmount: $record['net'],
            availableOn: new \DateTimeImmutable($record['availableOn']),
            createdAt: new \DateTimeImmutable($record['createdAt']),
        );
    }
}
