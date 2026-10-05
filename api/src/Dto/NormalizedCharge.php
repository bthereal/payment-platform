<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A charge, translated out of one payment gateway's raw record shape and
 * into the vocabulary PaymentService actually needs. Every
 * PaymentGatewayInterface implementation produces this same shape
 * regardless of provider, so PaymentService never has to know whether the
 * money moved through Stripe, Apple Pay, or anything else.
 */
final readonly class NormalizedCharge
{
    public function __construct(
        public string $providerId,
        public ?string $customerReference,
        public int $grossAmount,
        public int $processorFee,
        public int $applicationFee,
        public int $netAmount,
        public \DateTimeImmutable $availableOn,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
