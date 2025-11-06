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
use Weline\Sticker\Service\ConflictDetector;
use Weline\Sticker\Service\RuleParser;
use Weline\Sticker\Service\RuleScanner;
use Weline\Sticker\Service\StickerRegistry;

/**
 * Sticker 收集命令
 * 收集 Sticker 信息到注册表并检测冲突
 * 
 * 用法：php bin/w sticker:collect
 */
class Collect extends CommandAbstract implements CommandInterface
{
    private RuleScanner $ruleScanner;
    private RuleParser $ruleParser;
    private StickerRegistry $stickerRegistry;
    private ConflictDetector $conflictDetector;

    public function __construct()
    {
        $this->ruleScanner = ObjectManager::getInstance(RuleScanner::class);
        $this->ruleParser = ObjectManager::getInstance(RuleParser::class);
        $this->stickerRegistry = ObjectManager::getInstance(StickerRegistry::class);
        $this->conflictDetector = ObjectManager::getInstance(ConflictDetector::class);
    }

    /**
     * 命令描述
     * 
     * @return string
     */
    public function tip(): string
    {
        return '收集 Sticker 信息到注册表并检测冲突';
    }

    /**
     * 帮助信息
     * 
     * @return array|string
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'sticker:collect',
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
            $this->printer->note(__('开始收集 Sticker 信息...'));

            // 1. 扫描所有 Sticker 文件
            $this->printer->info(__('扫描 Sticker 文件...'));
            $scannedStickers = $this->ruleScanner->scanAllStickers();
            
            $this->printer->success(__('发现 %{1} 个 Sticker 文件', [count($scannedStickers)]));

            if (empty($scannedStickers)) {
                $this->printer->note(__('没有发现 Sticker 文件，清空注册表'));
                $this->stickerRegistry->saveRegistry([]);
                return;
            }

            // 2. 构建注册表
            $this->printer->info(__('构建注册表...'));
            $registry = $this->stickerRegistry->buildRegistryFromScanned($scannedStickers, $this->ruleParser);
            
            $this->printer->success(__('注册表构建完成'));

            // 3. 检测冲突
            $this->printer->info(__('检测冲突...'));
            $conflicts = $this->conflictDetector->detectConflicts($registry);

            if (!empty($conflicts)) {
                $this->printer->error(__('检测到 %{1} 个冲突', [count($conflicts)]));
                $this->printer->println('');
                
                $message = $this->conflictDetector->formatConflictMessage($conflicts);
                $this->printer->error($message);
                
                $this->printer->println('');
                $this->printer->error(__('注册表未更新，请解决冲突后重新执行'));
                return;
            }

            $this->printer->success(__('未检测到冲突'));

            // 4. 保存注册表
            $this->printer->info(__('保存注册表...'));
            $result = $this->stickerRegistry->saveRegistry($registry);

            if ($result) {
                $this->printer->success(__('注册表保存成功'));
            } else {
                $this->printer->error(__('注册表保存失败'));
            }

        } catch (\Exception $e) {
            $this->printer->error(__('执行失败：%{1}', [$e->getMessage()]));
        }
    }
}

