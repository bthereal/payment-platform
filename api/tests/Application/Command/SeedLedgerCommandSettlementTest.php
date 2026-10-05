<?php

declare(strict_types=1);

namespace App\Tests\Application\Command;

use App\Entity\Payment;
use App\Repository\SettlementPaymentRepository;
use App\Repository\SettlementRepository;
use App\Tests\Application\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers app:seed-ledger's settlement-reconciliation edge cases — none of
 * which appear in the real fixture data, so SeedLedgerCommandTest and
 * SeedLedgerCommandRealFixtureTest never exercise them: a settlement whose
 * declared total doesn't match what it actually swept, a payment that a
 * later payout tries to sweep a second time, and a payout referencing a
 * payment that was never ingested.
 */
final class SeedLedgerCommandSettlementTest extends DatabaseTestCase
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

    /**
     * SymfonyStyle word-wraps warning() blocks to the terminal width, which
     * can split a long message across lines with padding in between —
     * collapse whitespace so assertions can match the message as one line
     * regardless of wrapping.
     */
    private function normalizedDisplay(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }

    public function testSettlementTotalMismatchLogsAWarningButStillSeeds(): void
    {
        $tester = $this->runSeed('settlement-warnings.json');

        self::assertSame(0, $tester->getStatusCode(), 'a total mismatch is a warning, not a failure');
        self::assertStringContainsString('reports total 999 but its swept payments sum to 920.', $this->normalizedDisplay($tester));

        $settlement = self::getContainer()->get(SettlementRepository::class)->findOneByStripeId('po_M1');
        self::assertNotNull($settlement, 'the settlement is still persisted despite the mismatch');
        self::assertSame(999, $settlement->getTotalAmount());
    }

    public function testPayoutAlreadySweptByAnotherSettlementIsNotReLinked(): void
    {
        $tester = $this->runSeed('settlement-warnings.json');

        self::assertStringContainsString('is already swept by settlement "po_S1" — not re-linking it to "po_S2".', $this->normalizedDisplay($tester));

        $payment = self::getContainer()->get(SettlementPaymentRepository::class);
        $chargeS1 = $this->entityManager->getRepository(Payment::class)->findOneBy(['stripeId' => 'ch_S1']);
        self::assertNotNull($chargeS1);

        $link = $payment->findOneByPayment($chargeS1);
        self::assertNotNull($link);
        self::assertSame('po_S1', $link->getSettlement()->getStripeId(), 'the first settlement keeps the link; the second must not steal it');
    }

    public function testPayoutReferencingAnUnknownPaymentThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/"ch_does_not_exist", which was not found/');

        $this->runSeed('settlement-unknown-payment.json');
    }
}
