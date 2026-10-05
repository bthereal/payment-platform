<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Payment;
use App\Entity\Settlement;
use App\Entity\SettlementPayment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class SettlementPaymentTest extends TestCase
{
    public function testConstructorGeneratesAnId(): void
    {
        self::assertInstanceOf(Ulid::class, (new SettlementPayment())->getId());
    }

    public function testSettersRoundTrip(): void
    {
        $settlement = new Settlement();
        $payment = new Payment();

        $settlementPayment = new SettlementPayment();
        $settlementPayment->setSettlement($settlement);
        $settlementPayment->setPayment($payment);

        self::assertSame($settlement, $settlementPayment->getSettlement());
        self::assertSame($payment, $settlementPayment->getPayment());
    }
}
