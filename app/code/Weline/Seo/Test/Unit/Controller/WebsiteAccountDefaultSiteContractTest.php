<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class WebsiteAccountDefaultSiteContractTest extends TestCase
{
    public function testManageAndSaveAcceptDefaultWebsiteIdZero(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Backend/WebsiteAccount.php');
        self::assertStringContainsString('if ($websiteId < 0)', $src);
        self::assertStringNotContainsString('if ($websiteId <= 0)', $src);
    }
}
