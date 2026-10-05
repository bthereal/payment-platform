<?php

declare(strict_types=1);

namespace App\Tests\Application\Services;

use App\Entity\Organisation;
use App\Enum\PaymentDirection;
use App\Enum\PaymentSource;
use App\Repository\CustomerRepository;
use App\Repository\PaymentRepository;
use App\Services\PaymentService;
use App\Tests\Application\DatabaseTestCase;

/**
 * Exercises PaymentService directly — no console command, no JSON file on
 * disk, no Stripe field names anywhere in this file — wired up with
 * FakePaymentGateway instead of StripePaymentGateway. That substitution is
 * the point: it's the concrete proof that a new payment provider really
 * can slot in without PaymentService or the ledger caring.
 */
final class PaymentServiceTest extends DatabaseTestCase
{
    private PaymentService $paymentService;

    private Organisation $organisation;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentService = new PaymentService(
            $this->entityManager,
            self::getContainer()->get(PaymentRepository::class),
            self::getContainer()->get(CustomerRepository::class),
            new FakePaymentGateway(),
        );

        $this->organisation = new Organisation();
        $this->organisation->setName('Test Org');
        $this->entityManager->persist($this->organisation);
        $this->entityManager->flush();
    }

    public function testRecordChargeCreatesACreditSalePayment(): void
    {
        $payment = $this->paymentService->recordCharge($this->organisation, [
            'id' => 'fake_charge_1',
            'customer' => 'order-1',
            'gross' => 1000,
            'fee' => 30,
            'appFee' => 50,
            'net' => 920,
            'availableOn' => '2026-01-10',
            'createdAt' => '2026-01-01T10:00:00Z',
        ]);
        $this->entityManager->flush();

        self::assertSame('fake_charge_1', $payment->getStripeId());
        self::assertSame(PaymentDirection::CREDIT, $payment->getDirection());
        self::assertSame(PaymentSource::SALE, $payment->getSource());
        self::assertSame(1000, $payment->getGrossAmount());
        self::assertSame(30, $payment->getStripeFee());
        self::assertSame(50, $payment->getApplicationFee());
        self::assertSame(920, $payment->getNetAmount());
        self::assertSame('2026-01-10', $payment->getAvailableOn()->format('Y-m-d'));

        $customer = $payment->getCustomer();
        self::assertNotNull($customer);
        self::assertSame('order-1', $customer->getOrderRef());
        self::assertTrue($customer->isSynthetic());
    }

    public function testRecordChargeWithNoCustomerReferenceLeavesCustomerNull(): void
    {
        $payment = $this->paymentService->recordCharge($this->organisation, [
            'id' => 'fake_charge_anonymous',
            'customer' => null,
            'gross' => 500,
            'fee' => 10,
            'appFee' => 20,
            'net' => 470,
            'availableOn' => '2026-01-10',
            'createdAt' => '2026-01-01T10:00:00Z',
        ]);

        self::assertNull($payment->getCustomer());
        self::assertNull($payment->getOrderRef());
    }

    public function testRecordChargeUpsertsByProviderIdAndReusesTheCustomer(): void
    {
        $first = $this->paymentService->recordCharge($this->organisation, [
            'id' => 'fake_charge_2',
            'customer' => 'order-2',
            'gross' => 500,
            'fee' => 10,
            'appFee' => 20,
            'net' => 470,
            'availableOn' => '2026-01-10',
            'createdAt' => '2026-01-01T10:00:00Z',
        ]);
        $this->entityManager->flush();
        $firstId = $first->getId();

        // A rerun of the same provider id, with different amounts, must
        // update that same row rather than create a second one.
        $second = $this->paymentService->recordCharge($this->organisation, [
            'id' => 'fake_charge_2',
            'customer' => 'order-2',
            'gross' => 999,
            'fee' => 10,
            'appFee' => 20,
            'net' => 969,
            'availableOn' => '2026-01-10',
            'createdAt' => '2026-01-01T10:00:00Z',
        ]);
        $this->entityManager->flush();

        self::assertTrue($firstId->equals($second->getId()));
        self::assertSame(999, $second->getGrossAmount());
        self::assertSame($first->getCustomer(), $second->getCustomer(), 'the same order-ref must resolve to the same Customer, not a duplicate');
    }

    public function testRecordRefundCreatesADebitRefundPaymentLinkedToItsCharge(): void
    {
        $charge = $this->paymentService->recordCharge($this->organisation, [
            'id' => 'fake_charge_3',
            'customer' => 'order-3',
            'gross' => 2000,
            'fee' => 60,
            'appFee' => 100,
            'net' => 1840,
            'availableOn' => '2026-01-10',
            'createdAt' => '2026-01-01T10:00:00Z',
        ]);
        $this->entityManager->flush();

        $refund = $this->paymentService->recordRefund($this->organisation, [
            'id' => 'fake_refund_3',
            'relatedChargeId' => 'fake_charge_3',
            'gross' => 2000,
            'appFee' => -100,
            'net' => -1840,
            'availableOn' => '2026-01-15',
            'createdAt' => '2026-01-14T12:00:00Z',
        ]);
        $this->entityManager->flush();

        self::assertSame(PaymentDirection::DEBIT, $refund->getDirection());
        self::assertSame(PaymentSource::REFUND, $refund->getSource());
        self::assertSame(0, $refund->getStripeFee(), 'the processor fee is never reversed');
        self::assertSame(-1840, $refund->getNetAmount());
        self::assertSame($charge->getCustomer(), $refund->getCustomer());
        self::assertSame($charge, $refund->getRelatedPayment());

        // The original charge row must never be mutated by the refund.
        self::assertSame(2000, $charge->getGrossAmount());
        self::assertSame(1840, $charge->getNetAmount());
    }

    public function testRecordRefundRejectsAReferenceToAnUnknownCharge(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->paymentService->recordRefund($this->organisation, [
            'id' => 'fake_refund_orphan',
            'relatedChargeId' => 'does_not_exist',
            'gross' => 100,
            'appFee' => 0,
            'net' => -100,
            'availableOn' => '2026-01-15',
            'createdAt' => '2026-01-14T12:00:00Z',
        ]);
    }

    public function testRecordDisputeCreatesADebitDisputePaymentLinkedToItsCharge(): void
    {
        $charge = $this->paymentService->recordCharge($this->organisation, [
            'id' => 'fake_charge_4',
            'customer' => 'order-4',
            'gross' => 2000,
            'fee' => 60,
            'appFee' => 100,
            'net' => 1840,
            'availableOn' => '2026-01-10',
            'createdAt' => '2026-01-01T10:00:00Z',
        ]);
        $this->entityManager->flush();

        $dispute = $this->paymentService->recordDispute($this->organisation, [
            'id' => 'fake_dispute_4',
            'relatedChargeId' => 'fake_charge_4',
            'gross' => 800,
            'fee' => 1500,
            'net' => -2300,
            'availableOn' => '2026-01-20',
            'createdAt' => '2026-01-20T14:30:00Z',
        ]);
        $this->entityManager->flush();

        self::assertSame(PaymentDirection::DEBIT, $dispute->getDirection());
        self::assertSame(PaymentSource::DISPUTE, $dispute->getSource());
        self::assertSame(800, $dispute->getGrossAmount());
        self::assertSame(1500, $dispute->getStripeFee(), 'the dispute fee is not zeroed out, unlike a refund');
        self::assertSame(0, $dispute->getApplicationFee());
        self::assertSame(-2300, $dispute->getNetAmount());
        self::assertSame($charge->getCustomer(), $dispute->getCustomer());
        self::assertSame($charge, $dispute->getRelatedPayment());

        // The original charge row must never be mutated by the dispute.
        self::assertSame(2000, $charge->getGrossAmount());
        self::assertSame(1840, $charge->getNetAmount());
    }

    public function testRecordDisputeRejectsAReferenceToAnUnknownCharge(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->paymentService->recordDispute($this->organisation, [
            'id' => 'fake_dispute_orphan',
            'relatedChargeId' => 'does_not_exist',
            'gross' => 100,
            'fee' => 0,
            'net' => -100,
            'availableOn' => '2026-01-15',
            'createdAt' => '2026-01-14T12:00:00Z',
        ]);
    }
}
