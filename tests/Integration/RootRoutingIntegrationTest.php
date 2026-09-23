<?php

declare(strict_types=1);

namespace Haccp\Tests\Integration;

final class RootRoutingIntegrationTest extends IntegrationTestCase
{
    public function testRootAddressRedirectsToDashboardForAuthenticatedUser(): void
    {
        $root = $this->dashboardRequest('/');

        self::assertSame(302, $root->getStatusCode());
        self::assertSame('/dashboard', $root->getHeaderLine('Location'));
        self::assertSame('no-store', $root->getHeaderLine('Cache-Control'));

        $dashboard = $this->dashboardRequest($root->getHeaderLine('Location'));
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('text/html', $dashboard->getHeaderLine('Content-Type'));
    }

    public function testRootAddressLeadsUnauthenticatedUserToLogin(): void
    {
        $root = $this->dashboardRequest('/', false);

        self::assertSame(302, $root->getStatusCode());
        self::assertSame('/dashboard', $root->getHeaderLine('Location'));

        $dashboard = $this->dashboardRequest($root->getHeaderLine('Location'), false);
        self::assertSame(302, $dashboard->getStatusCode());
        self::assertSame('/login', $dashboard->getHeaderLine('Location'));

        $login = $this->dashboardRequest($dashboard->getHeaderLine('Location'), false);
        self::assertSame(200, $login->getStatusCode());
        self::assertStringContainsString('text/html', $login->getHeaderLine('Content-Type'));
    }
}
