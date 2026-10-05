<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Organisation;

/**
 * The sample extract's one dispute: dp_0001 against ch_0012, which
 * po_0001 had already paid out — the same clawback-after-payout shape as
 * re_0004/ch_0007, plus a dispute fee that is never reversed.
 */
final class DisputeFixtures extends LedgerRecordFixture
{
    #[\Override]
    public function getDependencies(): array
    {
        return [ChargeFixtures::class];
    }

    #[\Override]
    protected function ingest(Organisation $organisation, array $records): array
    {
        $this->seeder->recordDisputes($organisation, $records);

        return [];
    }

    #[\Override]
    public function records(): array
    {
        return [
            [
                'id' => 'dp_0001',
                'object' => 'dispute',
                'created' => '2026-06-20T14:30:00Z',
                'charge' => 'ch_0012',
                'amount' => 800,
                'currency' => 'gbp',
                'status' => 'needs_response',
                'fee_details' => [
                    ['type' => 'dispute_fee', 'amount' => 1500],
                ],
                'net' => -2300,
                'available_on' => '2026-06-20',
            ],
        ];
    }
}
