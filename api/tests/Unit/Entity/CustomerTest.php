<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Customer;
use App\Entity\Organisation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class CustomerTest extends TestCase
{
    public function testConstructorGeneratesAnIdAndCreatedAt(): void
    {
        $before = new \DateTimeImmutable();
        $customer = new Customer();
        $after = new \DateTimeImmutable();

        self::assertInstanceOf(Ulid::class, $customer->getId());
        self::assertGreaterThanOrEqual($before, $customer->getCreatedAt());
        self::assertLessThanOrEqual($after, $customer->getCreatedAt());
    }

    public function testSettersRoundTrip(): void
    {
        $organisation = new Organisation();
        $customer = new Customer();

        $customer->setOrganisation($organisation);
        $customer->setOrderRef('T-42');
        $customer->setSynthetic(true);

        self::assertSame($organisation, $customer->getOrganisation());
        self::assertSame('T-42', $customer->getOrderRef());
        self::assertTrue($customer->isSynthetic());
    }

    public function testSyntheticFlagCanBeFalse(): void
    {
        $customer = new Customer();
        $customer->setSynthetic(false);

        self::assertFalse($customer->isSynthetic());
    }
}
