<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Organisation;
use App\Services\LedgerSeeder;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;

/**
 * Base for the sample data's record fixtures. Each subclass holds one record
 * type as raw Stripe records — deliberately provider-shaped rather than
 * pre-built Payment/Settlement entities, so loading them drives the same
 * gateway → PaymentService → reconciliation path a real ingest would,
 * anomalies and all (see README, "Data anomalies").
 *
 * Dedupes before ingesting, per class: a redelivered record would otherwise
 * be inserted twice within one flush and collide on stripe_id, since the
 * upsert lookup is real SQL and can't see an unflushed insert. No console
 * here to warn on, so anything worth knowing goes to the logger.
 */
abstract class LedgerRecordFixture extends Fixture implements DependentFixtureInterface, LedgerRecordSource
{
    public function __construct(
        protected readonly LedgerSeeder $seeder,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    final public function load(ObjectManager $manager): void
    {
        $organisation = $this->getReference(OrganisationFixtures::ORGANISATION, Organisation::class);
        $deduped = $this->seeder->dedupe($this->records());

        foreach ($deduped->duplicateIds as $id) {
            $this->logger->info('Duplicate record "{id}" collapsed (first occurrence kept).', ['id' => $id]);
        }
        foreach ($deduped->conflictingIds as $id) {
            $this->logger->warning('Duplicate id "{id}" with differing content — keeping first occurrence.', ['id' => $id]);
        }
        foreach ($this->ingest($organisation, $deduped->records) as $warning) {
            $this->logger->warning($warning);
        }
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<string> warnings
     */
    abstract protected function ingest(Organisation $organisation, array $records): array;
}
