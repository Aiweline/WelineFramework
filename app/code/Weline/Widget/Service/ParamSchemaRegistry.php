<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Framework\App\Env;

/**
 * ParamSchema 注册表
 * 管理 generated/param_schemas.php 的读取、写入与运行时展开
 */
class ParamSchemaRegistry
{
    private const REGISTRY_FILE = BP . 'generated' . DIRECTORY_SEPARATOR . 'param_schemas.php';

    /** 进程级缓存，无需跨请求重置 */
    private static ?array $staticCachedRegistry = null;
    private static ?int $staticCachedFileMtime = null;

    private ParamSchemaScanner $scanner;

    public function __construct(ParamSchemaScanner $scanner)
    {
        $this->scanner = $scanner;
    }

    /**
     * 获取注册表内容
     */
    public function getRegistry(bool $forceReload = false): array
    {
        if (!$forceReload && self::$staticCachedRegistry !== null) {
            if (!file_exists(self::REGISTRY_FILE)) {
                return self::$staticCachedRegistry;
            }
            $currentMtime = filemtime(self::REGISTRY_FILE);
            $mtimeUnchanged = self::$staticCachedFileMtime !== null && $currentMtime === self::$staticCachedFileMtime;
            if (self::$staticCachedRegistry !== [] && $mtimeUnchanged) {
                return self::$staticCachedRegistry;
            }
            if (self::$staticCachedRegistry === []) {
                if (!$mtimeUnchanged || filesize(self::REGISTRY_FILE) > 100) {
                    // 重载
                } else {
                    return self::$staticCachedRegistry;
                }
            } elseif ($mtimeUnchanged) {
                return self::$staticCachedRegistry;
            }
        }

        if (!file_exists(self::REGISTRY_FILE)) {
            self::$staticCachedRegistry = [];
            self::$staticCachedFileMtime = 0;
            return [];
        }

        $registry = include self::REGISTRY_FILE;
        if (!is_array($registry)) {
            $registry = [];
        }

        self::$staticCachedRegistry = $registry;
        self::$staticCachedFileMtime = file_exists(self::REGISTRY_FILE) ? filemtime(self::REGISTRY_FILE) : 0;

        return $registry;
    }

    /**
     * 刷新注册表（重新扫描并保存）
     */
    public function refresh(): bool
    {
        $scannedData = $this->scanner->scanAllModules();
        return $this->saveRegistry($scannedData);
    }

    /**
     * 展开 params 中的语义化 type
     *
     * 若 param['type'] 在注册表中存在，则用注册表定义展开：
     * type → base_type，并合并 item_schema / sortable / max_items / add_label 等。
     * param 中已存在的同名 key 优先（可覆盖 label / description）。
     *
     * @param array $params 原始参数定义
     * @return array 展开后的参数定义
     */
    public function expandParams(array $params): array
    {
        foreach ($params as $key => $param) {
            if (!is_array($param)) {
                continue;
            }
            $type = (string)($param['type'] ?? '');
            if ($type === '') {
                continue;
            }

            $def = $this->resolveSchemaDefinition($type);
            if ($def === null) {
                continue;
            }

            $expanded = [
                'type' => $def['base_type'],
            ];

            $mergeKeys = ['item_schema', 'sortable', 'max_items', 'add_label', 'min_items', 'default', 'max_depth', 'page_candidates', 'category_candidates', 'seed_pages', 'i18n'];
            foreach ($mergeKeys as $mk) {
                if (isset($def[$mk])) {
                    $expanded[$mk] = $def[$mk];
                }
            }

            // param 中已有的 key 优先（如 label / description / default）
            $params[$key] = array_merge($expanded, $param);
            // type / ui_type / input 必须使用 base_type，不能被原 param 的语义 type 覆盖
            $baseType = (string)$def['base_type'];
            $params[$key]['type'] = $baseType;
            $params[$key]['ui_type'] = $baseType;
            $params[$key]['input'] = $baseType;
            // 保留原始语义化 type 以备 API / 前端识别
            $params[$key]['schema_type'] = $type;
        }

        return $params;
    }

    /**
     * 解析语义化 ParamSchema（注册表缺失时在 Web 运行时按 type 懒加载模块 Ui/ParamSchema/*.php）
     */
    private function resolveSchemaDefinition(string $type): ?array
    {
        $registry = $this->getRegistry();
        if (isset($registry[$type]) && is_array($registry[$type])) {
            return $registry[$type];
        }

        return $this->loadSchemaDefinitionFromModules($type);
    }

    /**
     * Web/CLI 均可：仅加载单个 schema 文件，避免全量 scanAllModules()
     */
    private function loadSchemaDefinitionFromModules(string $type): ?array
    {
        static $runtimeCache = [];

        if (array_key_exists($type, $runtimeCache)) {
            return $runtimeCache[$type];
        }

        $type = trim($type);
        if ($type === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $type)) {
            $runtimeCache[$type] = null;
            return null;
        }

        try {
            $modules = Env::getInstance()->getModuleList();
        } catch (\Throwable) {
            $runtimeCache[$type] = null;
            return null;
        }

        foreach ($modules as $moduleName => $module) {
            if (!($module['status'] ?? false)) {
                continue;
            }
            $basePath = (string)($module['base_path'] ?? '');
            if ($basePath === '') {
                continue;
            }

            $file = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'Ui' . DIRECTORY_SEPARATOR . 'ParamSchema' . DIRECTORY_SEPARATOR . $type . '.php';
            if (!is_file($file)) {
                continue;
            }

            try {
                $definition = include $file;
            } catch (\Throwable $e) {
                w_log_warning("懒加载 {$moduleName} 的 ParamSchema '{$type}' 失败: {$e->getMessage()}", [], 'ParamSchemaRegistry');
                continue;
            }

            if (!is_array($definition) || !isset($definition['base_type'], $definition['item_schema'])) {
                continue;
            }

            $definition['_source_module'] = $moduleName;
            $runtimeCache[$type] = $definition;

            return $definition;
        }

        $runtimeCache[$type] = null;
        return null;
    }

    private function saveRegistry(array $registry): bool
    {
        try {
            $registryDir = dirname(self::REGISTRY_FILE);
            if (!is_dir($registryDir)) {
                mkdir($registryDir, 0755, true);
            }

            $content = "<?php\n";
            $content .= "/**\n";
            $content .= " * ParamSchema 注册表\n";
            $content .= " * 此文件由系统自动生成，请勿手动修改\n";
            $content .= " * 生成时间: " . date('Y-m-d H:i:s') . "\n";
            $content .= " */\n\n";
            $content .= "return " . var_export($registry, true) . ";\n";

            $result = file_put_contents(self::REGISTRY_FILE, $content, LOCK_EX);

            if ($result !== false) {
                self::$staticCachedRegistry = null;
                self::$staticCachedFileMtime = null;
                return true;
            }

            return false;
        } catch (\Exception $e) {
            w_log_error('保存 ParamSchema 注册表失败: ' . $e->getMessage(), [], 'ParamSchemaRegistry');
            return false;
        }
    }
}
