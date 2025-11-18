<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Acl\Acl;

/**
 * 训练数据管理控制器
 * 
 * 功能：
 * - 训练数据集管理
 * - 数据标注
 * - 数据导入导出
 */
#[Acl('Weline_Ai::ai_training_data', '训练数据', 'mdi-database', '训练数据', 'Weline_Ai::ai')]
class TrainingData extends BackendController
{
    /**
     * 训练数据列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_training_data_list', '查看训练数据', 'mdi-view-list', '查看训练数据')]
    public function index(): string
    {
        try {
            // TODO: 获取训练数据集列表
            $datasets = [];
            
            $this->assign('datasets', $datasets);
            
            // 统计
            $stats = [
                'total_datasets' => count($datasets),
                'total_records' => 0,
                'labeled_records' => 0,
            ];
            $this->assign('stats', $stats);

            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载训练数据失败：%{1}', $e->getMessage()));
            $this->assign('datasets', []);
            $this->assign('stats', ['total_datasets' => 0, 'total_records' => 0, 'labeled_records' => 0]);
            return $this->fetch();
        }
    }
}
