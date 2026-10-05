<?php

declare(strict_types=1);

namespace App\Command;

use App\DataFixtures\LedgerRecordSource;
use App\Services\LedgerSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Seeds the Organisation/Customer/Payment/Settlement ledger from the sample
 * data held in the App\DataFixtures record fixtures (or, with --file, from a
 * JSON export of raw Stripe-style records — the shape the test suite's
 * scenario fixtures use).
 *
 * The non-destructive counterpart to `doctrine:fixtures:load`: both feed the
 * same records through the same LedgerSeeder, but this one never purges.
 * It upserts by `stripeId` inside a single transaction, so it's safe to
 * rerun on every dev boot (docker/entrypoint.sh does), and it reports what
 * it found — per-type counts, every duplicate id collapsed, reconciliation
 * warnings — with a --dry-run to see that without writing anything.
 *
 * This command only orchestrates: collect raw records, dedupe, group by
 * type, and drive LedgerSeeder in dependency order.
 */
#[AsCommand(name: 'app:seed-ledger', description: 'Seed the payments ledger from the sample data fixtures (non-destructive, idempotent).')]
final class SeedLedgerCommand extends Command
{
    /** @param iterable<LedgerRecordSource> $fixtures */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LedgerSeeder $seeder,
        #[AutowireIterator(LedgerRecordSource::class)]
        private readonly iterable $fixtures,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Seed from a JSON export of raw records instead of the sample data fixtures')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and dedupe without writing to the database');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filePath = $input->getOption('file');
        if ($filePath !== null) {
            if (!is_file($filePath)) {
                $io->error(sprintf('Fixture file not found: %s', $filePath));

                return Command::FAILURE;
            }

            $contents = file_get_contents($filePath);
            $raw = json_decode($contents !== false ? $contents : '', true, flags: JSON_THROW_ON_ERROR);
        } else {
            $raw = [];
            foreach ($this->fixtures as $fixture) {
                array_push($raw, ...$fixture->records());
            }
        }

        // Dedupe across every source before any database interaction — see
        // LedgerSeeder::dedupe() for why ids are named, not just counted.
        $deduped = $this->seeder->dedupe($raw);
        foreach ($deduped->conflictingIds as $id) {
            $io->warning(sprintf('Duplicate id "%s" with differing content — keeping first occurrence.', $id));
        }

        $charges = [];
        $refunds = [];
        $disputes = [];
        $payouts = [];
        $unknown = 0;
        foreach ($deduped->records as $record) {
            match ($record['object'] ?? null) {
                'charge' => $charges[] = $record,
                'refund' => $refunds[] = $record,
                'dispute' => $disputes[] = $record,
                'payout' => $payouts[] = $record,
                default => $unknown++,
            };
        }

        if ($unknown > 0) {
            $io->warning(sprintf('Skipped %d record(s) with an unrecognised "object" type.', $unknown));
        }

        $io->table(
            ['Type', 'Count'],
            [
                ['charges', \count($charges)],
                ['refunds', \count($refunds)],
                ['disputes', \count($disputes)],
                ['payouts', \count($payouts)],
                ['duplicate records collapsed', \count($deduped->duplicateIds)],
                ['unrecognised (skipped)', $unknown],
            ],
        );

        if ($deduped->duplicateIds !== []) {
            $io->text('Duplicate ids collapsed (first occurrence kept):');
            $io->listing($deduped->duplicateIds);
        }

        if ($input->getOption('dry-run')) {
            $io->success('Dry run complete — no changes written.');

            return Command::SUCCESS;
        }

        $warnings = $this->entityManager->wrapInTransaction(function () use ($charges, $refunds, $disputes, $payouts): array {
            $organisation = $this->seeder->findOrCreateOrganisation();

            // Ordered passes: refunds, disputes and payouts reference charges
            // that may appear later in delivery order, so charges must be
            // ingested first. Disputes follow refunds rather than being
            // combined with them so the summary table above keeps each
            // record type's count distinct.
            $this->seeder->recordCharges($organisation, $charges);
            $this->seeder->recordRefunds($organisation, $refunds);
            $this->seeder->recordDisputes($organisation, $disputes);

            return $this->seeder->recordPayouts($organisation, $payouts);
        });

        foreach ($warnings as $warning) {
            $io->warning($warning);
        }

        $io->success('Ledger seeded.');

        return Command::SUCCESS;
    }
}
