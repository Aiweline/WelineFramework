<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;

final class DictionaryCollectCronContractTest extends TestCase
{
    public function testCronReusesFrameworkI18nCollectCommand(): void
    {
        $cron = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Cron/DictionaryCollect.php',
        );

        self::assertStringContainsString('I18nCollectCommand', $cron);
        self::assertStringContainsString('Weline\\Framework\\Console\\Console\\I18n\\Collect', $cron);
        self::assertStringContainsString('collectCommand->execute', $cron);
        self::assertStringContainsString("execute_name(): string", $cron);
        self::assertStringContainsString("'i18n_dictionary_collect'", $cron);
        self::assertStringContainsString("'10 * * * *'", $cron);
        self::assertStringNotContainsString("__('", $cron);

        $collect = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Framework/Console/Console/I18n/Collect.php',
        );
        self::assertStringContainsString("ALIASES = ['i18n:collect']", $collect);
        self::assertStringContainsString('DictionaryCompiler', $collect);
    }
}
