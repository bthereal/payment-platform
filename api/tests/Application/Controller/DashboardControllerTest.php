<?php

declare(strict_types=1);

namespace App\Tests\Application\Controller;

use App\Entity\Organisation;
use App\Entity\User;
use App\Tests\Application\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DashboardControllerTest extends WebTestCase
{
    use ResetsDatabase;

    private function seedFixture(): void
    {
        $application = new Application(self::bootKernel());
        $command = $application->find('app:seed-ledger');
        (new CommandTester($command))->execute([
            '--file' => __DIR__ . '/../../Fixtures/records.json',
            '--no-interaction' => true,
        ]);
    }

    /** @return array<string, string> */
    private function authHeader(KernelBrowser $client): array
    {
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'admin@example.com', 'password' => 'password'], \JSON_THROW_ON_ERROR),
        );
        $content = $client->getResponse()->getContent();
        $token = json_decode($content !== false ? $content : '', true, flags: \JSON_THROW_ON_ERROR)['token'];

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /**
     * Creates a second, entirely separate organisation and user (no
     * ledger data seeded for it) and logs in as that user.
     *
     * @return array<string, string>
     */
    private function authHeaderForANewEmptyOrganisation(KernelBrowser $client, EntityManagerInterface $entityManager, string $email): array
    {
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $organisation = new Organisation();
        $organisation->setName('Second Organisation');
        $entityManager->persist($organisation);

        $user = new User();
        $user->setOrganisation($organisation);
        $user->setEmail($email);
        $entityManager->persist($user);
        $user->setPassword($passwordHasher->hashPassword($user, 'password'));

        $entityManager->flush();

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => 'password'], \JSON_THROW_ON_ERROR),
        );
        $content = $client->getResponse()->getContent();
        $token = json_decode($content !== false ? $content : '', true, flags: \JSON_THROW_ON_ERROR)['token'];

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $this->truncateLedgerTables(self::getContainer()->get(EntityManagerInterface::class));

        $client->request('GET', '/api/dashboard');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testReturnsTheDashboardSummaryOnceAuthenticatedAndSeeded(): void
    {
        $client = self::createClient();
        $this->truncateLedgerTables(self::getContainer()->get(EntityManagerInterface::class));
        $this->seedFixture();

        $client->request('GET', '/api/dashboard', server: $this->authHeader($client));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $content = $client->getResponse()->getContent();
        $payload = json_decode($content !== false ? $content : '', true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(4500, $payload['grossSales']);
        self::assertSame(1840, $payload['paidOutToDate']);
        self::assertIsArray($payload['pending']);
        self::assertSame(['day', 'week', 'month'], array_keys($payload['salesWindow']));
    }

    public function testRejectsAnInvalidDateFrom(): void
    {
        $client = self::createClient();
        $this->truncateLedgerTables(self::getContainer()->get(EntityManagerInterface::class));
        $this->seedFixture();

        $client->request('GET', '/api/dashboard?date_from=not-a-date', server: $this->authHeader($client));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testRejectsANonScalarDateFromAsCleanJsonNotAnHtmlErrorPage(): void
    {
        $client = self::createClient();
        $this->truncateLedgerTables(self::getContainer()->get(EntityManagerInterface::class));
        $this->seedFixture();

        // date_from[]=... makes Symfony's InputBag::get() itself throw,
        // before the controller's own try/catch ever gets a chance to run —
        // this app has no HTML views at all, so an HTML error page here
        // would be a real architectural break, not just an ugly response.
        $client->request('GET', '/api/dashboard?date_from[]=2026-01-01', server: $this->authHeader($client));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $content = $client->getResponse()->getContent();
        $payload = json_decode($content !== false ? $content : '', true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $payload);
    }

    public function testDashboardIsScopedToTheAuthenticatedUsersOwnOrganisation(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->truncateLedgerTables($entityManager);
        $this->seedFixture();

        // A brand new organisation with a real user but zero ledger
        // activity — covers two things at once: that its dashboard doesn't
        // error on an empty ledger, and that it never sees the first
        // organisation's numbers.
        $otherOrgHeader = $this->authHeaderForANewEmptyOrganisation($client, $entityManager, 'other-org@example.com');

        $client->request('GET', '/api/dashboard', server: $otherOrgHeader);

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        $payload = json_decode($content !== false ? $content : '', true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(0, $payload['grossSales'], "a brand new organisation must never see another organisation's totals");
        self::assertSame(0, $payload['netEarned']);
        self::assertSame(0, $payload['paidOutToDate']);
        self::assertSame([], $payload['pending']);
        self::assertSame(['day' => 0, 'week' => 0, 'month' => 0], $payload['salesWindow']);

        // And the original, seeded organisation must be completely
        // unaffected by the second one existing.
        $client->request('GET', '/api/dashboard', server: $this->authHeader($client));
        $content = $client->getResponse()->getContent();
        $payload = json_decode($content !== false ? $content : '', true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(4500, $payload['grossSales']);
        self::assertSame(1840, $payload['paidOutToDate']);
    }
}
