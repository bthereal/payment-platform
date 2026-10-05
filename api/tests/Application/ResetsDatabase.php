<?php

declare(strict_types=1);

namespace App\Tests\Application;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared by DatabaseTestCase (KernelTestCase-based) and any WebTestCase-based
 * functional tests, which can't both extend the same base class.
 */
trait ResetsDatabase
{
    private function truncateLedgerTables(EntityManagerInterface $entityManager): void
    {
        $entityManager->getConnection()->executeStatement(
            'TRUNCATE TABLE settlement_payment, payment, settlement, customer, app_user, organisation RESTART IDENTITY CASCADE',
        );
    }
}
