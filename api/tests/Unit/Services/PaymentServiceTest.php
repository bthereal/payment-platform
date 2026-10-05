<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services;

use App\Entity\Customer;
use App\Entity\Organisation;
use App\Entity\Payment;
use App\Enum\PaymentDirection;
use App\Enum\PaymentSource;
use App\Repository\CustomerRepository;
use App\Repository\PaymentRepository;
use App\Services\PaymentGateway\StripePaymentGateway;
use App\Services\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * PaymentService with every collaborator mocked (no database, no kernel) —
 * the counterpart to App\Tests\Application\Services\PaymentServiceTest,
 * which proves the same logic persists correctly against a real Postgres
 * database using FakePaymentGateway. This test uses the real
 * StripePaymentGateway (itself a pure, dependency-free unit — see
 * PaymentGateway\StripePaymentGatewayTest) fed with actual records from
 * tests/Fixtures/records.json, so the input data is the same real scenario
 * the rest of the suite is built around, not invented values.
 */
final class PaymentServiceTest extends TestCase
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

    public function testRecordChargeBuildsANewCreditSalePaymentFromAFixtureCharge(): void
    {
        $chargeA = $this->loadFixtureRecord('ch_A'); // amount 1000, net 920, stripe_fee 30, application_fee 50, order_ref T-1
        $organisation = new Organisation();

        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('findOneByStripeId')->willReturn(null);

        $customerRepository = $this->createStub(CustomerRepository::class);
        $customerRepository->method('findOneByOrderRef')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('persist'); // the new Customer, then the new Payment

        $service = new PaymentService($entityManager, $paymentRepository, $customerRepository, new StripePaymentGateway());

        $payment = $service->recordCharge($organisation, $chargeA);

        self::assertSame('ch_A', $payment->getStripeId());
        self::assertSame($organisation, $payment->getOrganisation());
        self::assertSame(PaymentDirection::CREDIT, $payment->getDirection());
        self::assertSame(PaymentSource::SALE, $payment->getSource());
        self::assertSame(1000, $payment->getGrossAmount());
        self::assertSame(30, $payment->getStripeFee());
        self::assertSame(50, $payment->getApplicationFee());
        self::assertSame(920, $payment->getNetAmount());
        self::assertSame('2026-01-10', $payment->getAvailableOn()->format('Y-m-d'));
        self::assertNotNull($payment->getCustomer());
        self::assertSame('T-1', $payment->getCustomer()->getOrderRef());
        self::assertTrue($payment->getCustomer()->isSynthetic());
    }

    public function testRecordChargeUpsertsAnExistingPaymentInsteadOfCreatingASecondRow(): void
    {
        $chargeA = $this->loadFixtureRecord('ch_A');
        $organisation = new Organisation();
        $existingCustomer = new Customer();
        $existingCustomer->setOrderRef('T-1');
        $existingPayment = new Payment();
        $existingPayment->setStripeId('ch_A');

        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('findOneByStripeId')->willReturn($existingPayment);

        $customerRepository = $this->createStub(CustomerRepository::class);
        $customerRepository->method('findOneByOrderRef')->willReturn($existingCustomer);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist'); // both already exist — nothing new to persist

        $service = new PaymentService($entityManager, $paymentRepository, $customerRepository, new StripePaymentGateway());

        $payment = $service->recordCharge($organisation, $chargeA);

        self::assertSame($existingPayment, $payment, 'the same row must be reused, not replaced, on a rerun');
        self::assertSame($existingCustomer, $payment->getCustomer());
        self::assertSame(1000, $payment->getGrossAmount());
    }

    public function testRecordRefundLinksToTheChargeItReversesUsingFixtureData(): void
    {
        // ch_B (amount 2000, net 1840) is refunded in full by re_1 (available_on 2026-01-15).
        $chargeB = $this->loadFixtureRecord('ch_B');
        $refundRecord = $this->loadFixtureRecord('re_1');
        $organisation = new Organisation();

        $existingCharge = new Payment();
        $existingCharge->setStripeId('ch_B');
        $existingCharge->setDirection(PaymentDirection::CREDIT);
        $existingCharge->setSource(PaymentSource::SALE);
        $existingCharge->setGrossAmount($chargeB['amount']);
        $existingCharge->setNetAmount($chargeB['net']);
        $chargeCustomer = new Customer();
        $chargeCustomer->setOrderRef('T-2');
        $existingCharge->setCustomer($chargeCustomer);

        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('findOneByStripeId')->willReturnMap([
            ['ch_B', $existingCharge],
            ['re_1', null],
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist'); // the new refund Payment row

        $service = new PaymentService(
            $entityManager,
            $paymentRepository,
            $this->createStub(CustomerRepository::class),
            new StripePaymentGateway(),
        );

        $refund = $service->recordRefund($organisation, $refundRecord);

        self::assertSame('re_1', $refund->getStripeId());
        self::assertSame(PaymentDirection::DEBIT, $refund->getDirection());
        self::assertSame(PaymentSource::REFUND, $refund->getSource());
        self::assertSame(-1840, $refund->getNetAmount());
        self::assertSame(0, $refund->getStripeFee(), "the processor's own fee is never reversed");
        self::assertSame($existingCharge, $refund->getRelatedPayment());
        self::assertSame($chargeCustomer, $refund->getCustomer(), 'a refund is attributed to the same customer as the charge it reverses');

        // The original charge row itself must never be mutated by the refund.
        self::assertSame($chargeB['amount'], $existingCharge->getGrossAmount());
        self::assertSame($chargeB['net'], $existingCharge->getNetAmount());
    }

    /**
     * Nothing in recordRefund() ever compares a refund's amount against its
     * charge's amount — a refund is just its own row, whatever it says.
     * That's correct (Stripe genuinely allows partial refunds), but it was
     * never actually tested anywhere until this: the real fixture's
     * re_0003 refunds only £22.50 of ch_0050's £45.00 charge
     * (RefundFixtures/ChargeFixtures — found during a full data-integrity
     * sweep of the real fixture, not previously identified). Values below
     * are copied from those two real records, not invented.
     */
    public function testRecordRefundHandlesAPartialRefundCorrectly(): void
    {
        $organisation = new Organisation();

        $existingCharge = new Payment();
        $existingCharge->setStripeId('ch_0050');
        $existingCharge->setDirection(PaymentDirection::CREDIT);
        $existingCharge->setSource(PaymentSource::SALE);
        $existingCharge->setGrossAmount(4500);
        $existingCharge->setStripeFee(83);
        $existingCharge->setApplicationFee(120);
        $existingCharge->setNetAmount(4297);

        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('findOneByStripeId')->willReturnMap([
            ['ch_0050', $existingCharge],
            ['re_0003', null],
        ]);

        $service = new PaymentService(
            $this->createStub(EntityManagerInterface::class),
            $paymentRepository,
            $this->createStub(CustomerRepository::class),
            new StripePaymentGateway(),
        );

        $refund = $service->recordRefund($organisation, [
            'id' => 're_0003',
            'object' => 'refund',
            'created' => '2026-06-16T09:05:00Z',
            'charge' => 'ch_0050',
            'amount' => 2250,
            'currency' => 'gbp',
            'fee_details' => [
                ['type' => 'application_fee', 'amount' => -60],
            ],
            'net' => -2190,
            'available_on' => '2026-06-17',
        ]);

        self::assertSame(2250, $refund->getGrossAmount(), 'a partial refund keeps its own (smaller) amount, never the charge\'s');
        self::assertSame(-2190, $refund->getNetAmount());
        self::assertSame(60, $refund->getApplicationFee());
        self::assertSame(0, $refund->getStripeFee());
        self::assertSame($existingCharge, $refund->getRelatedPayment());

        // The charge must be completely untouched — still the FULL original
        // amount, not reduced by the partial refund.
        self::assertSame(4500, $existingCharge->getGrossAmount());
        self::assertSame(4297, $existingCharge->getNetAmount());
    }

    public function testRecordDisputeLinksToTheChargeItDisputesAndMapsTheDisputeFeeToStripeFee(): void
    {
        $organisation = new Organisation();

        $existingCharge = new Payment();
        $existingCharge->setStripeId('ch_0012');
        $existingCharge->setDirection(PaymentDirection::CREDIT);
        $existingCharge->setSource(PaymentSource::SALE);
        $existingCharge->setGrossAmount(2000);
        $existingCharge->setNetAmount(1840);
        $chargeCustomer = new Customer();
        $chargeCustomer->setOrderRef('T-9');
        $existingCharge->setCustomer($chargeCustomer);

        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('findOneByStripeId')->willReturnMap([
            ['ch_0012', $existingCharge],
            ['dp_0001', null],
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist'); // the new dispute Payment row

        $service = new PaymentService(
            $entityManager,
            $paymentRepository,
            $this->createStub(CustomerRepository::class),
            new StripePaymentGateway(),
        );

        $dispute = $service->recordDispute($organisation, [
            'id' => 'dp_0001',
            'charge' => 'ch_0012',
            'amount' => 800,
            'fee_details' => [
                ['type' => 'dispute_fee', 'amount' => 1500],
            ],
            'net' => -2300,
            'available_on' => '2026-06-20',
            'created' => '2026-06-20T14:30:00Z',
        ]);

        self::assertSame('dp_0001', $dispute->getStripeId());
        self::assertSame(PaymentDirection::DEBIT, $dispute->getDirection());
        self::assertSame(PaymentSource::DISPUTE, $dispute->getSource());
        self::assertSame(800, $dispute->getGrossAmount());
        self::assertSame(1500, $dispute->getStripeFee(), 'the dispute_fee is the whole point of the record, unlike a refund it is not zeroed out');
        self::assertSame(0, $dispute->getApplicationFee());
        self::assertSame(-2300, $dispute->getNetAmount());
        self::assertSame($existingCharge, $dispute->getRelatedPayment());
        self::assertSame($chargeCustomer, $dispute->getCustomer(), 'a dispute is attributed to the same customer as the charge it disputes');

        // The original charge row itself must never be mutated by the dispute.
        self::assertSame(2000, $existingCharge->getGrossAmount());
        self::assertSame(1840, $existingCharge->getNetAmount());
    }

    public function testRecordDisputeThrowsWhenItsChargeCannotBeFound(): void
    {
        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('findOneByStripeId')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $service = new PaymentService(
            $entityManager,
            $paymentRepository,
            $this->createStub(CustomerRepository::class),
            new StripePaymentGateway(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/"ch_missing", which was not found/');

        $service->recordDispute(new Organisation(), [
            'id' => 'dp_orphan',
            'charge' => 'ch_missing',
            'amount' => 100,
            'fee_details' => [],
            'net' => -100,
            'available_on' => '2026-01-15',
            'created' => '2026-01-14T12:00:00Z',
        ]);
    }

    public function testRecordRefundThrowsWhenItsChargeCannotBeFound(): void
    {
        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('findOneByStripeId')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $service = new PaymentService(
            $entityManager,
            $paymentRepository,
            $this->createStub(CustomerRepository::class),
            new StripePaymentGateway(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/"ch_missing", which was not found/');

        $service->recordRefund(new Organisation(), [
            'id' => 're_orphan',
            'charge' => 'ch_missing',
            'amount' => 100,
            'fee_details' => [],
            'net' => -100,
            'available_on' => '2026-01-15',
            'created' => '2026-01-14T12:00:00Z',
        ]);
    }
}
