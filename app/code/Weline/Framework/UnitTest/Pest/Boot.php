<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\UnitTest\Pest;

use Weline\Framework\App\Exception;

/**
 * Pest 测试框架启动类
 * 用于在应用启动时自动加载测试框架
 */
class Boot
{
    /**
     * @DESC         |启动 Pest 测试框架
     *
     * 参数区：
     * @param array $args 命令行参数
     * @return bool 是否成功启动
     * @throws Exception
     */
    public static function boot(array $args = []): bool
    {
        // 检查是否启用了测试模式
        $enableTest = self::shouldEnableTest($args);
        
        if (!$enableTest) {
            return false;
        }

        // 初始化 Pest
        try {
            Pest::init();
            return true;
        } catch (Exception $e) {
            throw new Exception(__('Pest 测试框架初始化失败: %{1}', $e->getMessage()));
        }
    }

    /**
     * @DESC         |判断是否应该启用测试模式
     *
     * 参数区：
     * @param array $args 命令行参数
     * @return bool
     */
    private static function shouldEnableTest(array $args = []): bool
    {
        // 检查命令行参数
        if (PHP_SAPI === 'cli') {
            // 检查 --test 或 -t 参数
            if (isset($args['test']) || isset($args['t'])) {
                return true;
            }
            
            // 检查环境变量
            if (getenv('WELINE_ENABLE_TEST') === '1' || getenv('WELINE_ENABLE_TEST') === 'true') {
                return true;
            }
            
            // 检查是否在运行 Pest 命令
            global $argv;
            if (isset($argv) && is_array($argv)) {
                foreach ($argv as $arg) {
                    if (strpos($arg, 'pest') !== false || strpos($arg, 'vendor/bin/pest') !== false) {
                        return true;
                    }
                }
            }
        }

        // 检查 ENV_TEST 常量
        if (defined('ENV_TEST') && ENV_TEST === true) {
            return true;
        }

        // 检查 $_SERVER 变量（用于 Web 环境）
        if (isset($_SERVER['WELINE_ENABLE_TEST']) && $_SERVER['WELINE_ENABLE_TEST'] === '1') {
            return true;
        }

        return false;
    }
}
