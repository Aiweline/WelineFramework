<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class PromotionThemeFormTemplateContractTest extends TestCase
{
    public function testFormUsesActivityThemeVariableNotLayoutTheme(): void
    {
        $path = __DIR__ . '/../../../view/templates/backend/promotion/theme/form.phtml';
        $content = (string)file_get_contents($path);

        self::assertStringContainsString("getData('activityTheme')", $content);
        self::assertStringNotContainsString("getData('theme')", $content);
    }

    public function testControllerAssignsActivityTheme(): void
    {
        $path = __DIR__ . '/../../../Controller/Backend/Theme.php';
        $content = (string)file_get_contents($path);

        self::assertStringContainsString("assign('activityTheme'", $content);
        self::assertStringNotContainsString("assign('theme', is_array(\$theme)", $content);
    }

    public function testFormEmbedsLocalModelTranslationLabels(): void
    {
        $formPath = __DIR__ . '/../../../view/templates/backend/promotion/theme/form.phtml';
        $partialPath = __DIR__ . '/../../../view/templates/backend/promotion/theme/partials/local-field-label.phtml';
        $formContent = (string)file_get_contents($formPath);
        $partialContent = (string)file_get_contents($partialPath);

        self::assertStringContainsString('promotion-theme-local-label', $formContent);
        self::assertStringContainsString('Weline_I18n::css/local-translation.css', $formContent);
        self::assertStringContainsString('promotion-theme-form.css', $formContent);
        self::assertStringContainsString('PromotionActivityThemeLocal', $formContent);
        self::assertStringContainsString('<local', $formContent);
        self::assertStringContainsString('id="activityTheme.id"', $formContent);
        self::assertStringContainsString('保存后可翻译多语言', $formContent);
        self::assertStringContainsString('promotion-theme-form__sticky-bar', $formContent);
        self::assertStringContainsString('promotion-theme-form__sticky-shell', $formContent);
        self::assertStringContainsString('promotion-theme-form__sticky-inner', $formContent);
        self::assertStringContainsString('mode="bulk"', $formContent);
        self::assertStringContainsString('bulk-input="promotion-theme-nav"', $formContent);
        self::assertStringNotContainsString('bulk-fields', $formContent);
        self::assertStringNotContainsString('data-promotion-theme-bulk-ai', $formContent);
        self::assertStringNotContainsString('taglib-local-ai-bulk', $formContent);
        self::assertStringContainsString('一键 AI 翻译', $formContent);

        self::assertStringContainsString('allow-empty="false"', $formContent);
        self::assertStringContainsString('cross-website="false"', $formContent);
        self::assertStringContainsString('一站一活动', $formContent);
        self::assertStringNotContainsString('empty-label="@lang(全部 Website)"', $formContent);

        foreach ([
            'nav_label',
            'page_title',
            'hero_lede',
            'entry_title',
            'entry_action_label',
            'entry_subtitle',
        ] as $field) {
            self::assertStringContainsString('field="' . $field . '"', $formContent, "missing local field hook: {$field}");
        }

        self::assertStringContainsString('PromotionActivityThemeLocal', $partialContent);
        self::assertStringContainsString('<local', $partialContent);
    }
}
