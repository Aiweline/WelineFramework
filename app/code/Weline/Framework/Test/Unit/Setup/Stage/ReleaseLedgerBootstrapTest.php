<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup\Stage;

use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use Weline\Framework\Setup\Stage\FrameworkDbBootstrapStage;

/**
 * RSSE 发布清单排除 weline_schema_* 账本表；全新库上 ReleaseDatabaseStage 第一句就读账本，
 * 所以每张账本表都必须由 FrameworkDbBootstrapStage 先建。
 */
final class ReleaseLedgerBootstrapTest extends TestCase
{
    public function testEveryReleaseLedgerModelIsBootstrappedBeforeReleaseStage(): void
    {
        $bootstrapped = (new ReflectionClassConstant(FrameworkDbBootstrapStage::class, 'OPTIONAL_BOOTSTRAP_MODELS'))->getValue();
        $ledgerDir = \dirname(__DIR__, 5) . '/Database/Model/Release';
        $files = \glob($ledgerDir . '/*.php') ?: [];
        self::assertNotEmpty($files, 'release ledger models not found under ' . $ledgerDir);

        $missing = [];
        foreach ($files as $file) {
            $class = 'Weline\\Database\\Model\\Release\\' . \basename($file, '.php');
            if (!\class_exists($class) || !\defined($class . '::schema_table')) {
                continue;
            }
            if (\str_starts_with((string)\constant($class . '::schema_table'), 'weline_schema_') && !\in_array($class, $bootstrapped, true)) {
                $missing[] = $class;
            }
        }

        self::assertSame([], $missing, 'RSSE ledger models missing from FrameworkDbBootstrapStage::OPTIONAL_BOOTSTRAP_MODELS');
    }

    public function testManifestStillExcludesLedgerPrefix(): void
    {
        $prefixes = (new ReflectionClassConstant(\Weline\Database\Service\Release\SchemaManifestBuilder::class, 'EXCLUDED_TABLE_PREFIXES'))->getValue();
        self::assertContains('weline_schema_', $prefixes);
    }
}
