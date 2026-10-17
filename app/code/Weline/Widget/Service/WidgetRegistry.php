<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Compilation\CompiledPhpRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\GeneratedPhpArrayPublisher;
use Weline\Framework\Runtime\Runtime;
use Weline\Widget\Api\WidgetRegistryInterface;

/**
 * 部件注册表管理
 * 管理 generated/widgets.php 文件的读取和写入
 */
class WidgetRegistry implements WidgetRegistryInterface
{
    private const REGISTRY_FILE = BP . 'generated' . DIRECTORY_SEPARATOR . 'widgets.php';

    // 使用静态缓存，跨实例共享，提升性能
    private static ?array $staticCachedRegistry = null;
    private static ?int $staticCachedFileMtime = null;
    
    private WidgetScanner $scanner;
    private ?AiWidgetRegistrySource $aiWidgetRegistrySource;
    private ?WidgetRegistryRecordService $recordService;

    public function __construct(
        WidgetScanner $scanner,
        ?AiWidgetRegistrySource $aiWidgetRegistrySource = null,
        ?WidgetRegistryRecordService $recordService = null
    ) {
        $this->scanner = $scanner;
        $this->aiWidgetRegistrySource = $aiWidgetRegistrySource;
        $this->recordService = $recordService;
    }

    /**
     * 获取注册表内容
     *
     * @param bool $forceReload 强制重新加载（persistent / WLS 下为 no-op，禁止二次 include）
     * @return array
     */
    public function getRegistry(bool $forceReload = false): array
    {
        // Process gate: once loaded under WLS/persistent, mtime / forceReload must not re-include.
        if ($forceReload && !Runtime::isPersistent()) {
            CompiledPhpRegistry::forgetForTests(self::REGISTRY_FILE);
            self::$staticCachedRegistry = null;
            self::$staticCachedFileMtime = null;
        }

        $registry = CompiledPhpRegistry::load(self::REGISTRY_FILE) ?? [];
        self::$staticCachedRegistry = $registry;
        self::$staticCachedFileMtime = \is_file(self::REGISTRY_FILE)
            ? (int) (@\filemtime(self::REGISTRY_FILE) ?: 0)
            : 0;

        return $this->mergeAiWidgets($registry);
    }

    public static function clearRuntimeCache(): void
    {
        self::$staticCachedRegistry = null;
        self::$staticCachedFileMtime = null;
        // Persistent Workers keep CompiledPhpRegistry bag (no second include).
        if (!Runtime::isPersistent()) {
            CompiledPhpRegistry::forgetForTests(self::REGISTRY_FILE);
        }
        WidgetData::clearCache();
    }

    /**
     * 刷新注册表
     *
     * @return bool
     */
    public function refresh(): bool
    {
        $report = $this->refreshWithReport();
        return (bool)($report['success'] ?? false);
    }

    /**
     * 刷新注册表并同步普通 Widget DB 注册账本。
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function refreshWithReport(array $context = []): array
    {
        $registry = $this->buildRegistryFromGenerator($this->scanner->scanAllWidgetsGenerator());
        $coverage = $this->scanner->getScanCoverage();
        // A skipped or failed definition read is not an authoritative empty registry.
        $saved = ($coverage['complete'] ?? false) === true && $this->saveRegistry($registry);
        $report = [
            'success' => $saved,
            'file_saved' => $saved,
            'db_available' => null,
            'created_widgets' => [],
            'updated_widgets' => [],
            'created_default_injection_widgets' => [],
            'created_count' => 0,
            'updated_count' => 0,
            'created_default_injection_count' => 0,
            'scan_coverage' => $coverage,
            'retired_widgets' => [],
            'retired_count' => 0,
        ];
        if (!$saved) {
            return $report;
        }

        $context['scan_coverage'] = $coverage;
        $recordReport = $this->recordService()->sync($registry, $context);
        foreach ($recordReport as $key => $value) {
            $report[$key] = $value;
        }

        return $report;
    }

    /**
     * 从 (type, name, config) 生成器按 type 聚合。
     *
     * @param \Generator<int, array{0: string, 1: string, 2: array}, void, void> $items
     * @return array<string,array<string,array<string,mixed>>>
     */
    private function buildRegistryFromGenerator(\Generator $items): array
    {
        $registry = [];
        foreach ($items as [$type, $name, $config]) {
            if (!isset($registry[$type]) || !is_array($registry[$type])) {
                $registry[$type] = [];
            }
            $registry[$type][$name] = $config;
        }

        return $registry;
    }

    /**
     * 保存注册表到文件（兼容：传入完整数组时使用，如 Web 或测试）
     *
     * @param array $registry 注册表数据
     * @return bool
     */
    private function saveRegistry(array $registry): bool
    {
        try {
            $content = "<?php\n";
            $content .= "/**\n * 部件注册表\n * 此文件由系统自动生成，请勿手动修改\n * 生成时间: " . date('Y-m-d H:i:s') . "\n */\n\n";
            $content .= "return " . var_export($registry, true) . ";\n";

            (new GeneratedPhpArrayPublisher())->publishContent(
                self::REGISTRY_FILE,
                $content,
                $registry,
                GeneratedPhpArrayPublisher::countNestedLeaves(...),
            );
            self::clearRuntimeCache();
            return true;
        } catch (\Throwable $e) {
            w_log_error("保存部件注册表失败: " . $e->getMessage(), [], 'WidgetRegistry');
            return false;
        }
    }

    private function mergeAiWidgets(array $registry): array
    {
        try {
            $source = $this->aiWidgetRegistrySource ?? ObjectManager::getInstance(AiWidgetRegistrySource::class);
            $aiRegistry = $source->getRegistryEntries();
        } catch (\Throwable $e) {
            w_log_error('合并 AI Widget 注册表失败: ' . $e->getMessage(), [], 'WidgetRegistry');
            return $registry;
        }

        foreach ($aiRegistry as $type => $widgets) {
            if (!is_array($widgets)) {
                continue;
            }
            if (!isset($registry[$type]) || !is_array($registry[$type])) {
                $registry[$type] = [];
            }
            foreach ($widgets as $code => $widget) {
                if (is_string($code) && is_array($widget)) {
                    $registry[$type][$code] = $widget;
                }
            }
        }

        return $registry;
    }

    private function recordService(): WidgetRegistryRecordService
    {
        if (!$this->recordService) {
            $this->recordService = ObjectManager::getInstance(WidgetRegistryRecordService::class);
        }

        return $this->recordService;
    }
}
