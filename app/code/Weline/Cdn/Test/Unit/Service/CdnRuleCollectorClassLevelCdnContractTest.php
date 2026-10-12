<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 类级 @Cdn 必须进入收集：Search/Compare 写在类 docblock，不能只扫方法。
 */
final class CdnRuleCollectorClassLevelCdnContractTest extends TestCase
{
    public function testCollectorInheritsClassLevelCdnWhenMethodLacksAnnotation(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/CdnRuleCollector.php'
        );
        self::assertStringContainsString('classHasCdn', $src);
        self::assertStringContainsString('getDeclaringClass()', $src);
        self::assertMatchesRegularExpression(
            '/\$docComment\s*=\s*\$classDoc/',
            $src
        );
        self::assertStringContainsString('resolvePublicRoute', $src);
        self::assertStringContainsString('extractExtraPublicPathPatterns', $src);
    }

    public function testSearchAndCompareKeepClassLevelCacheFalse(): void
    {
        $root = \dirname(__DIR__, 7);
        $search = (string)\file_get_contents(
            $root . '/app/code/Weline/Search/Controller/Frontend/Index.php'
        );
        $compare = (string)\file_get_contents(
            $root . '/app/code/Weline/Compare/Controller/Frontend/Index.php'
        );
        self::assertMatchesRegularExpression('/@Cdn\s+cache=false/', $search);
        self::assertMatchesRegularExpression('/@Cdn\s+cache=false/', $compare);
    }
}
