<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderCurrencySwitcherTemplateTest extends TestCase
{
    public function testCurrencyOptionDoesNotRepeatSymbolInMetaText(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-currency-switcher.phtml';

        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('<span class="weline-choice-symbol"', $content);
        self::assertMatchesRegularExpression(
            '/<span class="weline-choice-meta">\s*<\?= \$escape\(\$currencyCode\) \?>\s*<\/span>/',
            $content
        );

        preg_match('/<span class="weline-choice-meta">(?P<meta>.*?)<\/span>/s', $content, $matches);
        self::assertArrayHasKey('meta', $matches, 'Currency switcher should render a meta text node.');
        self::assertStringNotContainsString('$currencySymbol', $matches['meta']);
        self::assertStringNotContainsString('·', $matches['meta']);
    }

}
