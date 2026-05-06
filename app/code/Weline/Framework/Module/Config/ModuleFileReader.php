<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Config;

use Weline\Framework\App\Env;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Module\Service\ModuleScanService;
use Weline\Framework\Register\Register;
use Weline\Framework\System\File\Scan;

class ModuleFileReader extends Scan
{
    private ModuleScanService $moduleScanService;

    public function __construct($moduleScanService = null)
    {
        $this->moduleScanService = $moduleScanService instanceof ModuleScanService
            ? $moduleScanService
            : new ModuleScanService($this);
    }

    public function read(string $module_name, string $dir = ''): array
    {
        $base_path = Env::getInstance()->getModuleInfo($module_name)['base_path'] ?? '';
        if ($base_path) {
            return $this->moduleScanService->scanDirTreeIfExists($base_path, $dir);
        } else {
            # 如果没有模块可能是第一次安装模块
            # app 内部的模块
            $base_path = APP_PATH . str_replace('_', DS, $module_name) . DS;
            $app_data  = $this->moduleScanService->scanDirTreeIfExists($base_path, $dir);
            # vendor 内部的模块
            $vendor_data = $this->moduleScanService->scanDirTreeIfExists(
                VENDOR_PATH . Register::convertToComposerName($module_name) . DS,
                $dir
            );
            return array_merge($app_data, $vendor_data);
        }
    }

    public function readClass(Module $module, string $dir = ''): array
    {
        $base_path = $module->getBasePath();
        $files = $this->moduleScanService->globPhpClassesInDirectory(
            $base_path,
            $dir,
            $module->getNamespacePath(),
            true,
            true,
            $base_path
        );
        // Weline_Framework 子目录（Setup、Session 等）可拥有 Model 目录，其 ORM 在 setup:upgrade 时由 SchemaDiffStage 处理
        if('Weline_Framework' == $module->getName() && $this->moduleScanService->resolveDirectory($base_path, '') !== null) {
            foreach (glob(rtrim($base_path, '/\\') . DS . '*', GLOB_ONLYDIR) ?: [] as $framework_module_path) {
                $framework_module = basename($framework_module_path);
                $tmp_files = $this->moduleScanService->globPhpClassesInDirectory(
                    $framework_module_path,
                    $dir,
                    'Weline\\Framework\\' . $framework_module,
                    true,
                    true,
                    $base_path
                );
                $files = array_merge($files, $tmp_files);
            }
        }
        return $files;
    }
}
