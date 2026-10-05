<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Only PAID appears in the fixture data. PENDING/FAILED are here so a
 * future non-paid payout has somewhere to live without a migration.
 */
enum SettlementStatus: string
{
    case PAID = 'PAID';
    case PENDING = 'PENDING';
    case FAILED = 'FAILED';
}
