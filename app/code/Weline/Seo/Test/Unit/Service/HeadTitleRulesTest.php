<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Head\HeadTitleRules;

class HeadTitleRulesTest extends TestCase
{
    public function testSanitizeModuleCodeTitle(): void
    {
        self::assertSame('', HeadTitleRules::sanitizeTitle('Weline_Customer', 'Weline_Customer'));
        self::assertSame('', HeadTitleRules::sanitizeTitle('Weline_Customer'));
    }

    public function testComposePageAndSite(): void
    {
        self::assertSame(
            '个人中心 | 我的商城',
            HeadTitleRules::composePageAndSite('个人中心', '我的商城')
        );
    }

    public function testComposeSkipsDuplicateSiteName(): void
    {
        self::assertSame(
            '我的商城 - 首页',
            HeadTitleRules::composePageAndSite('我的商城 - 首页', '我的商城')
        );
    }
}
