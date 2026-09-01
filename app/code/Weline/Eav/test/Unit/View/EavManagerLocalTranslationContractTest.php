<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class EavManagerLocalTranslationContractTest extends TestCase
{
    public function testManagerIntegratesLocalTranslationAndI18nUi(): void
    {
        $controller = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Manager.php',
        );
        $service = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LocalTranslation/EavLocalTranslationService.php',
        );
        $queue = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Queue/EavLocalTranslationQueue.php',
        );
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Backend/Manager/surface.phtml',
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/eav-manager-native.js',
        );

        self::assertStringContainsString('postLocalAiTranslate', $controller);
        self::assertStringContainsString('EAV 定时翻译入口已移除', $controller);
        self::assertStringContainsString('normalizeLocalizedStructureRow', $controller);
        self::assertStringContainsString('source_name', $controller);
        self::assertStringContainsString('saveLocalizedField', $controller);
        self::assertStringContainsString('isSourceLocale', $service);
        self::assertStringContainsString('I18nAiTranslationAdapter', $service);
        self::assertStringContainsString('collectEntityWorkItems', $service);
        self::assertStringContainsString('EavLocalTranslationQueue', $queue);
        self::assertStringContainsString('"localBase"', $tpl);
        self::assertStringNotContainsString('data-w-eav-i18n-schedule', $tpl);
        self::assertStringContainsString('mountLocalTranslationActions', $js);
        self::assertStringContainsString('values.local_name', $js);
        self::assertStringContainsString('sourceValue', $js);
        self::assertStringNotContainsString('scheduleLocalTranslation', $js);
        self::assertStringNotContainsString('updateI18nScheduleButton', $js);
    }
}
