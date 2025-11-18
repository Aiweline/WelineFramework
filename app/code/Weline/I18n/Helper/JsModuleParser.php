<?php
declare(strict_types=1);

namespace Weline\I18n\Helper;

/**
 * 工具：解析模板中的 JS 模块声明信息
 */
class JsModuleParser
{
    /**
     * 从内容中提取 Weline/Theme 声明的模块
     */
    public static function extractDeclaredModules(string $content): array
    {
        $modules = [];

        if (empty($content) || strpos($content, 'declare') === false) {
            return $modules;
        }

        // 单个模块：Weline.declare('module')
        if (preg_match_all('/(?:Weline|Theme)\.declare\s*\(\s*["\']([^"\']+)["\']/', $content, $matches)) {
            foreach ($matches[1] as $moduleName) {
                $moduleName = trim($moduleName);
                if ($moduleName !== '') {
                    $modules[] = $moduleName;
                }
            }
        }

        // 数组形式：Weline.declare(['module1', 'module2'])
        if (preg_match_all('/(?:Weline|Theme)\.declare\s*\(\s*\[([^\]]+)\]/', $content, $arrayMatches)) {
            foreach ($arrayMatches[1] as $arrayContent) {
                if (preg_match_all('/["\']([^"\']+)["\']/', $arrayContent, $moduleMatches)) {
                    foreach ($moduleMatches[1] as $moduleName) {
                        $moduleName = trim($moduleName);
                        if ($moduleName !== '') {
                            $modules[] = $moduleName;
                        }
                    }
                }
            }
        }

        // data-weline-load="module1,module2"
        if (preg_match_all('/data-weline-load\s*=\s*["\']([^"\']+)["\']/', $content, $dataMatches)) {
            foreach ($dataMatches[1] as $moduleList) {
                $moduleArray = array_map('trim', explode(',', $moduleList));
                foreach ($moduleArray as $moduleName) {
                    if ($moduleName !== '') {
                        $modules[] = $moduleName;
                    }
                }
            }
        }

        return array_values(array_unique($modules));
    }

    /**
     * 根据文件路径推测区域：frontend / backend
     */
    public static function detectAreaFromPath(?string $filePath): string
    {
        if (empty($filePath)) {
            return 'frontend';
        }

        if (stripos($filePath, 'backend') !== false) {
            return 'backend';
        }

        return 'frontend';
    }
}

