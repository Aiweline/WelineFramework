<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Console\CommandAbstract;
use Weline\Theme\Observer\VariableMetaRegister;

/**
 * 扫描CSS变量命令
 * 
 * 手动触发CSS变量扫描和Meta注册
 */
class ScanVariables extends CommandAbstract
{
    /**
     * 执行命令
     * 
     * @param array $args 参数数组
     * @param array $data 数据数组
     * @return void
     */
    public function execute(array $args = [], array $data = []): void
    {
        $area = $args['area'] ?? '';
        $themeId = isset($args['theme_id']) ? (int)$args['theme_id'] : null;
        $force = isset($args['force']) && $args['force'] === 'true';
        
        $this->printer->note('开始扫描CSS变量...');
        
        $results = VariableMetaRegister::scanManually($area, $themeId, $force);
        
        $total = 0;
        foreach ($results as $areaItem => $vars) {
            $count = count($vars);
            $total += $count;
            $this->printer->success("区域 {$areaItem}: 扫描到 {$count} 个变量");
        }
        
        $this->printer->success("总共扫描到 {$total} 个CSS变量并注册到Meta系统");
    }
    
    /**
     * 命令提示
     * 
     * @return string
     */
    public function tip(): string
    {
        return '扫描CSS变量并注册到Meta系统';
    }
    
    /**
     * 帮助信息
     * 
     * @return array|string
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:scan-variables',
            $this->tip(),
            [
                'area' => '区域（frontend/backend），可选，默认扫描所有区域',
                'theme_id' => '主题ID，可选，默认扫描所有主题',
                'force' => '是否强制重新注册（true/false），可选'
            ],
            [],
            []
        );
    }
}

