<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Console\Resource;

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
        array_shift($args);
        foreach ($args as $arg) {
            if (!in_array($arg, array_keys($this->getTypes()))) {
                $this->printing->error(__('不存在的编译资源类型：%1，支持的资源类型：%2', [$arg, $this->getTypes(true)]));
                exit();
            }
            $source_types[] = $arg;
        }
        $this->printing->note(__('开始编译器工作。'));
        if (empty($source_types)) {
            foreach ($this->getTypes() as $key => $type) {
                $this->printing->warning($key . ':' . $type . __('编译中...'));
                $key = ucfirst($key);
                /**@var CompilerInterface $compiler */
                $compiler = ObjectManager::getInstance("Weline\Theme\Console\Resource\Compiler\\$key");
                $compiler->compile();
            }
        } else {
            foreach ($source_types as $source_type) {
                $this->printing->warning($source_type . __('编译中...'));
                /**@var CompilerInterface $compiler */
                $compiler = ObjectManager::getInstance("Weline\Theme\Console\Resource\Compiler\\$source_type");
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
            'less'      => __('编译less静态资源！'),
            'requireJs' => __('静态文件：require.config.js编译文件！'),
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
        return __('编译资源');
    }
}
