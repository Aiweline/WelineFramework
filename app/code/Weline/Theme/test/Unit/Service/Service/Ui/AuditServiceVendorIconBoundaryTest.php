<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Ui;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Ui\AssetManifest;
use Weline\Theme\Service\Ui\AuditService;

\defined('BP') || \define('BP', \dirname(__DIR__, 8) . \DIRECTORY_SEPARATOR);

final class AuditServiceVendorIconBoundaryTest extends TestCase
{
    public function testHexadecimalRangesAreNotReportedAsFontAwesomeIcons(): void
    {
        $directory = sys_get_temp_dir() . '/weline-ui-audit-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0777, true));
        $source = $directory . '/boundary.js';
        file_put_contents($source, <<<'JS'
const hexadecimal = /^[0-9a-fA-F]+$/;
const markup = '<span class="mdi-eye"></span>';
JS);

        try {
            $report = (new AuditService(new AssetManifest()))->auditRepository($directory);
            $vendorIcons = array_values(array_filter(
                $report['violations'],
                static fn (array $violation): bool => $violation['code'] === 'vendor_icon'
            ));

            self::assertCount(1, $vendorIcons);
            self::assertSame('mdi-eye', $vendorIcons[0]['match']);
        } finally {
            @unlink($source);
            @rmdir($directory);
        }
    }

    public function testRuntimeViewsNamedTestAreAuditedWhileTestFixturesStayExcluded(): void
    {
        $directory = sys_get_temp_dir() . '/weline-ui-audit-paths-' . bin2hex(random_bytes(6));
        $runtimeDirectory = $directory . '/Module/view/templates/Test';
        $fixtureDirectory = $directory . '/Module/Test/Unit';
        self::assertTrue(mkdir($runtimeDirectory, 0777, true));
        self::assertTrue(mkdir($fixtureDirectory, 0777, true));
        $runtime = $runtimeDirectory . '/page.phtml';
        $fixture = $fixtureDirectory . '/Fixture.php';
        file_put_contents($runtime, '<i class="mdi-eye"></i>');
        file_put_contents($fixture, '<i class="mdi-hidden-fixture"></i>');

        try {
            $report = (new AuditService(new AssetManifest()))->auditRepository($directory);
            $vendorIcons = array_values(array_filter(
                $report['violations'],
                static fn (array $violation): bool => $violation['code'] === 'vendor_icon'
            ));

            self::assertCount(1, $vendorIcons);
            self::assertSame('mdi-eye', $vendorIcons[0]['match']);
            self::assertSame(str_replace('\\', '/', $runtime), $vendorIcons[0]['path']);
        } finally {
            @unlink($runtime);
            @unlink($fixture);
            @rmdir($runtimeDirectory);
            @rmdir(dirname($runtimeDirectory));
            @rmdir(dirname(dirname($runtimeDirectory)));
            @rmdir($fixtureDirectory);
            @rmdir(dirname($fixtureDirectory));
            @rmdir($directory . '/Module');
            @rmdir($directory);
        }
    }
}
