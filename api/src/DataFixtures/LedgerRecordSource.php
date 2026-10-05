<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A fixture that holds sample data as raw provider records. Tagged so
 * app:seed-ledger can collect every source's records and run them through
 * its own dedupe/summary/dry-run flow, without going via
 * doctrine:fixtures:load (which purges the database first).
 */
#[AutoconfigureTag]
interface LedgerRecordSource
{
    /** @return list<array<string, mixed>> */
    public function records(): array;
}
