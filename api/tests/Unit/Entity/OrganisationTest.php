<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Organisation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class OrganisationTest extends TestCase
{
    public function testConstructorGeneratesAnIdAndCreatedAt(): void
    {
        $before = new \DateTimeImmutable();
        $organisation = new Organisation();
        $after = new \DateTimeImmutable();

        self::assertInstanceOf(Ulid::class, $organisation->getId());
        self::assertGreaterThanOrEqual($before, $organisation->getCreatedAt());
        self::assertLessThanOrEqual($after, $organisation->getCreatedAt());
    }

    public function testStripeAccountIdDefaultsToNull(): void
    {
        self::assertNull((new Organisation())->getStripeAccountId());
    }

    public function testSettersRoundTrip(): void
    {
        $organisation = new Organisation();
        $organisation->setName('Fixture Organisation');
        $organisation->setStripeAccountId('acct_123');

        self::assertSame('Fixture Organisation', $organisation->getName());
        self::assertSame('acct_123', $organisation->getStripeAccountId());
    }

    public function testStripeAccountIdCanBeClearedBackToNull(): void
    {
        $organisation = new Organisation();
        $organisation->setStripeAccountId('acct_123');
        $organisation->setStripeAccountId(null);

        self::assertNull($organisation->getStripeAccountId());
    }
}
