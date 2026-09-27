<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class EntityAttributeStoreFreshInstallRegisterContractTest extends TestCase
{
    public function testProvisionRegistersMissingEntityFromDefinition(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/EntityAttributeStore.php',
        );
        self::assertStringContainsString('ensureRegisteredFromDefinition', $src);
        self::assertStringContainsString(
            'Fresh install: Module Install may run before UpgradeDefaultAttribute',
            $src,
        );
        self::assertMatchesRegularExpression(
            '/provisionValueTables\([^)]*\)\s*:\s*void\s*\{[^}]*ensureRegisteredFromDefinition/s',
            $src,
        );
    }
}
