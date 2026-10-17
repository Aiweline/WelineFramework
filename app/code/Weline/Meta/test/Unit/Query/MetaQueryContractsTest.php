<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionMethod as ReflectionMethod;
use Weline\Meta\Controller\Backend\Config\File as ConfigFileController;
use Weline\Meta\Controller\Backend\Meta as MetaController;
use Weline\Meta\Controller\Backend\Taglib\Meta as TaglibMetaController;
use Weline\Meta\Extends\Module\Weline_Framework\Query\MetaAdminQueryProvider;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMetaQueryContractsPass().
 */
final class MetaQueryContractsTest extends TestCase
{
    public function testAllMetaQueryContractsPass(): void
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
            'MetaAdminQueryProviderNestedPath::testAdminRequestRejectsUnknownNestedPath' => function () {
            $this->assert_MetaAdminQueryProviderNestedPath_testAdminRequestRejectsUnknownNestedPath();
        },
        ];
    }

/**
 * @return array<string, array{0:string,1:string,2:string}>
 */
private function nestedPathProvider_MetaAdminQueryProviderNestedPath(): array
{
    return ['config_file_tree' => ['/meta/backend/config/file/tree', ConfigFileController::class, 'tree'], 'config_file_fileMeta' => ['/meta/backend/config/file/fileMeta', ConfigFileController::class, 'fileMeta'], 'config_file_index' => ['/meta/backend/config/file', ConfigFileController::class, 'index'], 'taglib_meta_get' => ['/meta/backend/taglib/meta/get', TaglibMetaController::class, 'get'], 'flat_meta_index' => ['/meta/backend/meta', MetaController::class, 'index']];
}

private function assert_MetaAdminQueryProviderNestedPath_testAdminRequestRejectsUnknownNestedPath(): void
{
    $provider = new MetaAdminQueryProvider();
    $unknown = $provider->execute('adminRequest', ['url' => '/meta/backend/not-a-real/controller/path', 'method' => 'GET']);
    self::assertIsArray($unknown);
    self::assertFalse($unknown['success'] ?? true);
    self::assertStringContainsString('Unsupported admin path', (string) ($unknown['message'] ?? ''));
}
}
