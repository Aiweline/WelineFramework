<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\RunType\Bin\Commands;
use Weline\Framework\System\RunType\Db\InstallConfig;
use Weline\Framework\System\RunType\Env\Checker;
use Weline\Framework\System\RunType\System\Init;
use Weline\Framework\System\RunType\System\Install;

class Runner
{
    public function checkEnv(): array
    {
        /**@var $checker Checker */
        $checker = ObjectManager::getInstance(Checker::class);

        return $checker->run();
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
