<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Widget\Service\WidgetScanner;
use Weline\Widget\Service\WidgetRegistry;
use Weline\Widget\Service\WidgetRegistryRecordService;

final class WidgetScannerCoverageTest extends TestCase
{
    public function testRegistryDoesNotPublishOrSynchronizeAnIncompleteScan(): void
    {
        $scanner = $this->createMock(WidgetScanner::class);
        $scanner->method('scanAllWidgetsGenerator')->willReturn((static function () { if (false) { yield []; } })());
        $scanner->method('getScanCoverage')->willReturn(['complete' => false, 'modules' => [], 'identities' => [], 'errors' => ['read failure']]);
        $records = $this->createMock(WidgetRegistryRecordService::class);
        $records->expects(self::never())->method('sync');
        $result = (new WidgetRegistry($scanner, null, $records))->refreshWithReport();
        self::assertFalse($result['success']);
        self::assertFalse($result['file_saved']);
        self::assertSame(['read failure'], $result['scan_coverage']['errors'] ?? [], 'The scan failure must be reported before publishing is attempted.');
    }

    public function testACompleteEmptyModuleIsCoveredButMalformedDefinitionIsNotAuthoritativeAbsence(): void
    {
        self::assertTrue(method_exists(WidgetScanner::class, 'getScanCoverage'), 'Scanner must distinguish complete absence from swallowed read errors.');
        $base = sys_get_temp_dir() . '/widget-scan-coverage-' . bin2hex(random_bytes(5));
        $directory = $base . '/extends/module/Weline_Widget/Weline_CoverageTest';
        mkdir($directory, 0700, true);
        $property = new ReflectionProperty(Env::class, 'module_list');
        $env = Env::getInstance();
        $original = $property->getValue($env);
        $property->setValue($env, ['Weline_CoverageTest' => ['status' => true, 'base_path' => $base]]);
        try {
            $scanner = new WidgetScanner();
            self::assertSame([], iterator_to_array($scanner->scanAllWidgetsGenerator()));
            self::assertTrue($scanner->getScanCoverage()['complete']);
            self::assertSame(['Weline_CoverageTest'], $scanner->getScanCoverage()['modules']);
            file_put_contents($directory . '/widget.php', '<?php throw new RuntimeException("fixture read failure");');
            iterator_to_array($scanner->scanAllWidgetsGenerator());
            self::assertFalse($scanner->getScanCoverage()['complete']);
            self::assertNotEmpty($scanner->getScanCoverage()['errors']);
            file_put_contents($directory . '/widget.php', '<?php return "invalid definitions";');
            iterator_to_array($scanner->scanAllWidgetsGenerator());
            self::assertFalse($scanner->getScanCoverage()['complete']);
            file_put_contents($directory . '/widget.php', '<?php return ["invalid entry"];');
            iterator_to_array($scanner->scanAllWidgetsGenerator());
            self::assertFalse($scanner->getScanCoverage()['complete']);
        } finally {
            $property->setValue($env, $original);
            @unlink($directory . '/widget.php');
            foreach ([$directory, dirname($directory), dirname($directory, 2), dirname($directory, 3), $base] as $path) { rmdir($path); }
        }
    }
}
