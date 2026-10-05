<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Organisation;

/**
 * The sample extract's payouts. Depends on refunds and disputes as well as
 * charges, since po_0002 sweeps refund and dispute balance transactions too — every
 * payment po_0001 left behind, so nothing is pending once both are in.
 */
final class PayoutFixtures extends LedgerRecordFixture
{
    #[\Override]
    public function getDependencies(): array
    {
        return [RefundFixtures::class, DisputeFixtures::class];
    }

    #[\Override]
    protected function ingest(Organisation $organisation, array $records): array
    {
        return $this->seeder->recordPayouts($organisation, $records);
    }

    #[\Override]
    public function records(): array
    {
        return [
            [
                'id' => 'po_0001',
                'object' => 'payout',
                'created' => '2026-06-16T04:00:00Z',
                'amount' => 44536,
                'currency' => 'gbp',
                'status' => 'paid',
                'arrival_date' => '2026-06-18',
                'included_balance_transactions' => [
                    'ch_0001', 'ch_0002', 'ch_0003', 'ch_0004', 'ch_0005', 'ch_0006', 'ch_0007',
                    'ch_0008', 'ch_0009', 'ch_0010', 'ch_0011', 'ch_0012', 'ch_0013', 'ch_0014',
                    'ch_0015', 'ch_0016', 'ch_0017', 'ch_0018', 'ch_0019', 'ch_0020', 'ch_0021',
                    'ch_0022', 'ch_0023', 'ch_0024', 'ch_0025', 'ch_0026',
                ],
            ],
            [
                'id' => 'po_0002',
                'object' => 'payout',
                'created' => '2026-06-21T04:00:00Z',
                'amount' => 52028,
                'currency' => 'gbp',
                'status' => 'paid',
                'arrival_date' => '2026-06-23',
                'included_balance_transactions' => [
                    'ch_0027', 'ch_0028', 'ch_0029', 'ch_0030', 'ch_0031', 'ch_0032', 'ch_0033',
                    'ch_0034', 'ch_0035', 'ch_0036', 'ch_0037', 'ch_0038', 'ch_0039', 'ch_0040',
                    'ch_0041', 'ch_0042', 'ch_0043', 'ch_0044', 'ch_0045', 'ch_0046', 'ch_0047',
                    'ch_0048', 'ch_0049', 'ch_0050', 'ch_0051', 'ch_0052', 'ch_0053', 'ch_0054',
                    'ch_0055', 'ch_0056', 'ch_0057', 'ch_0058', 'ch_0059', 'ch_0060', 're_0001',
                    're_0002', 're_0003', 'dp_0001', 're_0004',
                ],
            ],
        ];
    }
}
