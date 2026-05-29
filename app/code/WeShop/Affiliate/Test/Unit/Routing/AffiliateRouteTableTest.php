<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\Routing;

use PHPUnit\Framework\TestCase;

final class AffiliateRouteTableTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    public function testFrontendRouteTableContainsAffiliateRoutes(): void
    {
        $routes = $this->loadRouteTable('frontend_pc.php');

        $this->assertArrayHasKey('affiliate', $routes);
        $this->assertArrayHasKey('affiliate/redirect', $routes);
        $this->assertSame('WeShop_Affiliate', $routes['affiliate']['module'] ?? null);
        $this->assertSame('WeShop_Affiliate', $routes['affiliate/redirect']['module'] ?? null);
    }

    public function testBackendRouteTableContainsAffiliateAdminRoutes(): void
    {
        $routes = $this->loadRouteTable('backend_pc.php');

        $this->assertArrayHasKey('affiliate/backend/affiliate', $routes);
        $this->assertArrayHasKey('affiliate/backend/affiliate/view', $routes);
        $this->assertArrayHasKey('affiliate/backend/affiliate/save::POST', $routes);
        $this->assertArrayHasKey('affiliate/backend/affiliate/delete::GET', $routes);
        $this->assertArrayHasKey('affiliate/backend/affiliate/delete::POST', $routes);
        $this->assertSame('WeShop_Affiliate', $routes['affiliate/backend/affiliate']['module'] ?? null);
        $this->assertSame('WeShop_Affiliate', $routes['affiliate/backend/affiliate/save::POST']['module'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadRouteTable(string $fileName): array
    {
        $path = dirname(__DIR__, 7) . DIRECTORY_SEPARATOR . 'generated' . DIRECTORY_SEPARATOR . 'routers' . DIRECTORY_SEPARATOR . $fileName;
        $this->assertFileExists($path);

        $routes = require $path;
        $this->assertIsArray($routes);

        return $routes;
    }
}
