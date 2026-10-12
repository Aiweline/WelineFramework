<?php
declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 契约：词典导出语种列表含源语，且不因 AI 总开关关闭而返回空集逻辑。
 */
final class DictionaryPublishLocaleCodesContractTest extends TestCase
{
    public function testDictionaryPublishLocaleCodesIgnoresAiMasterSwitchGate(): void
    {
        $config = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AiTranslationConfig.php',
        );
        $observer = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/DictionaryCompileAfterObserver.php',
        );

        self::assertStringContainsString('function getDictionaryPublishLocaleCodes', $config);
        self::assertStringContainsString('不依赖', $config);
        // getEnabledLocaleCodes 仍保留 AI 总开关早退；导出方法不得复制该早退
        $enabledPos = strpos($config, 'function getEnabledLocaleCodes');
        $publishPos = strpos($config, 'function getDictionaryPublishLocaleCodes');
        self::assertNotFalse($enabledPos);
        self::assertNotFalse($publishPos);
        $publishBody = substr($config, (int)$publishPos, 2200);
        self::assertStringNotContainsString("empty(\$config['enabled'])", $publishBody);
        self::assertStringContainsString('array_unshift($codes, $sourceLocale)', $publishBody);

        self::assertStringContainsString('getDictionaryPublishLocaleCodes', $observer);
        self::assertStringNotContainsString('getEnabledLocaleCodes', $observer);
    }
}
