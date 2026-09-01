<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

final class CustomerAdminQueryProviderTest extends TestCase
{
    public function testProviderDeclaresSearchAndResolveOperations(): void
    {
        $providerPath = dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CustomerAdminQueryProvider.php';
        $servicePath = dirname(__DIR__, 3) . '/Service/CustomerAdminPickerService.php';

        self::assertFileExists($providerPath);
        self::assertFileExists($servicePath);

        $provider = (string) file_get_contents($providerPath);
        $service = (string) file_get_contents($servicePath);

        self::assertStringContainsString("'search' => \$this->search(\$params)", $provider);
        self::assertStringContainsString("'resolve' => \$this->resolve(\$params)", $provider);
        self::assertStringContainsString('Weline_Customer::customer_index', $provider);
        self::assertStringContainsString('schema_fields_username', $service);
        self::assertStringContainsString('schema_fields_email', $service);
    }
}
