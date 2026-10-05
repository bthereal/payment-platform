<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every /api/* error becomes a clean {"error": "..."} JSON response, never
 * Symfony's default HTML error page — this app has no server-rendered
 * views, so an HTML error page from an API route is a bug
 * in its own right. Verified (see the investigation this docblock records):
 * without this listener, stock Symfony renders HTML for /api/* whenever
 * the client doesn't send an explicit `Accept: application/json` — true in
 * both dev and prod, so this isn't an env-specific fix.
 *
 * Exception messages are exposed for RequestExceptionInterface/
 * HttpExceptionInterface — Symfony's own "this message is safe to show the
 * client" categories, and every message this app actually throws in those
 * categories (e.g. "The key \"password\" must be provided.") is a genuine,
 * intentional validation message, not an internal leak. This is
 * deliberately *more* detail than stock Symfony's own prod default, which
 * collapses even those safe messages to a bare "Bad Request" — confirmed
 * empirically by running this app with APP_ENV=prod APP_DEBUG=0. That
 * generic behavior is the right default for a framework that can't know
 * which messages are safe; here, the messages are all first-party and
 * checked, so showing them is a deliberate choice, not an oversight.
 *
 * Anything else (a genuinely unexpected exception) is debug-gated:
 *   - %kernel.debug% true (dev/test): the real exception class + message,
 *     so curling an endpoint locally is enough to see what broke — a full
 *     multi-frame trace is deliberately NOT included in the response body;
 *     it's already in `docker compose logs api` via Symfony's own
 *     ErrorListener::logKernelException(), which runs before this listener
 *     regardless (priority 0 vs. this listener's -8) and isn't affected by
 *     this listener stopping propagation afterward.
 *   - %kernel.debug% false (prod): a generic message only. Verified this
 *     matches stock Symfony's own prod behavior for an unhandled exception
 *     (generic "Internal Server Error", no detail, no trace) — this
 *     listener's only actual behavior change in prod is JSON-vs-HTML, not
 *     how much detail is shown.
 *
 * Priority -8, deliberately: Symfony Security's own exception listener
 * (which turns an unauthenticated/forbidden request into a proper 401/403
 * via the firewall's entry point) runs at priority 1 and must go first —
 * running ahead of it here would swallow that as a generic 500 instead.
 * This still runs well before the framework's default ErrorListener
 * (-128), so it's the one that gets to format whatever's left as JSON.
 */
final class ApiExceptionListener
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: -8)]
    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();

        [$status, $message] = match (true) {
            $exception instanceof RequestExceptionInterface => [JsonResponse::HTTP_BAD_REQUEST, $exception->getMessage()],
            $exception instanceof HttpExceptionInterface => [$exception->getStatusCode(), $exception->getMessage()],
            default => [JsonResponse::HTTP_INTERNAL_SERVER_ERROR, $this->unexpectedExceptionMessage($exception)],
        };

        $event->setResponse(new JsonResponse(['error' => $message], $status));
        $event->stopPropagation();
    }

    private function unexpectedExceptionMessage(\Throwable $exception): string
    {
        if (!$this->debug) {
            return 'An unexpected error occurred.';
        }

        return \sprintf('%s: %s', $exception::class, $exception->getMessage());
    }
}
