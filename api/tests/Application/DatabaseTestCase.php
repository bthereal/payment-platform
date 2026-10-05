<?php

declare(strict_types=1);

namespace App\Tests\Application;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Boots the kernel against the real `app_test` Postgres database (see
 * config/packages/doctrine.yaml, `when@test: dbname_suffix`) and truncates
 * every ledger table before each test, so tests seeding different fixtures
 * never see leftover rows from a previous test's data.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    use ResetsDatabase;

    protected EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->truncateLedgerTables($this->entityManager);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->entityManager->close();
        parent::tearDown();
    }
}
