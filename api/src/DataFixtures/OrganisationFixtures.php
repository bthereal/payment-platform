<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Services\LedgerSeeder;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * The single organisation every sample record belongs to, plus the demo
 * login against it. Not a LedgerRecordSource — the sample extract carries no
 * organisation record of its own; it's implied by the Connect account the
 * extract was taken from.
 */
final class OrganisationFixtures extends Fixture
{
    public const string ORGANISATION = 'organisation';

    public function __construct(private readonly LedgerSeeder $seeder)
    {
    }

    #[\Override]
    public function load(ObjectManager $manager): void
    {
        $this->addReference(self::ORGANISATION, $this->seeder->findOrCreateOrganisation());
    }
}
