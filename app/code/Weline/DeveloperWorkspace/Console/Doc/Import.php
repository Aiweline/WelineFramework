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
        
        $this->printer->printing('');
        $this->printer->printing('═══════════════════════════════════════════════════════════════');
        $this->printer->printing(__('📚 开始扫描模块文档...'));
        $this->printer->printing('═══════════════════════════════════════════════════════════════');
        $this->printer->printing('');
        
        /** @var DocumentScanner $scanner */
        $scanner = ObjectManager::getInstance(DocumentScanner::class);
        
        // 设置进度回调函数
        $scanner->setProgressCallback(function(string $message, string $type = 'info') {
            if (empty($message)) {
                $this->printer->printing('');
                return;
            }
            
            switch ($type) {
                case 'success':
                    $this->printer->success($message);
                    break;
                case 'warning':
                    $this->printer->warning($message);
                    break;
                case 'error':
                    $this->printer->error($message);
                    break;
                default:
                    $this->printer->printing($message);
                    break;
            }
        });
        
        $result = $scanner->scanAllModules($forceRescan);
        
        $this->printer->printing('');
        $this->printer->printing('═══════════════════════════════════════════════════════════════');
        $this->printer->success(__('✅ 文档扫描完成！'));
        $this->printer->printing('═══════════════════════════════════════════════════════════════');
        $this->printer->printing('');
        $this->printer->printing(__('📊 扫描统计:'));
        $this->printer->printing(__('   总共扫描: %{count} 个文档', ['count' => $result['scanned']]));
        $this->printer->printing(__('   新增: %{count} 个', ['count' => $result['new']]));
        $this->printer->printing(__('   更新: %{count} 个', ['count' => $result['updated']]));
        if (isset($result['deleted']) && $result['deleted'] > 0) {
            $this->printer->warning(__('   删除文档: %{count} 个（不在文件中的文档）', ['count' => $result['deleted']]));
        }
        if (isset($result['deleted_catalogs']) && $result['deleted_catalogs'] > 0) {
            $this->printer->warning(__('   删除分类: %{count} 个（不存在于导入目录的分类）', ['count' => $result['deleted_catalogs']]));
        }
        
        if (!empty($result['modules'])) {
            $this->printer->printing('');
            $this->printer->printing(__('📦 模块详情:'));
            foreach ($result['modules'] as $module) {
                $this->printer->printing(__("   • %{name}: 扫描 %{scanned}, 新增 %{new}, 更新 %{updated}", [
                    'name' => $module['name'],
                    'scanned' => $module['scanned'],
                    'new' => $module['new'],
                    'updated' => $module['updated']
                ]));
            }
        }
        $this->printer->printing('');
    }

    public function tip(): string
    {
        return __('扫描并导入各模块的开发文档');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => __('显示帮助信息'),
                '-f, --force' => __('强制重新扫描（会删除旧的自动导入文档）'),
            ],
            [
                'php bin/w doc:import' => __('扫描所有模块文档（增量导入）'),
                'php bin/w doc:import --force' => __('强制重新扫描所有文档')
            ],
            []
        );
    }
}


