<?php

declare(strict_types=1);

namespace App\Tests\Application\Repository;

use App\Repository\OrganisationRepository;
use App\Repository\SettlementPaymentRepository;
use App\Tests\Application\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A settlement marked PAID with an arrival_date still in the future hasn't
 * actually landed in the organisation's bank account yet — see
 * SettlementPaymentRepository::sumPaidOutToDate()'s own docblock. None of
 * the other fixtures ever exercise this (every arrival_date in them is
 * safely in the past), so this is its own dedicated scenario.
 */
final class SettlementPaymentRepositoryTest extends DatabaseTestCase
{
    public function testASettlementIsExcludedUntilItsArrivalDateHasPassed(): void
    {
        $application = new Application(self::bootKernel());
        $command = $application->find('app:seed-ledger');
        (new CommandTester($command))->execute([
            '--file' => __DIR__ . '/../../Fixtures/settlement-future-arrival.json',
            '--no-interaction' => true,
        ]);

        $organisation = self::getContainer()->get(OrganisationRepository::class)->findFirst();
        self::assertNotNull($organisation);

        $settlementPaymentRepository = self::getContainer()->get(SettlementPaymentRepository::class);

        $beforeArrival = new \DateTimeImmutable('2026-06-01'); // po_FA1's arrival_date is 2099-01-01
        self::assertSame(
            0,
            $settlementPaymentRepository->sumPaidOutToDate($organisation, $beforeArrival),
            'a PAID settlement whose arrival_date is still in the future must not count as paid out yet',
        );

        $afterArrival = new \DateTimeImmutable('2099-06-01');
        self::assertSame(
            920,
            $settlementPaymentRepository->sumPaidOutToDate($organisation, $afterArrival),
            'once arrival_date has passed, the same settlement must count',
        );

        $onArrivalDay = new \DateTimeImmutable('2099-01-01');
        self::assertSame(
            920,
            $settlementPaymentRepository->sumPaidOutToDate($organisation, $onArrivalDay),
            'arrival_date itself counts as arrived, not just strictly after it',
        );
    }
}
