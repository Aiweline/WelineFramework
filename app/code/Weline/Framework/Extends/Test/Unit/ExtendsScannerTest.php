<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsRegistry;
use Weline\Framework\Extends\ExtendsScanner;
use Weline\Framework\Manager\ObjectManager;

final class ExtendsScannerTest extends TestCase
{
    private ExtendsScanner $scanner;

    protected function setUp(): void
    {
        // 保证环境可用
        if (!defined('BP')) {
            // 单元测试环境通常由 bootstrap_phpunit.php 定义 BP
            $this->markTestSkipped('BP is not defined; ensure phpunit bootstrap sets BP.');
        }
        $this->scanner = new ExtendsScanner();
    }

    public function testScanAllExtendsReturnsArray(): void
    {
        $data = $this->scanner->scanAllExtends();
        $this->assertIsArray($data, 'scanAllExtends() should return array');

        // 模块名格式校验（Vendor_Module）
        foreach ($data as $moduleName => $moduleData) {
            $this->assertMatchesRegularExpression(
                '/^[A-Za-z][A-Za-z0-9_]*_[A-Za-z][A-Za-z0-9_]*$/',
                (string)$moduleName,
                'Module name should be in Vendor_Module format.'
            );
            $this->assertIsArray($moduleData, 'Module data should be array');
            $this->assertArrayHasKey('extends', $moduleData);
            $this->assertArrayHasKey('extended_by', $moduleData);
            $this->assertIsArray($moduleData['extended_by']);
        }
    }

    public function testStickerExtendsDefinitionExists(): void
    {
        $data = $this->scanner->scanAllExtends();
        $this->assertArrayHasKey('Weline_Sticker', $data, 'Weline_Sticker module must be present');

        $sticker = $data['Weline_Sticker'] ?? [];
        $this->assertArrayHasKey('extends', $sticker);
        $this->assertArrayHasKey('extends', $sticker['extends']);
        $this->assertArrayHasKey('Sticker', $sticker['extends']['extends']);

        $stickerConfig = $sticker['extends']['extends']['Sticker'];
        $this->assertSame('extends/module/Weline_Sticker', $stickerConfig['path'] ?? '', 'Sticker path must match extends.php spec');
    }

    public function testStrictVendorModuleRuleForSticker(): void
    {
        // 规则：extends/module/Weline_Sticker/{Vendor}/{Module}/... 严格两段 Vendor/Module
        // 当前仓库中示例路径 app/code/Weline/Sticker/extends/Weline_Sticker/Weline_Sticker/... 并不满足严格两段，
        // 因此在严格模式下，extended_by 不应出现由此路径产生的记录。
        $data = $this->scanner->scanAllExtends();
        $sticker = $data['Weline_Sticker'] ?? [];

        $this->assertArrayHasKey('extended_by', $sticker);
        $this->assertIsArray($sticker['extended_by']);

        // 不断言具体为空（避免未来新增合规用例导致失败），但断言不存在由自模块单段路径产生的记录
        foreach ($sticker['extended_by'] as $sourceModule => $extensions) {
            foreach ($extensions as $ext) {
                if (($ext['is_sticker_extension'] ?? false) === true) {
                    // 目标模块名必须是 Vendor_Module 格式
                    $this->assertMatchesRegularExpression(
                        '/^[A-Za-z][A-Za-z0-9_]*_[A-Za-z][A-Za-z0-9_]*$/',
                        (string)($ext['target_module'] ?? ''),
                        'Sticker targets must be strict Vendor_Module.'
                    );
                }
            }
        }
    }

    public function testScannerRespectsRegisteredModulesFromEnv(): void
    {
        $modules = Env::getInstance()->getModuleList();
        $this->assertIsArray($modules);

        $data = $this->scanner->scanAllExtends();
        // 所有被记录到 registry 的模块，都应该是 Env 中注册过的模块
        foreach (array_keys($data) as $moduleName) {
            $this->assertArrayHasKey($moduleName, $modules, "Scanned module {$moduleName} must be registered in Env.");
        }
    }
}


