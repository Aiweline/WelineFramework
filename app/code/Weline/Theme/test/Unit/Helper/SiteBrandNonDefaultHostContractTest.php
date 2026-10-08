<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;

/**
 * Non-default Host must not stick Backend site_name (长安汉服) before Website bind.
 * Website::ID_DEFAULT is 0 — existence checks must not use !(int)$id.
 */
final class SiteBrandNonDefaultHostContractTest extends TestCase
{
    public function testRequestLooksLikeNonDefaultStorefrontHostDoesNotTreatZeroIdAsMissing(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Helper/SiteBrand.php');
        self::assertStringContainsString('requestLooksLikeNonDefaultStorefrontHost', $src);
        self::assertStringContainsString('Website::ID_DEFAULT is 0', $src);
        self::assertStringContainsString('instanceof \\Weline\\Websites\\Model\\Website', $src);
        self::assertStringNotContainsString('!(int)$default->getId()', $src);
        self::assertStringContainsString('shouldUseBackendBrandIdentity', $src);
        self::assertStringContainsString('requestLooksLikeNonDefaultStorefrontHost()', $src);
    }
}
