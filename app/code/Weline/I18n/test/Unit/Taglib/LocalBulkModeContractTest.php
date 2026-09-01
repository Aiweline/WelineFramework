<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

final class LocalBulkModeContractTest extends TestCase
{
    public function testLocalTagDeclaresBulkModeAndSharedDialog(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Taglib/Local.php');

        self::assertStringContainsString("'mode' => false", $source);
        self::assertStringContainsString("'bulk-fields' => false", $source);
        self::assertStringContainsString('isBulkRenderTagKey', $source);
        self::assertStringContainsString('tag-self-close-with-attrs', $source);
        self::assertStringContainsString('data-w-local-bulk-root', $source);
        self::assertStringContainsString('data-w-local-bulk-dialog', $source);
        self::assertStringContainsString('data-w-closable="false"', $source);
        self::assertStringContainsString('resolveModuleStaticUrl', $source);
        self::assertStringContainsString('fetchTagSource', $source);
        self::assertStringNotContainsString("getStaticUrl('Weline_I18n::js/local-bulk-translation.js')", $source);
        self::assertStringContainsString('data-w-local-bulk-participant', $source);
    }

    public function testResumableTaskRegistrationExists(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/resumable_tasks.php');

        self::assertStringContainsString('TaglibLocalBulkTranslationTaskHandler::TYPE_CODE', $source);
        self::assertStringContainsString('TaglibLocalBulkTranslationTaskHandler::class', $source);
        self::assertStringContainsString('Weline_I18n::i18n_dictionaries', $source);
    }

    public function testBulkTranslationJsUsesRuntimeTaskAndModalLock(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/local-bulk-translation.js');

        self::assertStringContainsString('i18n.taglib_local_bulk', $source);
        self::assertStringContainsString('runTranslationTaskPolling', $source);
        self::assertStringContainsString('resolveRuntimeStartError', $source);
        self::assertStringNotContainsString('.createStream(', $source);
        self::assertStringContainsString('wClosable', $source);
        self::assertStringContainsString('finishDialog', $source);
        self::assertStringContainsString('getDialogCloseButtons', $source);
        self::assertStringContainsString("querySelectorAll('[data-w-local-bulk-close]')", $source);
    }
}
