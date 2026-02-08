<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Installer;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Env\Api\EnvCheckerInterface;
use Weline\Framework\Env\Api\EnvRequirementsCollectorInterface;
use Weline\Framework\Env\Service\EnvChecker;
use Weline\Framework\Env\Service\EnvRequirementsCollector;
use Weline\Installer\RunType\Bin\Commands;
use Weline\Installer\RunType\Db\InstallConfig;
use Weline\Installer\RunType\System\Init;
use Weline\Installer\RunType\System\Install;

class Runner
{
    /**
     * 检测环境（使用新的 Framework\Env 组件）
     * 
     * @return array 兼容旧格式的返回值
     */
    public function checkEnv(): array
    {
        /** @var EnvRequirementsCollectorInterface $collector */
        $collector = ObjectManager::getInstance(EnvRequirementsCollector::class);
        
        /** @var EnvCheckerInterface $checker */
        $checker = ObjectManager::getInstance(EnvChecker::class);
        
        // 收集并检测
        $requirements = $collector->collect();
        $checker->setRequirements($requirements);
        $result = $checker->check();
        
        // 转换为旧格式以保持兼容
        return [
            'data' => $result->getDetails(),
            'hasErr' => $result->hasError(),
            'msg' => $result->getMessage(),
            'result' => $result, // 新增：完整的结果对象
        ];
    }

    public function installDb(array $params = []): array
    {
        if (!CLI) {
            /**@var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $params  = $request->getParams();
        }
        /**@var $installConfig InstallConfig */
        $installConfig = ObjectManager::getInstance(InstallConfig::class);

        return $installConfig->run($params);
    }

    public function systemCommands(): array
    {
        /**@var $commands Commands */
        $commands = ObjectManager::getInstance(Commands::class);
        return $commands->run();
    }

    public function systemInit(array $params = []): array
    {
        if (!CLI) {
            /**@var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $params  = $request->getParams();
        }
        /**@var $init Init */
        $init = ObjectManager::getInstance(Init::class);

        return $init->run($params);
    }
}
