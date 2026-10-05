<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The accounting fact: which way money moved relative to the organisation's
 * Stripe balance. CREDIT = a sale increased it, DEBIT = a refund decreased it.
 * Kept separate from PaymentSource, which records *why*.
 */
enum PaymentDirection: string
{
    case CREDIT = 'CREDIT';
    case DEBIT = 'DEBIT';
}
