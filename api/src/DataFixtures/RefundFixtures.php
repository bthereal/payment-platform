<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Organisation;

/**
 * The sample extract's refunds, in delivery order. re_0002 appears twice
 * (a redelivery); re_0003 is a partial refund of ch_0050; re_0004 fully
 * refunds ch_0007 after po_0001 had already paid it out — the refund lands
 * as its own negative, unswept row rather than mutating the charge.
 */
final class RefundFixtures extends LedgerRecordFixture
{
    #[\Override]
    public function getDependencies(): array
    {
        return [ChargeFixtures::class];
    }

    #[\Override]
    protected function ingest(Organisation $organisation, array $records): array
    {
        $this->seeder->recordRefunds($organisation, $records);

        return [];
    }

    #[\Override]
    public function records(): array
    {
        return [
            [
                'id' => 're_0002',
                'object' => 'refund',
                'created' => '2026-06-15T16:40:00Z',
                'charge' => 'ch_0042',
                'amount' => 1500,
                'currency' => 'gbp',
                'fee_details' => [
                    ['type' => 'application_fee', 'amount' => -60],
                ],
                'net' => -1440,
                'available_on' => '2026-06-17',
            ],
            [
                'id' => 're_0001',
                'object' => 'refund',
                'created' => '2026-06-15T10:12:00Z',
                'charge' => 'ch_0034',
                'amount' => 2500,
                'currency' => 'gbp',
                'fee_details' => [
                    ['type' => 'application_fee', 'amount' => -80],
                ],
                'net' => -2420,
                'available_on' => '2026-06-17',
            ],
            [
                'id' => 're_0002',
                'object' => 'refund',
                'created' => '2026-06-15T16:40:00Z',
                'charge' => 'ch_0042',
                'amount' => 1500,
                'currency' => 'gbp',
                'fee_details' => [
                    ['type' => 'application_fee', 'amount' => -60],
                ],
                'net' => -1440,
                'available_on' => '2026-06-17',
            ],
            [
                'id' => 're_0003',
                'object' => 'refund',
                'created' => '2026-06-16T09:05:00Z',
                'charge' => 'ch_0050',
                'amount' => 2250,
                'currency' => 'gbp',
                'fee_details' => [
                    ['type' => 'application_fee', 'amount' => -60],
                ],
                'net' => -2190,
                'available_on' => '2026-06-17',
            ],
            [
                'id' => 're_0004',
                'object' => 'refund',
                'created' => '2026-06-20T11:30:00Z',
                'charge' => 'ch_0007',
                'amount' => 4500,
                'currency' => 'gbp',
                'fee_details' => [
                    ['type' => 'application_fee', 'amount' => -120],
                ],
                'net' => -4380,
                'available_on' => '2026-06-20',
            ],
        ];
    }
}
