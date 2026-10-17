<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMediaManagerControllerContractsPass().
 */
final class MediaManagerControllerContractsTest extends TestCase
{
    public function testAllMediaManagerControllerContractsPass(): void
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
            'ImageThumbnailResponse::testImageControllerUsesFrameworkResponseTerminate' => function () {
            $this->assert_ImageThumbnailResponse_testImageControllerUsesFrameworkResponseTerminate();
        },
            'ImageThumbnailResponse::testModuleVersionIs133OrNewer' => function () {
            $this->assert_ImageThumbnailResponse_testModuleVersionIs133OrNewer();
        },
        ];
    }

private function assert_ImageThumbnailResponse_testImageControllerUsesFrameworkResponseTerminate(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/MediaManager' . '/Controller/Image.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('Response::fromContent', $src);
    self::assertStringContainsString('ResponseTerminateException', $src);
    self::assertStringContainsString('mimeForExtension', $src);
    self::assertStringNotContainsString("header('Content-Type:image/jpeg')", $src);
    self::assertDoesNotMatchRegularExpression('/header\(\s*[\'"]Content-Type:/', $src);
}

private function assert_ImageThumbnailResponse_testModuleVersionIs133OrNewer(): void
{
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline/MediaManager' . '/etc/module.php';
    self::assertIsArray($module);
    $version = (string) ($module['version'] ?? '');
    self::assertTrue(version_compare($version, '1.3.4', '>='), 'expected MediaManager >= 1.3.4, got ' . $version);
}
}
