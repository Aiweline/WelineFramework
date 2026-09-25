<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\ScopeMaintenanceGate;

/**
 * N4: ScopeMaintenanceGate.status prefers storefront.render_context.v1 +
 * request memo — no parallel maintenance bag.
 */
final class ScopeMaintenanceRenderContextReaderContractTest extends TestCase
{
    public function testStatusPrefersReaderThenRequestMemo(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ScopeMaintenanceGate.php',
        );
        self::assertStringContainsString('StorefrontRenderContextReader::maintenance', $src);
        self::assertStringContainsString("rememberForRequest(", $src);
        self::assertStringContainsString("'scope_maintenance'", $src);
        self::assertStringContainsString('$this->status($candidate)', $src);
        self::assertStringNotContainsString(
            '$this->repository->status($candidate)',
            $src,
        );
        self::assertTrue(class_exists(ScopeMaintenanceGate::class));
    }
}
