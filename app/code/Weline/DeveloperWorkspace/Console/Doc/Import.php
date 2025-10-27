<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Console\Doc;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\DeveloperWorkspace\Service\DocumentScanner;

class Import extends CommandAbstract
{
    public const dir = 'Console\\Doc';

    public function execute(array $args = [], array $data = [])
    {
        $forceRescan = isset($args['--force']) || isset($args['-f']);
        
        $this->printer->printing('开始扫描模块文档...');
        
        /** @var DocumentScanner $scanner */
        $scanner = ObjectManager::getInstance(DocumentScanner::class);
        $result = $scanner->scanAllModules($forceRescan);
        
        $this->printer->success('文档扫描完成！');
        $this->printer->printing('总共扫描: ' . $result['scanned'] . ' 个文档');
        $this->printer->printing('新增: ' . $result['new'] . ' 个');
        $this->printer->printing('更新: ' . $result['updated'] . ' 个');
        
        if (!empty($result['modules'])) {
            $this->printer->printing("\n模块详情:");
            foreach ($result['modules'] as $module) {
                $this->printer->printing("  - {$module['name']}: 扫描 {$module['scanned']}, 新增 {$module['new']}, 更新 {$module['updated']}");
            }
        }
    }

    public function tip(): string
    {
        return '扫描并导入各模块的开发文档';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-f, --force' => '强制重新扫描（会删除旧的自动导入文档）',
            ],
            [
                'php bin/w doc:import' => '扫描所有模块文档（增量导入）',
                'php bin/w doc:import --force' => '强制重新扫描所有文档'
            ],
            []
        );
    }
}


