<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\Console\Route;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;

/**
 * 路由列表命令
 * 由于 List 是 PHP 保留关键字，使用 Listing 作为类名
 * 命令：route:listing (可以通过 route:list 调用)
 */
class Listing implements CommandInterface
{
    /**
     * 命令别名
     * 定义此常量可以为命令添加别名，例如：route:list 可以调用此命令
     */
    public const ALIASES = ['route:list'];

    private Printing $printing;

    public function __construct(
        Printing $printing
    ) {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $routerFiles = [
            '后台 REST API' => Env::path_BACKEND_REST_API_ROUTER_FILE,
            '前端 REST API' => Env::path_FRONTEND_REST_API_ROUTER_FILE,
            '后台 PC' => Env::path_BACKEND_PC_ROUTER_FILE,
            '前端 PC' => Env::path_FRONTEND_PC_ROUTER_FILE,
        ];

        $allRoutes = [];
        $totalCount = 0;

        foreach ($routerFiles as $type => $filePath) {
            if (!file_exists($filePath)) {
                continue;
            }

            try {
                $routes = include $filePath;
                if (!is_array($routes)) {
                    continue;
                }
            } catch (\Throwable $e) {
                // 如果路由文件有错误，跳过
                continue;
            }

            $allRoutes[$type] = $routes;
            $totalCount += count($routes);
        }

        if (empty($allRoutes)) {
            $this->printing->warning(__('未找到任何路由文件。请先运行路由生成命令。'));
            return;
        }

        // 显示标题
        $this->printing->note(__('路由列表 (共 %{1} 条)', [$totalCount]));
        $this->printing->printing(str_repeat('═', 120) . PHP_EOL);

        // 按类型显示路由
        foreach ($allRoutes as $type => $routes) {
            if (empty($routes)) {
                continue;
            }

            $this->printing->printing(PHP_EOL);
            $this->printing->printing($this->printing->colorize('【' . $type . '】', 'Cyan'));
            $this->printing->printing(' (' . count($routes) . ' 条)' . PHP_EOL);
            $this->printing->printing(str_repeat('─', 120) . PHP_EOL);

            // 表头
            $header = sprintf(
                "%-50s %-15s %-50s",
                __('路由路径'),
                __('HTTP方法'),
                __('控制器')
            );
            $this->printing->printing($this->printing->colorize($header, 'Yellow') . PHP_EOL);
            $this->printing->printing(str_repeat('─', 120) . PHP_EOL);

            // 显示路由
            foreach ($routes as $routePath => $routeData) {
                // 解析路由路径和方法
                $path = $routePath;
                $method = 'ALL';
                
                if (str_contains($routePath, '::')) {
                    [$path, $method] = explode('::', $routePath, 2);
                    $method = strtoupper($method);
                }

                // 获取控制器信息
                $controller = '';
                if (isset($routeData['class']['name'])) {
                    $controller = $routeData['class']['name'];
                    if (isset($routeData['class']['method'])) {
                        $controller .= '::' . $routeData['class']['method'];
                    }
                } elseif (isset($routeData['module'])) {
                    $controller = $routeData['module'];
                }

                // 格式化输出
                $line = sprintf(
                    "%-50s %-15s %-50s",
                    $this->truncateString($path, 48),
                    $method,
                    $this->truncateString($controller, 48)
                );
                $this->printing->printing($line . PHP_EOL);
            }
        }

        $this->printing->printing(PHP_EOL);
        $this->printing->printing(str_repeat('═', 120) . PHP_EOL);
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '列出所有路由';
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'route:list',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '列出所有路由' => 'php bin/w route:list',
            ]
        );
    }

    /**
     * 截断字符串到指定长度
     *
     * @param string $string
     * @param int $length
     * @return string
     */
    private function truncateString(string $string, int $length): string
    {
        if (mb_strlen($string, 'UTF-8') <= $length) {
            return $string;
        }
        return mb_substr($string, 0, $length - 3, 'UTF-8') . '...';
    }
}

