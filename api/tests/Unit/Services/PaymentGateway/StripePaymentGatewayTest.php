<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\PaymentGateway;

use App\Services\PaymentGateway\StripePaymentGateway;
use PHPUnit\Framework\TestCase;

/**
 * A plain unit test — no database, no kernel — for the one class in the
 * seed pipeline that actually knows Stripe's raw record shape. Before the
 * PaymentGateway split this logic was only ever exercised indirectly
 * through SeedLedgerCommandTest/SeedLedgerCommandRealFixtureTest; now that
 * it's its own class, it gets its own direct tests of the field-mapping
 * edge cases those integration tests don't specifically target. The happy
 * path below reads real records out of tests/Fixtures/records.json rather
 * than a hand-typed array; the edge cases (missing fee_details, etc.) are
 * hand-built since the fixture's real records never actually hit them.
 */
final class StripePaymentGatewayTest extends TestCase
{
    private StripePaymentGateway $gateway;

    #[\Override]
    protected function setUp(): void
    {
        $this->gateway = new StripePaymentGateway();
    }

    /** @return array<string, mixed> */
    private function loadFixtureRecord(string $id): array
    {
        $path = __DIR__ . '/../../../Fixtures/records.json';
        $contents = file_get_contents($path);
        $records = json_decode($contents !== false ? $contents : '', true, flags: \JSON_THROW_ON_ERROR);

        foreach ($records as $record) {
            if ($record['id'] === $id) {
                return $record;
            }
        }

        throw new \RuntimeException(\sprintf('Fixture record "%s" not found in %s.', $id, $path));
    }

    public function testNormalizeChargeMapsAllFields(): void
    {
        $charge = $this->gateway->normalizeCharge($this->loadFixtureRecord('ch_A'));

        self::assertSame('ch_A', $charge->providerId);
        self::assertSame('T-1', $charge->customerReference);
        self::assertSame(1000, $charge->grossAmount);
        self::assertSame(30, $charge->processorFee);
        self::assertSame(50, $charge->applicationFee);
        self::assertSame(920, $charge->netAmount);
        self::assertSame('2026-01-10', $charge->availableOn->format('Y-m-d'));
        self::assertSame('2026-01-01T10:00:00+00:00', $charge->createdAt->format('c'));
    }

    public function testNormalizeChargeWithNoOrderRefLeavesCustomerReferenceNull(): void
    {
        $charge = $this->gateway->normalizeCharge([
            'id' => 'ch_2',
            'amount' => 500,
            'fee_details' => [],
            'net' => 500,
            'available_on' => '2026-01-10',
            'created' => '2026-01-01T10:00:00Z',
        ]);

        self::assertNull($charge->customerReference);
    }

    public function testNormalizeChargeWithNoFeeDetailsKeyDefaultsFeesToZero(): void
    {
        $charge = $this->gateway->normalizeCharge([
            'id' => 'ch_3',
            'amount' => 500,
            'net' => 500,
            'available_on' => '2026-01-10',
            'created' => '2026-01-01T10:00:00Z',
        ]);

        self::assertSame(0, $charge->processorFee);
        self::assertSame(0, $charge->applicationFee);
    }

    public function testNormalizeChargeWithFeeDetailsMissingOneTypeDefaultsThatOneToZero(): void
    {
        $charge = $this->gateway->normalizeCharge([
            'id' => 'ch_4',
            'amount' => 500,
            'fee_details' => [
                ['type' => 'stripe_fee', 'amount' => 15],
            ],
            'net' => 485,
            'available_on' => '2026-01-10',
            'created' => '2026-01-01T10:00:00Z',
        ]);

        self::assertSame(15, $charge->processorFee);
        self::assertSame(0, $charge->applicationFee, 'no application_fee entry in fee_details must default to 0, not be skipped/null');
    }

    public function testNormalizeRefundMapsAllFieldsAndTakesTheAbsoluteFeeValue(): void
    {
        // re_1 fully refunds ch_B (amount 2000, net 1840) in the fixture.
        $refund = $this->gateway->normalizeRefund($this->loadFixtureRecord('re_1'));

        self::assertSame('re_1', $refund->providerId);
        self::assertSame('ch_B', $refund->relatedChargeProviderId);
        self::assertSame(2000, $refund->grossAmount);
        self::assertSame(100, $refund->applicationFee, "Stripe's fee_details reports a reversed fee as negative — the gateway must report a magnitude, not a signed value");
        self::assertSame(-1840, $refund->netAmount);
    }

    public function testNormalizeDisputeMapsAllFields(): void
    {
        // Shape matches the real fixture's dp_0001 (disputing an already
        // paid-out charge) — hand-built since tests/Fixtures/records.json
        // deliberately has no dispute record of its own (see
        // SeedLedgerCommandDisputeTest for the ingestion-level coverage of
        // that file instead).
        $dispute = $this->gateway->normalizeDispute([
            'id' => 'dp_1',
            'object' => 'dispute',
            'created' => '2026-01-20T14:30:00Z',
            'charge' => 'ch_A',
            'amount' => 800,
            'currency' => 'gbp',
            'status' => 'needs_response',
            'fee_details' => [
                ['type' => 'dispute_fee', 'amount' => 1500],
            ],
            'net' => -2300,
            'available_on' => '2026-01-20',
        ]);

        self::assertSame('dp_1', $dispute->providerId);
        self::assertSame('ch_A', $dispute->relatedChargeProviderId);
        self::assertSame(800, $dispute->grossAmount);
        self::assertSame(1500, $dispute->disputeFee);
        self::assertSame(-2300, $dispute->netAmount);
        self::assertSame('2026-01-20', $dispute->availableOn->format('Y-m-d'));
    }

    public function testNormalizeDisputeWithNoFeeDetailsKeyDefaultsDisputeFeeToZero(): void
    {
        $dispute = $this->gateway->normalizeDispute([
            'id' => 'dp_2',
            'charge' => 'ch_A',
            'amount' => 500,
            'net' => -500,
            'available_on' => '2026-01-20',
            'created' => '2026-01-20T14:30:00Z',
        ]);

        self::assertSame(0, $dispute->disputeFee);
    }
}
