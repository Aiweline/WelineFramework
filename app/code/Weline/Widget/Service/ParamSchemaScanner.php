<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Framework\App\Env;

/**
 * ParamSchema 扫描器
 * 扫描所有已启用模块的 Ui/ParamSchema/*.php，收集语义化参数类型定义
 */
class ParamSchemaScanner
{
    private const SCHEMA_DIR = 'Ui' . DIRECTORY_SEPARATOR . 'ParamSchema';

    /**
     * 扫描所有已启用模块的 ParamSchema 定义
     *
     * @return array<string, array> [ type_code => definition, ... ]
     */
    public function scanAllModules(): array
    {
        if (PHP_SAPI !== 'cli') {
            Env::log_warning('ParamSchemaScanner', 'scanAllModules() 不应在 Web 运行时调用');
            return [];
        }

        $result = [];
        $modules = Env::getInstance()->getModuleList();

        foreach ($modules as $moduleName => $module) {
            if (!($module['status'] ?? false)) {
                continue;
            }
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath)) {
                continue;
            }

            $schemaDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . self::SCHEMA_DIR;
            if (!is_dir($schemaDir)) {
                continue;
            }

            $files = glob($schemaDir . DIRECTORY_SEPARATOR . '*.php');
            if (empty($files)) {
                continue;
            }

            foreach ($files as $file) {
                $typeCode = pathinfo($file, PATHINFO_FILENAME);

                try {
                    $definition = include $file;
                } catch (\Throwable $e) {
                    Env::log_warning('ParamSchemaScanner', "加载 {$moduleName} 的 ParamSchema '{$typeCode}' 失败: {$e->getMessage()}");
                    continue;
                }

                if (!is_array($definition)) {
                    Env::log_warning('ParamSchemaScanner', "{$moduleName}::Ui/ParamSchema/{$typeCode}.php 必须 return 数组");
                    continue;
                }

                if (!isset($definition['base_type']) || !isset($definition['item_schema'])) {
                    Env::log_warning('ParamSchemaScanner', "{$moduleName}::Ui/ParamSchema/{$typeCode}.php 缺少 base_type 或 item_schema");
                    continue;
                }

                $definition['_source_module'] = $moduleName;
                $result[$typeCode] = $definition;
            }
        }

        return $result;
    }
}
