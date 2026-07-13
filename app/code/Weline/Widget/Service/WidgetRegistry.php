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
use Weline\Framework\Manager\ObjectManager;
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
     * @param bool $forceReload 强制重新加载
     * @return array
     */
    public function getRegistry(bool $forceReload = false): array
    {
        // 使用静态缓存，跨实例共享，避免每次请求都读取文件
        if (!$forceReload && self::$staticCachedRegistry !== null) {
            if (!file_exists(self::REGISTRY_FILE)) {
                return $this->mergeAiWidgets(self::$staticCachedRegistry);
            }
            $currentMtime = filemtime(self::REGISTRY_FILE);
            $mtimeUnchanged = self::$staticCachedFileMtime !== null && $currentMtime === self::$staticCachedFileMtime;
            // 缓存非空且 mtime 未变：直接返回
            if (self::$staticCachedRegistry !== [] && $mtimeUnchanged) {
                return $this->mergeAiWidgets(self::$staticCachedRegistry);
            }
            // 缓存为空：若文件可能已有内容（mtime 变了或文件较大）则重载，否则返回空
            if (self::$staticCachedRegistry === []) {
                if (!$mtimeUnchanged || filesize(self::REGISTRY_FILE) > 100) {
                    // 重载
                } else {
                    return $this->mergeAiWidgets(self::$staticCachedRegistry);
                }
            } elseif ($mtimeUnchanged) {
                return $this->mergeAiWidgets(self::$staticCachedRegistry);
            }
        }

        if (!file_exists(self::REGISTRY_FILE)) {
            self::$staticCachedRegistry = [];
            self::$staticCachedFileMtime = 0;
            return $this->mergeAiWidgets([]);
        }

        $registry = include self::REGISTRY_FILE;
        if (!is_array($registry)) {
            $registry = [];
        }

        // 更新静态缓存
        self::$staticCachedRegistry = $registry;
        self::$staticCachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;

        return $this->mergeAiWidgets($registry);
    }

    public static function clearRuntimeCache(): void
    {
        self::$staticCachedRegistry = null;
        self::$staticCachedFileMtime = null;
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
        $saved = $this->saveRegistry($registry);
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
        ];
        if (!$saved) {
            return $report;
        }

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
            $registryDir = dirname(self::REGISTRY_FILE);
            if (!is_dir($registryDir)) {
                mkdir($registryDir, 0755, true);
            }
            $fh = fopen(self::REGISTRY_FILE, 'wb');
            if ($fh === false) {
                return false;
            }
            fwrite($fh, "<?php\n");
            fwrite($fh, "/**\n * 部件注册表\n * 此文件由系统自动生成，请勿手动修改\n * 生成时间: " . date('Y-m-d H:i:s') . "\n */\n\nreturn [\n");
            $firstType = true;
            foreach ($registry as $type => $widgets) {
                if (!$firstType) {
                    fwrite($fh, ",\n");
                }
                $firstType = false;
                fwrite($fh, var_export($type, true) . " => [\n");
                $firstName = true;
                foreach (is_array($widgets) ? $widgets : [] as $name => $config) {
                    if (!$firstName) {
                        fwrite($fh, ",\n");
                    }
                    $firstName = false;
                    fwrite($fh, '    ' . var_export($name, true) . ' => ' . var_export($config, true));
                }
                fwrite($fh, "\n]");
            }
            fwrite($fh, "\n];\n");
            fclose($fh);
            self::clearRuntimeCache();
            return true;
        } catch (\Exception $e) {
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
