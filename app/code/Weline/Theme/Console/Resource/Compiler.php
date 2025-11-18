<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Console\Resource;

use Weline\Framework\Console\CommandInterface;

use Weline\Framework\Cache\Console\Cache\Clear;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Resource\CompilerInterface;

class Compiler implements \Weline\Framework\Console\CommandInterface
{
    private Printing $printing;

    public function __construct(
        Printing $printing
    )
    {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $source_types = [];
        
        // 移除命令名（第一个参数）
        array_shift($args);
        
        // 提取资源类型参数（排除命令相关的键）
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
                exit();
            }
        }
        $this->printing->note(__('开始编译器工作。'));
        if (empty($source_types)) {
            foreach ($this->getTypes() as $key => $type) {
                $this->printing->warning($key . ':' . $type . __('编译中...'));
                // 将键名转换为类名：welineModules -> WelineModules, less -> Less
                // 对于 camelCase，需要在大小写字母之间插入空格，然后使用 ucwords
                $key = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
                $className = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $key)));
                /**@var CompilerInterface $compiler */
                $compiler = ObjectManager::getInstance("Weline\Theme\Console\Resource\Compiler\\$className");
                $compiler->compile();
            }
        } else {
            foreach ($source_types as $source_type) {
                $this->printing->warning($source_type . __('编译中...'));
                // 将键名转换为类名：welineModules -> WelineModules, less -> Less
                // 对于 camelCase，需要在大小写字母之间插入空格，然后使用 ucwords
                $source_type = preg_replace('/([a-z])([A-Z])/', '$1 $2', $source_type);
                $className = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $source_type)));
                /**@var CompilerInterface $compiler */
                $compiler = ObjectManager::getInstance("Weline\Theme\Console\Resource\Compiler\\$className");
                $compiler->compile();
            }
        }
        $this->printing->success(__('编译器工作已完成。'));
        # 清理缓存
        ObjectManager::getInstance(Clear::class)->execute();
    }

    public function getTypes(bool $to_string = false)
    {
        $data = [
            'less' => __('编译less静态资源！'),
            'welineModules' => __('静态文件：weline.modules.js编译文件！'),
        ];
        if ($to_string) {
            $data = implode(',', $data);
        }
        return $data;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('编译主题资源（less、weline.modules.js等）');
    }
    
    /**
     * 命令别名
     */
    public const ALIASES = ['theme:compile', 't:compile'];

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:resource:compiler',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [
                '[资源类型]' => '可选，指定要编译的资源类型（less、welineModules）。如果不指定，将编译所有资源类型。',
            ],
            [
                '编译所有资源' => 'php bin/w theme:resource:compiler',
                '只编译 less' => 'php bin/w theme:resource:compiler less',
                '只编译 welineModules' => 'php bin/w theme:resource:compiler welineModules',
                '使用别名' => 'php bin/w theme:compile welineModules',
            ]
        );
    }
}
