<?php

declare(strict_types=1);

namespace App\Tests\Application\Command;

use App\Entity\Payment;
use App\Enum\PaymentDirection;
use App\Enum\PaymentSource;
use App\Tests\Application\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers app:seed-ledger's dispute ingestion end to end, using its own
 * fixture (dispute.json) rather than the shared records.json — that file's
 * exact totals are asserted by several other tests
 * (SeedLedgerCommandTest/DashboardServiceTest/DashboardControllerTest/etc.),
 * so a dispute record doesn't belong there. dispute.json mirrors the real
 * fixture's dp_0001 shape: a dispute against a charge that was already
 * swept into a payout, same anomaly as re_0004/ch_0007 in
 * the sample data (DisputeFixtures/PayoutFixtures).
 */
final class SeedLedgerCommandDisputeTest extends DatabaseTestCase
{
    private function runSeed(string $fixture): CommandTester
    {
        $application = new Application(self::bootKernel());
        $command = $application->find('app:seed-ledger');
        $tester = new CommandTester($command);
        $tester->execute([
            '--file' => __DIR__ . '/../../Fixtures/' . $fixture,
            '--no-interaction' => true,
        ]);

        return $tester;
    }

    public function testDisputeAfterPayoutStaysLinkedButUnswept(): void
    {
        $this->runSeed('dispute.json');

        $paymentRepository = $this->entityManager->getRepository(Payment::class);
        $dispute = $paymentRepository->findOneBy(['stripeId' => 'dp_D1']);
        $charge = $paymentRepository->findOneBy(['stripeId' => 'ch_D1']);

        self::assertNotNull($dispute);
        self::assertNotNull($charge);
        self::assertSame(PaymentDirection::DEBIT, $dispute->getDirection());
        self::assertSame(PaymentSource::DISPUTE, $dispute->getSource());
        self::assertSame(800, $dispute->getGrossAmount());
        self::assertSame(1500, $dispute->getStripeFee());
        self::assertSame(0, $dispute->getApplicationFee());
        self::assertSame(-2300, $dispute->getNetAmount());
        self::assertTrue($dispute->getRelatedPayment() === $charge, 'dp_D1 should link back to the charge it disputes');

        $connection = $this->entityManager->getConnection();
        self::assertTrue(
            (bool) $connection->fetchOne(
                'SELECT EXISTS(SELECT 1 FROM settlement_payment sp JOIN payment p ON p.id = sp.payment_id WHERE p.stripe_id = :id)',
                ['id' => 'ch_D1'],
            ),
            'ch_D1 was paid out before the dispute happened, so it must stay swept',
        );
        self::assertFalse(
            (bool) $connection->fetchOne(
                'SELECT EXISTS(SELECT 1 FROM settlement_payment sp JOIN payment p ON p.id = sp.payment_id WHERE p.stripe_id = :id)',
                ['id' => 'dp_D1'],
            ),
            'dp_D1 arrived after the payout and covers no settlement — it must stay unswept',
        );

        // ch_D1's own figures must be completely untouched by the dispute.
        self::assertSame(3000, $charge->getGrossAmount());
        self::assertSame(2760, $charge->getNetAmount());
    }

    public function testDisputeReferencingAnUnknownChargeThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/"ch_missing", which was not found/');

        $this->runSeed('dispute-unknown-charge.json');
    }
}
