<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Weline\Admin\Api\Runtime\RequestResetter;
use Weline\Admin\Helper\MenuUrlValidator;

final class RequestResetterTest extends TestCase
{
    public function testRequestResetPreservesProcessMenuAndWhitelistCaches(): void
    {
        $menuProperty = new ReflectionProperty(MenuUrlValidator::class, 'menuPathsCache');
        $whitelistProperty = new ReflectionProperty(MenuUrlValidator::class, 'whitelistCache');
        $menuProperty->setAccessible(true);
        $whitelistProperty->setAccessible(true);
        $originalMenu = $menuProperty->getValue();
        $originalWhitelist = $whitelistProperty->getValue();

        try {
            $menuProperty->setValue(null, ['admin/catalog/index']);
            $whitelistProperty->setValue(null, ['admin/login']);

            (new RequestResetter())->resetRequest();

            self::assertSame(['admin/catalog/index'], $menuProperty->getValue());
            self::assertSame(['admin/login'], $whitelistProperty->getValue());
        } finally {
            $menuProperty->setValue(null, $originalMenu);
            $whitelistProperty->setValue(null, $originalWhitelist);
        }
    }
}
