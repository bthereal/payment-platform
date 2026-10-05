<?php

declare(strict_types=1);

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * Lexik's failure handler answers every failed login with a 401, throttled
 * ones included — indistinguishable from a wrong password, so a client
 * would tell a locked-out user their password is wrong when retrying with
 * the right one can't work for another minute. A throttled attempt is a
 * 429 with Retry-After (RFC 6585), keeping Lexik's {code, message} shape.
 */
final class LoginThrottledListener
{
    #[AsEventListener(event: Events::AUTHENTICATION_FAILURE)]
    public function __invoke(AuthenticationFailureEvent $event): void
    {
        $exception = $event->getException();
        if (!$exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return;
        }

        $minutes = (int) ($exception->getMessageData()['%minutes%'] ?? 1);

        $event->setResponse(new JsonResponse(
            [
                'code' => JsonResponse::HTTP_TOO_MANY_REQUESTS,
                'message' => strtr($exception->getMessageKey(), $exception->getMessageData()),
            ],
            JsonResponse::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) (max(1, $minutes) * 60)],
        ));
    }
}
