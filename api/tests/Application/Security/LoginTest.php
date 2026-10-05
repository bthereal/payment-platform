<?php

declare(strict_types=1);

namespace App\Tests\Application\Security;

use App\Tests\Application\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

final class LoginTest extends WebTestCase
{
    use ResetsDatabase;

    private function seededClient(): KernelBrowser
    {
        $client = self::createClient();
        $this->truncateLedgerTables(self::getContainer()->get(EntityManagerInterface::class));
        // Failed-login counters (security.yaml, login_throttling) persist in
        // the filesystem cache, so they'd accumulate across tests and runs
        // until the happy-path login itself got throttled. Start clean.
        self::getContainer()->get('cache.rate_limiter')->clear();

        $application = new Application(self::bootKernel());
        $command = $application->find('app:seed-ledger');
        (new CommandTester($command))->execute([
            '--file' => __DIR__ . '/../../Fixtures/records.json',
            '--no-interaction' => true,
        ]);

        return $client;
    }

    public function testCorrectCredentialsReturnAUsableToken(): void
    {
        $client = $this->seededClient();
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'admin@example.com', 'password' => 'password'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        $token = json_decode($content !== false ? $content : '', true, flags: \JSON_THROW_ON_ERROR)['token'];
        self::assertNotEmpty($token);

        $client->request('GET', '/api/dashboard', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
    }

    public function testWrongPasswordIsRejected(): void
    {
        $client = $this->seededClient();
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'admin@example.com', 'password' => 'not-the-password'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUnknownEmailIsRejected(): void
    {
        $client = $this->seededClient();
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'nobody@example.com', 'password' => 'password'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMissingPasswordFieldReturnsACleanJsonErrorNotATrace(): void
    {
        $client = $this->seededClient();
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'admin@example.com'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $content = $client->getResponse()->getContent();
        $payload = json_decode($content !== false ? $content : '', true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $payload);
        self::assertArrayNotHasKey('trace', $payload, 'no internal stack trace should ever reach the response body');
    }

    public function testMalformedJsonBodyReturnsACleanJsonErrorNotATrace(): void
    {
        $client = $this->seededClient();
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{not valid json',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $content = $client->getResponse()->getContent();
        $payload = json_decode($content !== false ? $content : '', true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $payload);
        self::assertArrayNotHasKey('trace', $payload);
    }

    public function testEmptyBodyReturnsACleanJsonErrorNotATrace(): void
    {
        $client = $this->seededClient();
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $content = $client->getResponse()->getContent();
        $payload = json_decode($content !== false ? $content : '', true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $payload);
        self::assertArrayNotHasKey('trace', $payload);
    }

    /**
     * The sixth failure inside a minute is throttled (security.yaml,
     * login_throttling max_attempts: 5) — and so is a *correct* password
     * after that, which is the whole point: an attacker who guesses right
     * on attempt six still doesn't get in. Answered as 429, not Lexik's
     * default 401, so a client can tell lockout from a wrong password.
     */
    public function testRepeatedFailedLoginsAreThrottled(): void
    {
        $client = $this->seededClient();

        $attempt = static function (string $password) use ($client): void {
            $client->request(
                'POST',
                '/api/login',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['email' => 'admin@example.com', 'password' => $password], \JSON_THROW_ON_ERROR),
            );
        };

        for ($i = 0; $i < 5; ++$i) {
            $attempt('not-the-password');
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }

        $attempt('password');
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertResponseHasHeader('Retry-After');

        $content = $client->getResponse()->getContent();
        $payload = json_decode($content !== false ? $content : '', true, flags: \JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('Too many failed login attempts', $payload['message']);
    }
}
