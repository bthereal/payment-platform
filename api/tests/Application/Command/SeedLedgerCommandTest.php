<?php

declare(strict_types=1);

namespace App\Tests\Application\Command;

use App\Entity\Payment;
use App\Enum\PaymentDirection;
use App\Tests\Application\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exercises app:seed-ledger against a small, hand-built fixture
 * (tests/Fixtures/records.json) covering the three edge cases identified in
 * README.md: a duplicate record, a charge left unswept despite sharing its
 * available_on date with a swept batch, and a refund landing after its
 * charge was already paid out.
 */
final class SeedLedgerCommandTest extends DatabaseTestCase
{
    private function runSeed(): CommandTester
    {
        $application = new Application(self::bootKernel());
        $command = $application->find('app:seed-ledger');
        $tester = new CommandTester($command);
        $tester->execute([
            '--file' => __DIR__ . '/../../Fixtures/records.json',
            '--no-interaction' => true,
        ]);

        return $tester;
    }

    public function testDuplicateRecordsAreCollapsed(): void
    {
        $this->runSeed();

        $payments = $this->entityManager->getRepository(Payment::class)->findAll();
        $chargeA = array_values(array_filter($payments, static fn (Payment $p) => $p->getStripeId() === 'ch_A'));

        self::assertCount(1, $chargeA, 'the duplicate ch_A record should collapse to a single row');
        self::assertSame(1000, $chargeA[0]->getGrossAmount());
    }

    public function testChargeSharingAnAvailableOnDateWithASweptBatchButNotIncludedStaysUnswept(): void
    {
        $this->runSeed();

        $connection = $this->entityManager->getConnection();
        $swept = $connection->fetchOne(
            'SELECT EXISTS(SELECT 1 FROM settlement_payment sp JOIN payment p ON p.id = sp.payment_id WHERE p.stripe_id = :id)',
            ['id' => 'ch_C'],
        );

        self::assertFalse((bool) $swept, 'ch_C shares available_on with the swept ch_B but was never included in the payout, so it must stay unswept');
    }

    public function testRefundAfterPayoutStaysLinkedButUnswept(): void
    {
        $this->runSeed();

        $paymentRepository = $this->entityManager->getRepository(Payment::class);
        $refund = $paymentRepository->findOneBy(['stripeId' => 're_1']);
        $charge = $paymentRepository->findOneBy(['stripeId' => 'ch_B']);

        self::assertNotNull($refund);
        self::assertNotNull($charge);
        self::assertSame(PaymentDirection::DEBIT, $refund->getDirection());
        self::assertSame(-1840, $refund->getNetAmount());
        self::assertTrue($refund->getRelatedPayment() === $charge, 're_1 should link back to the charge it refunds');

        $connection = $this->entityManager->getConnection();
        self::assertTrue(
            (bool) $connection->fetchOne(
                'SELECT EXISTS(SELECT 1 FROM settlement_payment sp JOIN payment p ON p.id = sp.payment_id WHERE p.stripe_id = :id)',
                ['id' => 'ch_B'],
            ),
            'ch_B was paid out before the refund happened, so it must stay swept',
        );
        self::assertFalse(
            (bool) $connection->fetchOne(
                'SELECT EXISTS(SELECT 1 FROM settlement_payment sp JOIN payment p ON p.id = sp.payment_id WHERE p.stripe_id = :id)',
                ['id' => 're_1'],
            ),
            're_1 arrived after the payout and covers no settlement — it must stay unswept',
        );
    }

    public function testRerunningTheSeedIsIdempotent(): void
    {
        $this->runSeed();
        $firstRunCount = count($this->entityManager->getRepository(Payment::class)->findAll());

        $this->entityManager->clear();
        $this->runSeed();
        $secondRunCount = count($this->entityManager->getRepository(Payment::class)->findAll());

        self::assertSame($firstRunCount, $secondRunCount);
        self::assertSame(4, $secondRunCount, '3 charges (ch_A, ch_B, ch_C) + 1 refund (re_1), ch_A duplicate collapsed');
    }
}
