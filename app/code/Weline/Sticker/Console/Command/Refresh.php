<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Console\Command;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Sticker\Service\Compiler;

/**
 * Sticker 刷新命令
 * 刷新编译文件
 * 
 * 用法：php bin/w sticker:refresh
 */
class Refresh extends CommandAbstract implements CommandInterface
{
    private Compiler $compiler;

    public function __construct()
    {
        $this->compiler = ObjectManager::getInstance(Compiler::class);
    }

    /**
     * 命令描述
     * 
     * @return string
     */
    public function tip(): string
    {
        return '刷新 Sticker 编译文件';
    }

    /**
     * 帮助信息
     * 
     * @return array|string
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'sticker:refresh',
            $this->tip(),
            [],
            [],
            []
        );
    }

    /**
     * 执行命令
     * 
     * @param array $args
     * @param array $data
     * @return mixed|void
     */
    public function execute(array $args = [], array $data = [])
    {
        try {
            $this->printer->note(__('开始刷新 Sticker 编译文件...'));

            $result = $this->compiler->compileAll();

            $this->printer->println('');
            $this->printer->info(__('刷新完成'));
            $this->printer->success(__('成功: %{1}', [$result['success']]));
            
            if ($result['failed'] > 0) {
                $this->printer->error(__('失败: %{1}', [$result['failed']]));
                
                if (!empty($result['errors'])) {
                    $this->printer->println('');
                    $this->printer->note(__('错误详情:'));
                    foreach ($result['errors'] as $error) {
                        $this->printer->error("  - {$error}");
                    }
                }
            }

        } catch (\Exception $e) {
            $this->printer->error(__('执行失败：%{1}', [$e->getMessage()]));
        }
    }
}

