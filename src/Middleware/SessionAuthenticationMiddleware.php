<?php

declare(strict_types=1);

namespace Haccp\Middleware;

use Haccp\Service\AuthService;
use Haccp\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final readonly class SessionAuthenticationMiddleware implements MiddlewareInterface
{
    /** @param list<string> $roles */
    public function __construct(private AuthService $auth, private array $roles = [], private bool $csrf = false)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $request->getCookieParams()[AuthService::COOKIE] ?? null;
        $session = $this->auth->authenticate(is_string($token) ? $token : null);
        if ($session === null) {
            if (!str_starts_with($request->getUri()->getPath(), '/api/')) {
                return (new Response())->withStatus(302)->withHeader('Location', '/login');
            }

            return JsonResponse::write(new Response(), [
                'success' => false,
                'error' => ['code' => 'AUTHENTICATION_REQUIRED', 'message' => 'Eine Anmeldung ist erforderlich.'],
            ], 401);
        }
        $path = $request->getUri()->getPath();
        if ((bool) $session['password_change_required']
            && !in_array($path, ['/api/v1/auth/me', '/api/v1/auth/me/password', '/api/v1/auth/logout'], true)) {
            return JsonResponse::write(new Response(), [
                'success' => false,
                'error' => ['code' => 'PASSWORD_CHANGE_REQUIRED', 'message' => 'Vor der weiteren Nutzung muss das Passwort geändert werden.'],
            ], 403);
        }
        if ($this->roles !== [] && !in_array((string) $session['role'], $this->roles, true)) {
            return JsonResponse::write(new Response(), [
                'success' => false,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Für diese Aktion fehlt die Berechtigung.'],
            ], 403);
        }
        if ($this->csrf && !hash_equals((string) $session['csrf_token'], $request->getHeaderLine('X-CSRF-Token'))) {
            return JsonResponse::write(new Response(), [
                'success' => false,
                'error' => ['code' => 'CSRF_FAILED', 'message' => 'Die Sicherheitsprüfung der Anfrage ist fehlgeschlagen.'],
            ], 403);
        }

        return $handler->handle($request->withAttribute('dashboard_user', $session)->withAttribute('session_token', $token));
    }
}
