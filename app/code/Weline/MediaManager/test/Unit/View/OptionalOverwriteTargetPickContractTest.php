<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 上传/拖入可选指定覆盖目标：UI 与接线契约。
 */
class OptionalOverwriteTargetPickContractTest extends TestCase
{
    public function testManagerSurfacesOptionalOverwritePickBeforeConflictResolution(): void
    {
        $js = (string)file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js');
        $template = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/templates/Backend/Manager/manager.phtml'
        );
        $css = (string)file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css');

        self::assertStringContainsString('function promptOptionalOverwriteTargets(fileList, targetHash)', $js);
        self::assertStringContainsString('function directoryChildFiles(targetHash)', $js);
        self::assertStringContainsString('function filterDirectoryChildFiles(files, query)', $js);
        self::assertMatchesRegularExpression(
            '/source === \'drop\' \|\| source === \'paste\'[\s\S]*promptOptionalOverwriteTargets\(files, targetHash\)[\s\S]*pickTargets\.then\(function\(mapped\)[\s\S]*resolveUploadNameConflicts\(mapped\.files, targetHash, mapped\.overwriteFlags\)/',
            $js
        );
        self::assertStringContainsString('if (flags[index]) {', $js);
        self::assertStringContainsString("t('overwritePickDuplicateTarget'", $js);
        self::assertStringContainsString('id="mmf-overwrite-pick-overlay" hidden', $template);
        self::assertStringContainsString('data-mmf-overwrite-pick-rows', $template);
        self::assertStringNotContainsString('id="mmf-overwrite-dock"', $template);
        self::assertStringContainsString('.mmf-overwrite-pick-overlay', $css);
        self::assertStringNotContainsString('.mmf-overwrite-dock {', $css);
        self::assertStringContainsString('.mmf-statusbar { flex: 0 0 auto', $css);
        self::assertStringContainsString('value="replace_target"', $template);
        self::assertStringContainsString('id="mmf-ai-save-target-pick"', $template);
        self::assertStringContainsString('function syncAiSaveModeFields()', $js);
        self::assertStringContainsString("saveMode = 'overwrite'", $js);
    }

    public function testAiDrawServiceAllowsOverwriteViaReplaceExistingFile(): void
    {
        $service = (string)file_get_contents(BP . '/app/code/Weline/MediaManager/Service/AiDrawService.php');
        $storage = (string)file_get_contents(BP . '/app/code/Weline/MediaManager/Service/MediaStorageService.php');

        self::assertStringContainsString("if (\$saveMode === 'overwrite') {", $service);
        self::assertStringNotContainsString('统一 FileAsset 模式不允许原位覆盖共享资源', $service);
        self::assertStringContainsString('replaceExistingFile(', $service);
        self::assertStringContainsString('public function replaceExistingFile(', $storage);
        self::assertStringContainsString('$this->assets->replaceContent(', $storage);
    }
}
