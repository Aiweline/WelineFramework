<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Resource;

use Weline\Framework\Cache\Console\Cache\Clear;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Resource\CompilerInterface;

/**
 * 静态资源编译命令
 * 
 * 编译所有模块的静态资源，包括：
 * - weline.modules.js：合并所有模块的模块配置文件
 * - less：编译 Less 样式文件
 * - 其他自定义资源类型
 */
class Compile extends CommandAbstract
{
    private Printing $printing;

    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): void
    {
        $source_types = [];
        
        // 移除命令名（第一个参数）
        array_shift($args);
        
        // 提取资源类型参数
        $valid_types = array_keys($this->getTypes());
        foreach ($args as $key => $arg) {
            // 跳过非数字键（如 'command', 'h', 'help' 等）
            if (!is_numeric($key)) {
                continue;
            }
            // 检查是否是有效的资源类型
            if (in_array($arg, $valid_types)) {
                $source_types[] = $arg;
            } else {
                $this->printing->error(__('不存在的编译资源类型：%{1}，支持的资源类型：%{2}', [$arg, $this->getTypes(true)]));
                exit(1);
            }
        }
        
        $this->printing->note(__('开始编译静态资源...'));
        
        if (empty($source_types)) {
            // 如果没有指定类型，编译所有类型
            foreach ($this->getTypes() as $key => $type) {
                $this->printing->warning($key . ': ' . $type . __('编译中...'));
                $this->compileResourceType($key);
            }
        } else {
            // 编译指定的类型
            foreach ($source_types as $source_type) {
                $this->printing->warning($source_type . ': ' . ($this->getTypes()[$source_type] ?? '') . __('编译中...'));
                $this->compileResourceType($source_type);
            }
        }
        
        $this->printing->success(__('静态资源编译完成！'));
        
        // 清理缓存
        ObjectManager::getInstance(Clear::class)->execute();
    }

    /**
     * 编译指定类型的资源
     * 
     * @param string $type 资源类型
     */
    private function compileResourceType(string $type): void
    {
        try {
            // 将键名转换为类名：welineModules -> WelineModules, less -> Less
            $type = preg_replace('/([a-z])([A-Z])/', '$1 $2', $type);
            $className = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $type)));
            
            // 尝试从 Theme 模块加载编译器
            $compilerClass = "Weline\\Theme\\Console\\Resource\\Compiler\\{$className}";
            if (class_exists($compilerClass)) {
                /**@var CompilerInterface $compiler */
                $compiler = ObjectManager::getInstance($compilerClass);
                $compiler->compile();
                $this->printing->success(__('%{1} 编译完成', [$type]));
                return;
            }
            
            // 如果 Theme 模块没有，尝试从 Framework 模块加载
            $compilerClass = "Weline\\Framework\\Resource\\Compiler\\{$className}";
            if (class_exists($compilerClass)) {
                /**@var CompilerInterface $compiler */
                $compiler = ObjectManager::getInstance($compilerClass);
                $compiler->compile();
                $this->printing->success(__('%{1} 编译完成', [$type]));
                return;
            }
            
            $this->printing->error(__('未找到资源类型 %{1} 的编译器', [$type]));
        } catch (\Exception $e) {
            $this->printing->error(__('编译 %{1} 时出错：%{2}', [$type, $e->getMessage()]));
        }
    }

    /**
     * 获取支持的资源类型
     * 
     * @param bool $to_string 是否返回字符串格式（用逗号分隔）
     * @return array|string
     */
    public function getTypes(bool $to_string = false)
    {
        $data = [
            'welineModules' => __('编译 weline.modules.js 模块配置文件'),
            'less' => __('编译 Less 样式文件'),
        ];
        
        if ($to_string) {
            return implode(', ', array_keys($data));
        }
        
        return $data;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('编译静态资源（weline.modules.js、less 等）');
    }
    
    /**
     * 命令别名
     */
    public const ALIASES = ['static:compile', 's:compile', 'resource:compile', 'r:compile'];

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'resource:compile',
            $this->tip(),
            [
                '-h, --help' => __('显示帮助信息'),
            ],
            [
                '[资源类型]' => __('可选，指定要编译的资源类型（welineModules、less）。如果不指定，将编译所有资源类型。'),
            ],
            [
                __('编译所有资源') => 'php bin/w resource:compile',
                __('只编译 weline.modules.js') => 'php bin/w resource:compile welineModules',
                __('只编译 less') => 'php bin/w resource:compile less',
                __('使用别名') => 'php bin/w static:compile welineModules',
            ]
        );
    }
}
