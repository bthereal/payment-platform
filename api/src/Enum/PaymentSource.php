<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Provenance of a ledger entry — kept separate from PaymentDirection so a
 * future non-refund DEBIT (a chargeback, a manual adjustment) isn't a
 * modelling problem.
 */
enum PaymentSource: string
{
    case SALE = 'SALE';
    case REFUND = 'REFUND';
    case DISPUTE = 'DISPUTE';
}
