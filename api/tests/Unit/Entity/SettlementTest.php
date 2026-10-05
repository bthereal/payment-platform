<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Organisation;
use App\Entity\Settlement;
use App\Enum\SettlementStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class SettlementTest extends TestCase
{
    public function testConstructorGeneratesAnId(): void
    {
        self::assertInstanceOf(Ulid::class, (new Settlement())->getId());
    }

    public function testSettersRoundTrip(): void
    {
        $organisation = new Organisation();
        $settlement = new Settlement();
        $createdAt = new \DateTimeImmutable('2026-06-16T04:00:00Z');
        $arrivalDate = new \DateTimeImmutable('2026-06-18');

        $settlement->setOrganisation($organisation);
        $settlement->setStripeId('po_0001');
        $settlement->setTotalAmount(44536);
        $settlement->setStatus(SettlementStatus::PAID);
        $settlement->setArrivalDate($arrivalDate);
        $settlement->setCreatedAt($createdAt);

        self::assertSame($organisation, $settlement->getOrganisation());
        self::assertSame('po_0001', $settlement->getStripeId());
        self::assertSame(44536, $settlement->getTotalAmount());
        self::assertSame(SettlementStatus::PAID, $settlement->getStatus());
        self::assertSame($arrivalDate, $settlement->getArrivalDate());
        self::assertSame($createdAt, $settlement->getCreatedAt());
    }

    /**
     * Only PAID appears in the fixture, but PENDING/FAILED must still be
     * assignable — that's the entire reason those cases exist on the enum.
     */
    public function testAcceptsEveryStatusOnTheEnum(): void
    {
        $settlement = new Settlement();

        foreach (SettlementStatus::cases() as $status) {
            $settlement->setStatus($status);
            self::assertSame($status, $settlement->getStatus());
        }
    }
}
