<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Exists only so /api/login is a real, routable path for json_login's
 * check_path (security.yaml, "login" firewall) to match against —
 * Symfony's router 404s before the firewall gets a chance to intercept the
 * request otherwise. JsonLoginAuthenticator handles every real login
 * request and returns a JWT before this would ever run.
 *
 * Route registration lives in config/routes/login.yaml and its OpenAPI
 * documentation lives in config/packages/nelmio_api_doc.yaml — neither
 * belongs here, since this class has no real behaviour to attach them to.
 */
final class LoginController
{
    public function __invoke(): never
    {
        throw new \LogicException('Unreachable: the "login" firewall intercepts this request first.');
    }
}
