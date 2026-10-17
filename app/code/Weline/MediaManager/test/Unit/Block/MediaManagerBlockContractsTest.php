<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Block;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMediaManagerBlockContractsPass().
 */
final class MediaManagerBlockContractsTest extends TestCase
{
    public function testAllMediaManagerBlockContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'WelineMediaConnectorOrigin::testBlockUsesBackendUrlPathForIframeConnector' => function () {
            $this->assert_WelineMediaConnectorOrigin_testBlockUsesBackendUrlPathForIframeConnector();
        },
            'WelineMediaConnectorOrigin::testPickerTemplateUsesTheRelativeConnectorSuppliedByTheBlock' => function () {
            $this->assert_WelineMediaConnectorOrigin_testPickerTemplateUsesTheRelativeConnectorSuppliedByTheBlock();
        },
        ];
    }

private function assert_WelineMediaConnectorOrigin_testBlockUsesBackendUrlPathForIframeConnector(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/MediaManager' . '/Block/WelineMedia.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('getBackendUrlPath(', $source);
    self::assertStringContainsString("'media/backend/manager/iframe'", $source);
    self::assertStringContainsString("'weline_filemanager/backend/media-reference/bind'", $source);
    self::assertStringContainsString("assign('bind_url'", $source);
    self::assertStringContainsString('Do not merge the parent workbench query', $source);
    self::assertStringNotContainsString("getUrl('media/", $source);
    self::assertStringNotContainsString('isBackend()', $source);
    self::assertDoesNotMatchRegularExpression('/getBackendUrlPath\(\s*[\'"]media\/backend\/manager\/iframe[\'"]\s*,\s*\$params\s*,\s*true\s*\)/', $source);
    self::assertDoesNotMatchRegularExpression('/getBackendUrl\(\s*[\'"]media\/backend\/manager\/iframe[\'"]/', $source);
}

private function assert_WelineMediaConnectorOrigin_testPickerTemplateUsesTheRelativeConnectorSuppliedByTheBlock(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/MediaManager' . '/view/blocks/weline-media.phtml';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('data-w-aspect-ratio-tolerance', $source);
    self::assertStringContainsString('data-src="{{connector}}"', $source);
    self::assertStringContainsString('data-w-bind-url="<?= htmlspecialchars((string)($this->getData(\'bind_url\') ?? \'\')', $source);
    self::assertStringContainsString('aspect_ratio_hint', $source);
    self::assertStringContainsString('recommend_size_display', $source);
    self::assertStringContainsString('data-aspect-ratio=', $source);
    self::assertStringNotContainsString('@backend-url{weline_filemanager/backend/media-reference/bind}', $source);
    self::assertStringNotContainsString('getBackendUrl(', $source);
    self::assertStringNotContainsString('window.location.origin', $source);
}
}
