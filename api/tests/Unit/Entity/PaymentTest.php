<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Customer;
use App\Entity\Organisation;
use App\Entity\Payment;
use App\Enum\PaymentDirection;
use App\Enum\PaymentSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

/**
 * Payment has no real behaviour of its own — it's a plain ledger row — so
 * what's worth a unit test is that the constructor's one piece of real
 * logic (self-generating an id) works, and that every setter/getter pair
 * round-trips faithfully. Values are drawn from tests/Fixtures/records.json
 * (ch_A) rather than invented numbers, so this test reads the same "shape"
 * of charge the rest of the suite already exercises.
 */
final class PaymentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function loadFixtureRecord(string $id): array
    {
        $path = __DIR__ . '/../../Fixtures/records.json';
        $contents = file_get_contents($path);
        $records = json_decode($contents !== false ? $contents : '', true, flags: \JSON_THROW_ON_ERROR);

        foreach ($records as $record) {
            if ($record['id'] === $id) {
                return $record;
            }
        }

        throw new \RuntimeException(\sprintf('Fixture record "%s" not found in %s.', $id, $path));
    }

    public function testConstructorGeneratesAUlid(): void
    {
        $payment = new Payment();

        self::assertInstanceOf(Ulid::class, $payment->getId());
    }

    public function testTwoNewPaymentsGetDifferentIds(): void
    {
        self::assertFalse((new Payment())->getId()->equals((new Payment())->getId()));
    }

    public function testSettersRoundTripUsingRealFixtureData(): void
    {
        $chargeA = $this->loadFixtureRecord('ch_A');

        $organisation = new Organisation();
        $organisation->setName('Fixture Organisation');

        $customer = new Customer();
        $customer->setOrganisation($organisation);
        $customer->setOrderRef($chargeA['order_ref']);
        $customer->setSynthetic(true);

        $stripeFee = $this->feeAmount($chargeA, 'stripe_fee');
        $applicationFee = $this->feeAmount($chargeA, 'application_fee');

        $payment = new Payment();
        $payment->setOrganisation($organisation);
        $payment->setCustomer($customer);
        $payment->setStripeId($chargeA['id']);
        $payment->setDirection(PaymentDirection::CREDIT);
        $payment->setSource(PaymentSource::SALE);
        $payment->setGrossAmount($chargeA['amount']);
        $payment->setStripeFee($stripeFee);
        $payment->setApplicationFee($applicationFee);
        $payment->setNetAmount($chargeA['net']);
        $payment->setAvailableOn(new \DateTimeImmutable($chargeA['available_on']));
        $payment->setCreatedAt(new \DateTimeImmutable($chargeA['created']));
        $payment->setOrderRef($chargeA['order_ref']);

        self::assertSame('ch_A', $payment->getStripeId());
        self::assertSame($organisation, $payment->getOrganisation());
        self::assertSame($customer, $payment->getCustomer());
        self::assertSame(PaymentDirection::CREDIT, $payment->getDirection());
        self::assertSame(PaymentSource::SALE, $payment->getSource());
        self::assertSame(1000, $payment->getGrossAmount());
        self::assertSame(30, $payment->getStripeFee());
        self::assertSame(50, $payment->getApplicationFee());
        self::assertSame(920, $payment->getNetAmount());
        self::assertSame('2026-01-10', $payment->getAvailableOn()->format('Y-m-d'));
        self::assertSame('T-1', $payment->getOrderRef());
        self::assertNull($payment->getRelatedPayment());
    }

    public function testRefundLinksBackToTheChargeItReverses(): void
    {
        $charge = new Payment();
        $refund = new Payment();
        $refund->setRelatedPayment($charge);

        self::assertSame($charge, $refund->getRelatedPayment());
    }

    public function testCustomerAndRelatedPaymentDefaultToNull(): void
    {
        $payment = new Payment();

        self::assertNull($payment->getCustomer());
        self::assertNull($payment->getRelatedPayment());
        self::assertNull($payment->getOrderRef());
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
