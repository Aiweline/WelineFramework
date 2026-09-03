<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CustomerServiceWidgetAccessibilityContractTest extends TestCase
{
    public function testSettingsLabelsAreProgrammaticallyAssociatedWithSelects(): void
    {
        $template = $this->moduleFile('view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');

        self::assertStringContainsString('<label for="cs-locale-select">', $template);
        self::assertStringContainsString('<select id="cs-locale-select"', $template);
        self::assertStringContainsString('<label for="cs-display-mode">', $template);
        self::assertStringContainsString('<select id="cs-display-mode"', $template);
    }

    public function testIconOnlySendButtonKeepsALocalizedAccessibleName(): void
    {
        $template = $this->moduleFile('view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
        $script = $this->moduleFile('view/statics/js/customer-service.js');

        self::assertMatchesRegularExpression(
            '/<button\b(?=[^>]*\bcs-send-button\b)(?=[^>]*\baria-label="@lang\(发送\)")(?=[^>]*\bdata-cs-send-message\b)[^>]*>/s',
            $template
        );
        self::assertStringContainsString(
            "sendButton.setAttribute('aria-label', __('发送'));",
            $script
        );
    }

    public function testDynamicLocaleSwitchKeepsComponentLanguageAndDirectionInSync(): void
    {
        $script = $this->moduleFile('view/statics/js/customer-service.js');

        self::assertStringContainsString(
            "const isRtl = ['ar', 'fa', 'he', 'ur'].includes(language);",
            $script
        );
        self::assertStringContainsString("widgetRoot.lang = locale.replace(/_/g, '-');", $script);
        self::assertStringContainsString("widgetRoot.dir = isRtl ? 'rtl' : 'ltr';", $script);
        self::assertStringContainsString("updateWidgetLocaleDirection();", $script);
    }

    public function testEverySupportedLocaleDictionaryIsSerializedForInWidgetSwitching(): void
    {
        $template = $this->moduleFile('view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');

        self::assertStringContainsString(
            '$widgetLocaleCodes = array_column($supportedLocales, \'code\');',
            $template
        );
        self::assertStringContainsString(
            'array_values(array_unique($widgetLocaleCodes))',
            $template
        );
    }

    public function testEveryJavascriptTranslationLiteralIsIncludedInTheWidgetDictionary(): void
    {
        $script = $this->moduleFile('view/statics/js/customer-service.js');
        $service = $this->moduleFile('Service/WidgetTranslationService.php');

        self::assertSame(
            1,
            preg_match('/private const WIDGET_KEYS = \[(.*?)\n    \];/s', $service, $widgetKeysBlock)
        );
        self::assertNotFalse(
            preg_match_all("/__\\(\\s*'([^']+)'\\s*\\)/", $script, $singleQuotedJsKeys)
        );
        self::assertNotFalse(
            preg_match_all('/__\(\s*"([^"]+)"\s*\)/', $script, $doubleQuotedJsKeys)
        );
        self::assertNotFalse(
            preg_match_all("/'([^']+)'/", $widgetKeysBlock[1], $serviceKeys)
        );

        $javascriptKeys = array_values(array_unique(array_merge(
            $singleQuotedJsKeys[1],
            $doubleQuotedJsKeys[1]
        )));
        $missingKeys = array_values(array_diff($javascriptKeys, array_unique($serviceKeys[1])));
        sort($missingKeys);

        self::assertNotEmpty($javascriptKeys);
        self::assertSame(
            [],
            $missingKeys,
            'Missing WidgetTranslationService keys: ' . implode(', ', $missingKeys)
        );
    }

    private function moduleFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        self::assertIsString($contents, 'CustomerService contract source must be readable: ' . $relativePath);

        return $contents;
    }
}
