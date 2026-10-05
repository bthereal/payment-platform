<?php

declare(strict_types=1);

namespace App\Tests\Application\Command;

use App\Entity\Payment;
use App\Tests\Application\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Seeds the real sample data (the App\DataFixtures record fixtures) and
 * asserts against totals hand-verified with SQL — catches silent
 * aggregation regressions in the seed pipeline itself.
 *
 * Run once per entry point: app:seed-ledger and doctrine:fixtures:load both
 * drive the same LedgerSeeder, and must land the same ledger. A drift
 * between them (say, one stops deduping) fails here rather than surfacing
 * as two different dashboards depending on how the database was seeded.
 */
final class SeedLedgerCommandRealFixtureTest extends DatabaseTestCase
{
    /** @return iterable<string, array{string}> */
    public static function entryPoints(): iterable
    {
        yield 'app:seed-ledger' => ['app:seed-ledger'];
        yield 'doctrine:fixtures:load' => ['doctrine:fixtures:load'];
    }

    #[DataProvider('entryPoints')]
    public function testRealFixtureSeedsToHandVerifiedTotals(string $commandName): void
    {
        $application = new Application(self::bootKernel());
        $command = $application->find($commandName);
        $tester = new CommandTester($command);
        // Non-interactive so doctrine:fixtures:load skips its "purge the
        // database?" confirmation.
        $tester->execute([], ['interactive' => false]);

        self::assertSame(0, $tester->getStatusCode());

        $payments = $this->entityManager->getRepository(Payment::class)->findAll();
        self::assertCount(65, $payments, '60 charges + 4 refunds + 1 dispute, with the ch_0005/re_0002 duplicates collapsed');

        $connection = $this->entityManager->getConnection();

        self::assertSame(116250, (int) $connection->fetchOne(
            "SELECT COALESCE(SUM(gross_amount), 0) FROM payment WHERE direction = 'CREDIT'",
        ));
        self::assertSame(2831, (int) $connection->fetchOne(
            "SELECT COALESCE(SUM(stripe_fee), 0) FROM payment WHERE direction = 'CREDIT'",
        ));
        self::assertSame(3805, (int) $connection->fetchOne(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'CREDIT' THEN application_fee ELSE -application_fee END), 0) FROM payment",
        ), 'net platform fee: taken on sales minus reversed on refunds');
        self::assertSame(11550, (int) $connection->fetchOne(
            "SELECT COALESCE(SUM(gross_amount), 0) FROM payment WHERE direction = 'DEBIT'",
        ), '10750 refunded + 800 disputed (dp_0001 against ch_0012)');
        self::assertSame(96564, (int) $connection->fetchOne('SELECT COALESCE(SUM(net_amount), 0) FROM payment'), '98864 minus dp_0001\'s -2300 net');

        // po_0002 sweeps everything po_0001 left behind — every charge past
        // po_0001's cutoff, all four refunds, and dp_0001 — including the
        // refund-after-payout and dispute-after-payout clawbacks
        // (re_0004/ch_0007, dp_0001/ch_0012) that po_0001 alone left
        // permanently unswept. So paidOutToDate now equals netEarned exactly
        // and nothing is left pending.
        self::assertSame(96564, (int) $connection->fetchOne(
            <<<'SQL'
                SELECT COALESCE(SUM(p.net_amount), 0)
                FROM settlement s
                JOIN settlement_payment sp ON sp.settlement_id = s.id
                JOIN payment p ON p.id = sp.payment_id
                WHERE s.status = 'PAID'
                SQL,
        ), 'po_0001 (44536) + po_0002 (52028), which together sweep every payment');

        self::assertSame(0, (int) $connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM payment p
                WHERE NOT EXISTS (SELECT 1 FROM settlement_payment sp WHERE sp.payment_id = p.id)
                SQL,
        ), 'po_0002 leaves nothing still to come');
    }
}
