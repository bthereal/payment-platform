<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway;

use App\Dto\NormalizedCharge;
use App\Dto\NormalizedDispute;
use App\Dto\NormalizedRefund;

/**
 * The seam a new payment provider plugs into. PaymentService depends only
 * on this interface, never on a concrete gateway, so adding a provider
 * (Apple Pay, a second Stripe-Connect-style processor, ...) is a new class
 * implementing normalizeCharge()/normalizeRefund()/normalizeDispute() for
 * that provider's raw record shape — nothing in PaymentService or the
 * ledger domain model changes.
 *
 * There is exactly one implementation today (StripePaymentGateway), so it's
 * the sole autowired PaymentGatewayInterface — no factory or provider
 * registry exists yet. That's deliberate: with one real implementation
 * there is nothing to select between, and a factory with a single branch
 * would be untested scaffolding. Once a second provider exists, resolving
 * "which gateway for this record" becomes a real problem worth its own
 * small factory service at that point.
 */
interface PaymentGatewayInterface
{
    /** @param array<string, mixed> $record */
    public function normalizeCharge(array $record): NormalizedCharge;

    /** @param array<string, mixed> $record */
    public function normalizeRefund(array $record): NormalizedRefund;

    /** @param array<string, mixed> $record */
    public function normalizeDispute(array $record): NormalizedDispute;
}
