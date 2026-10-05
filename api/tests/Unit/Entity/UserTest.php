<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Organisation;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class UserTest extends TestCase
{
    public function testConstructorGeneratesAnIdAndCreatedAt(): void
    {
        $before = new \DateTimeImmutable();
        $user = new User();
        $after = new \DateTimeImmutable();

        self::assertInstanceOf(Ulid::class, $user->getId());
        self::assertGreaterThanOrEqual($before, $user->getCreatedAt());
        self::assertLessThanOrEqual($after, $user->getCreatedAt());
    }

    public function testSettersRoundTrip(): void
    {
        $organisation = new Organisation();
        $user = new User();

        $user->setOrganisation($organisation);
        $user->setEmail('admin@example.com');
        $user->setPassword('a-hashed-password');

        self::assertSame($organisation, $user->getOrganisation());
        self::assertSame('admin@example.com', $user->getEmail());
        self::assertSame('a-hashed-password', $user->getPassword());
    }

    public function testUserIdentifierIsTheEmail(): void
    {
        $user = new User();
        $user->setEmail('admin@example.com');

        self::assertSame('admin@example.com', $user->getUserIdentifier());
    }

    /**
     * Deliberate design decision (see the class docblock): every
     * authenticated user gets exactly ROLE_USER, regardless of any other
     * state — there is no roles column to vary this by.
     */
    public function testRolesAreAlwaysJustRoleUser(): void
    {
        $user = new User();

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testEraseCredentialsIsSafeToCallAndDoesNothingObservable(): void
    {
        $user = new User();
        $user->setPassword('a-hashed-password');

        $user->eraseCredentials();

        self::assertSame('a-hashed-password', $user->getPassword(), 'eraseCredentials() must not clear the stored (already-hashed) password');
    }
}
