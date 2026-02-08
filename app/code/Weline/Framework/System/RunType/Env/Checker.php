<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\RunType\Env;

use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Helper\Data;

/**
 * @deprecated 此类已废弃，请使用 \Weline\Framework\Env\Service\EnvChecker 代替
 * 
 * 迁移指南：
 * 1. 使用 EnvRequirementsCollector 收集环境需求
 * 2. 使用 EnvChecker::setRequirements() 设置需求
 * 3. 调用 EnvChecker::check() 获取 EnvCheckResult 结果
 * 
 * 示例：
 * ```php
 * $collector = ObjectManager::getInstance(\Weline\Framework\Env\Service\EnvRequirementsCollector::class);
 * $checker = ObjectManager::getInstance(\Weline\Framework\Env\Service\EnvChecker::class);
 * $requirements = $collector->collect();
 * $checker->setRequirements($requirements);
 * $result = $checker->check();
 * if ($result->hasError()) {
 *     // 处理错误
 * }
 * ```
 * 
 * @see \Weline\Framework\Env\Api\EnvCheckerInterface
 * @see \Weline\Framework\Env\Service\EnvChecker
 * @see \Weline\Framework\Env\Service\EnvRequirementsCollector
 */
class Checker
{
    public const type_MODULES = 'modules';

    public const type_FUNCTIONS = 'functions';

    public const type_CONFIG = 'config';

    protected Data $helper;

    private Printing $printer;

    protected array $canCheck = ['modules', 'functions'];

    protected array $module = [];

    protected array $method = [];

    private bool $isCli;

    public function __construct()
    {
        $this->helper  = new Data();
        $this->isCli   = (PHP_SAPI === 'cli');
        $this->printer = new Printing();
    }

    public function run()
    {
        // 循环检测
        foreach ($this->helper->getCheckEnv() as $type => $data) {
            $this->setNeed($type, $data);
        }

        return $this->check();
    }

    public function setNeed(string $type, $value)
    {
        if (!in_array($type, $this->canCheck, true)) {
            $this->canCheck[] = $type;
        }
        if (is_array($value)) {
            $this->$type = $value;
        } else {
            $this->$type[] = $value;
        }

        return $this;
    }

    public function check(): array
    {
        $tmp    = [];
        $hasErr = false;
        foreach ($this->canCheck as $item) {
            if (is_array($this->$item)) {
                switch ($item) {
                    case self::type_FUNCTIONS:
                        $disable_functions = ini_get('disable_functions');
                        $disable_functions = explode(',', $disable_functions);
                        foreach ($this->$item as $i) {
                            if (in_array($i, $disable_functions, true)) {
                                $hasErr = true;
                                $key    = str_pad($item . '---' . $i, 45, '-', STR_PAD_BOTH);
                                $value  = str_pad('✖', 10, ' ', STR_PAD_BOTH);
                                if ($this->isCli) {
                                    $this->printer->error($key . '=>' . $value);
                                }
                                $tmp[$key] = $value;
                            } else {
                                $key   = str_pad($item . '---' . $i, 45, '-', STR_PAD_BOTH);
                                $value = str_pad('✔', 10, ' ', STR_PAD_BOTH);
                                if ($this->isCli) {
                                    $this->printer->success($key . '=>' . $value);
                                }
                                $tmp[$key] = $value;
                            }
                        }

                        break;
                    case self::type_MODULES:
                        $modules = get_loaded_extensions();
                        foreach ($this->$item as $needModule) {
                            if (in_array($needModule, $modules, true)) {
                                $key   = str_pad($item . '---' . $needModule, 45, '-', STR_PAD_BOTH);
                                $value = str_pad('✔', 10, ' ', STR_PAD_BOTH);
                                if ($this->isCli) {
                                    $this->printer->success($key . '=>' . $value);
                                }
                                $tmp[$key] = $value;
                            } else {
                                $hasErr = true;
                                $key    = str_pad($item . '---' . $needModule, 45, '-', STR_PAD_BOTH);
                                $value  = str_pad('✖', 10, ' ', STR_PAD_BOTH);
                                if ($this->isCli) {
                                    $this->printer->error($key . '=>' . $value);
                                }
                                $tmp[$key] = $value;
                            }
                        }

                        break;
                    default:
                        $tmp['ERR: ' . $item] = '不存在的检测类型！（✖）';
                }
            }
        }

        return ['data' => $tmp, 'hasErr' => $hasErr, 'msg' => '-------  环境初始化检测中...  -------'];
    }
}
