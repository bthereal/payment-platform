<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Result of LedgerSeeder::dedupe() — the surviving records plus enough
 * detail for the caller to report exactly which ids collapsed.
 */
final readonly class DedupedRecords
{
    /**
     * @param list<array<string, mixed>> $records first occurrence of each id, in input order
     * @param list<string> $duplicateIds one entry per collapsed occurrence
     * @param list<string> $conflictingIds one entry per collapsed occurrence whose content differed from the kept one
     */
    public function __construct(
        public array $records,
        public array $duplicateIds,
        public array $conflictingIds,
    ) {
    }
}
