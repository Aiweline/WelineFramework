<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/6/23 11:50:30
 */

namespace Weline\UrlManager\Plugin;

use Weline\Framework\App\Env;
use Weline\ModuleManager\Model\Module;
use Weline\UrlManager\Model\UrlManager;

class ModuleUpgradeExecuteAfterPlugin
{
    private $module =  null;
    private $urlManager =  null;
    /** @var array<string, int> */
    private array $moduleIdCache = [];
    function __construct(Module $module,UrlManager $urlManager)
    {
        $this->module = $module;
        $this->urlManager = $urlManager;
    }

    function afterExecute()
    {
        # 按类型分段处理并及时释放内存，避免升级阶段峰值过高
        $this->syncRoutesFromFile(Env::path_FRONTEND_PC_ROUTER_FILE, 'frontend_pc');
        $this->syncRoutesFromFile(Env::path_FRONTEND_REST_API_ROUTER_FILE, 'frontend_rest');
        $this->syncRoutesFromFile(Env::path_BACKEND_PC_ROUTER_FILE, 'backend_pc');
        $this->syncRoutesFromFile(Env::path_BACKEND_REST_API_ROUTER_FILE, 'backend_rest');
    }

    private function syncRoutesFromFile(string $filePath, string $type): void
    {
        if (!is_file($filePath)) {
            return;
        }

        $urls = include $filePath;
        if (!is_array($urls)) {
            unset($urls);
            gc_collect_cycles();
            return;
        }

        foreach ($urls as $path => $urlConfig) {
            if (!is_array($urlConfig)) {
                continue;
            }
            $moduleName = (string)($urlConfig['module'] ?? '');
            if ($moduleName === '') {
                continue;
            }
            $module_id = $this->getModuleIdByName($moduleName);
            if (!$module_id) {
                continue;
            }
            $this->urlManager->recovery();
            $this->urlManager
                ->setData('module_id', $module_id)
                ->setData('path', $path)
                ->setData('identify', md5($path . $type), true)
                ->setData('data', json_encode($urlConfig))
                ->setData('type', $type)
                ->save();
        }

        unset($urls);
        gc_collect_cycles();
    }

    private function getModuleIdByName(string $moduleName): int
    {
        if (isset($this->moduleIdCache[$moduleName])) {
            return $this->moduleIdCache[$moduleName];
        }

        $this->module->recovery();
        $moduleId = (int)$this->module->load('name', $moduleName)->getId();
        $this->moduleIdCache[$moduleName] = $moduleId;
        return $moduleId;
    }
}
