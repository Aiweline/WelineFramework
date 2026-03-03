<?php
declare(strict_types=1);

/**
 * Weline CDN - 攻击日志后台控制器
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Cdn\Controller\Backend;

use Weline\Cdn\Model\AttackLog as AttackLogModel;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\ObjectManager;

/**
 * CDN攻击日志管理后台控制器
 */
#[AclAttribute('Weline_Cdn::cdn_attack_log_manager', '攻击日志管理', 'mdi-shield-alert', 'CDN攻击日志管理', '')]
class AttackLog extends BackendController
{
    /**
     * 获取攻击日志模型
     */
    private function getLogModel(): AttackLogModel
    {
        return ObjectManager::getInstance(AttackLogModel::class);
    }

    /**
     * 攻击日志列表页面
     */
    #[AclAttribute('Weline_Cdn::cdn_attack_log_list', '查看攻击日志', 'mdi-view-list', '查看CDN攻击日志列表')]
    public function index(): string
    {
        $page = (int)$this->request->getGet('page', 1);
        $pageSize = 20;
        $search = \trim($this->request->getGet('search', ''));
        $status = \trim($this->request->getGet('status', ''));
        $attackType = \trim($this->request->getGet('attack_type', ''));
        $dateFrom = \trim($this->request->getGet('date_from', ''));
        $dateTo = \trim($this->request->getGet('date_to', ''));

        $query = $this->getLogModel()->reset()->select();

        // 搜索过滤（域名、IP）
        if (!empty($search)) {
            $query->where('domain', 'like', "%{$search}%")
                ->orWhere('attacker_ip', 'like', "%{$search}%");
        }

        // 状态过滤
        if (!empty($status)) {
            $query->where(AttackLogModel::fields_STATUS, $status);
        }

        // 攻击类型过滤
        if (!empty($attackType)) {
            $query->where(AttackLogModel::fields_ATTACK_TYPE, $attackType);
        }

        // 日期范围过滤
        if (!empty($dateFrom)) {
            $query->where(AttackLogModel::fields_CREATED_AT, '>=', $dateFrom . ' 00:00:00');
        }
        if (!empty($dateTo)) {
            $query->where(AttackLogModel::fields_CREATED_AT, '<=', $dateTo . ' 23:59:59');
        }

        // 按创建时间倒序
        $query->order(AttackLogModel::fields_CREATED_AT, 'DESC');

        // 分页查询
        $result = $query->pagination($page, $pageSize)->fetch();
        $logs = $result->getItems();
        
        $pagination = $query->getPaginationData();
        $total = $pagination['totalSize'] ?? 0;
        $totalPages = $pagination['lastPage'] ?? 0;

        // 攻击类型选项
        $attackTypes = [
            AttackLogModel::TYPE_RATE_LIMIT => __('频率限制'),
            AttackLogModel::TYPE_PATH_SCAN => __('路径扫描'),
            AttackLogModel::TYPE_MALICIOUS_PATTERN => __('恶意特征'),
            AttackLogModel::TYPE_BAD_USER_AGENT => __('恶意UA'),
            AttackLogModel::TYPE_PROTECTED_PATH => __('保护路径'),
            AttackLogModel::TYPE_SLOWLORIS => __('Slowloris'),
            AttackLogModel::TYPE_UNKNOWN => __('未知'),
        ];

        // 状态选项
        $statuses = [
            AttackLogModel::STATUS_ACTIVE => __('进行中'),
            AttackLogModel::STATUS_RECOVERED => __('已恢复'),
            AttackLogModel::STATUS_FAILED => __('处理失败'),
        ];

        // 统计数据
        $stats = $this->getStats();

        $this->assign('logs', $logs);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('pageSize', $pageSize);
        $this->assign('totalPages', $totalPages);
        $this->assign('search', $search);
        $this->assign('status', $status);
        $this->assign('attackType', $attackType);
        $this->assign('dateFrom', $dateFrom);
        $this->assign('dateTo', $dateTo);
        $this->assign('attackTypes', $attackTypes);
        $this->assign('statuses', $statuses);
        $this->assign('stats', $stats);

        return $this->fetch();
    }

    /**
     * 查看攻击日志详情
     */
    #[AclAttribute('Weline_Cdn::cdn_attack_log_view', '查看攻击详情', 'mdi-eye', '查看攻击日志详情')]
    public function getDetail(): string
    {
        $logId = (int)$this->request->getGet('id');
        
        if (!$logId) {
            $this->getMessageManager()->addError(__('日志ID不能为空'));
            return (string)$this->redirect('*/backend/attackLog/index');
        }

        $log = $this->getLogModel()->load($logId);
        
        if (!$log->getId()) {
            $this->getMessageManager()->addError(__('日志不存在'));
            return (string)$this->redirect('*/backend/attackLog/index');
        }

        // 解析 CDN 响应
        $cdnResponse = $log->getData(AttackLogModel::fields_CDN_RESPONSE);
        if (\is_string($cdnResponse)) {
            $cdnResponse = \json_decode($cdnResponse, true) ?: [];
        }
        $log->setData('cdn_response_parsed', $cdnResponse);

        $this->assign('log', $log);

        return $this->fetch('detail');
    }

    /**
     * 删除攻击日志
     */
    #[AclAttribute('Weline_Cdn::cdn_attack_log_delete', '删除攻击日志', 'mdi-delete', '删除攻击日志')]
    public function postDelete(): string
    {
        $logId = (int)$this->request->getPost('id');
        
        if (!$logId) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('日志ID不能为空'),
            ]);
        }

        $log = $this->getLogModel()->load($logId);
        
        if (!$log->getId()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('日志不存在'),
            ]);
        }

        try {
            $log->delete();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('删除成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('删除失败: %{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 批量删除攻击日志
     */
    #[AclAttribute('Weline_Cdn::cdn_attack_log_delete', '删除攻击日志', 'mdi-delete', '删除攻击日志')]
    public function postBatchDelete(): string
    {
        $ids = $this->request->getPost('ids');
        
        if (empty($ids) || !\is_array($ids)) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请选择要删除的日志'),
            ]);
        }

        try {
            $deleted = 0;
            foreach ($ids as $id) {
                $log = $this->getLogModel()->load((int)$id);
                if ($log->getId()) {
                    $log->delete();
                    $deleted++;
                }
            }
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('成功删除 %{1} 条日志', [$deleted]),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('批量删除失败: %{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 清理历史日志
     */
    #[AclAttribute('Weline_Cdn::cdn_attack_log_cleanup', '清理历史日志', 'mdi-broom', '清理历史攻击日志')]
    public function postCleanup(): string
    {
        $days = (int)$this->request->getPost('days', 30);
        
        if ($days < 1) {
            $days = 30;
        }

        try {
            $cutoff = \date('Y-m-d H:i:s', \strtotime("-{$days} days"));
            
            $result = $this->getLogModel()->reset()
                ->where(AttackLogModel::fields_CREATED_AT, '<', $cutoff)
                ->delete();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('成功清理 %{1} 天前的日志', [$days]),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('清理失败: %{1}', [$e->getMessage()]),
            ]);
        }
    }

    /**
     * 导出日志
     */
    #[AclAttribute('Weline_Cdn::cdn_attack_log_export', '导出攻击日志', 'mdi-download', '导出攻击日志')]
    public function getExport(): void
    {
        $status = \trim($this->request->getGet('status', ''));
        $attackType = \trim($this->request->getGet('attack_type', ''));
        $dateFrom = \trim($this->request->getGet('date_from', ''));
        $dateTo = \trim($this->request->getGet('date_to', ''));

        $query = $this->getLogModel()->reset()->select();

        if (!empty($status)) {
            $query->where(AttackLogModel::fields_STATUS, $status);
        }
        if (!empty($attackType)) {
            $query->where(AttackLogModel::fields_ATTACK_TYPE, $attackType);
        }
        if (!empty($dateFrom)) {
            $query->where(AttackLogModel::fields_CREATED_AT, '>=', $dateFrom . ' 00:00:00');
        }
        if (!empty($dateTo)) {
            $query->where(AttackLogModel::fields_CREATED_AT, '<=', $dateTo . ' 23:59:59');
        }

        $query->order(AttackLogModel::fields_CREATED_AT, 'DESC');
        $logs = $query->select()->fetchArray();

        // 生成 CSV
        $filename = 'attack_logs_' . \date('YmdHis') . '.csv';
        
        \header('Content-Type: text/csv; charset=UTF-8');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = \fopen('php://output', 'w');
        
        // UTF-8 BOM
        \fwrite($output, "\xEF\xBB\xBF");
        
        // 表头
        \fputcsv($output, [
            'ID',
            __('域名'),
            __('攻击类型'),
            __('攻击者IP'),
            __('攻击次数'),
            __('原因'),
            __('动作'),
            __('状态'),
            __('持续时间'),
            __('开始时间'),
            __('结束时间'),
            __('创建时间'),
        ]);
        
        // 数据行
        foreach ($logs as $log) {
            \fputcsv($output, [
                $log[AttackLogModel::fields_LOG_ID],
                $log[AttackLogModel::fields_DOMAIN],
                $log[AttackLogModel::fields_ATTACK_TYPE],
                $log[AttackLogModel::fields_ATTACKER_IP],
                $log[AttackLogModel::fields_ATTACK_COUNT],
                $log[AttackLogModel::fields_REASON],
                $log[AttackLogModel::fields_ACTION],
                $log[AttackLogModel::fields_STATUS],
                $log[AttackLogModel::fields_DURATION] ?? '',
                $log[AttackLogModel::fields_STARTED_AT],
                $log[AttackLogModel::fields_ENDED_AT] ?? '',
                $log[AttackLogModel::fields_CREATED_AT],
            ]);
        }
        
        \fclose($output);
        exit;
    }

    /**
     * 获取统计数据
     */
    private function getStats(): array
    {
        $model = $this->getLogModel();
        
        // 今日攻击数
        $today = \date('Y-m-d');
        $todayCount = $model->reset()
            ->where(AttackLogModel::fields_CREATED_AT, '>=', $today . ' 00:00:00')
            ->count();

        // 活跃攻击数
        $activeCount = $model->reset()
            ->where(AttackLogModel::fields_STATUS, AttackLogModel::STATUS_ACTIVE)
            ->count();

        // 本周攻击数
        $weekStart = \date('Y-m-d', \strtotime('monday this week'));
        $weekCount = $model->reset()
            ->where(AttackLogModel::fields_CREATED_AT, '>=', $weekStart . ' 00:00:00')
            ->count();

        // 本月攻击数
        $monthStart = \date('Y-m-01');
        $monthCount = $model->reset()
            ->where(AttackLogModel::fields_CREATED_AT, '>=', $monthStart . ' 00:00:00')
            ->count();

        return [
            'today' => $todayCount,
            'active' => $activeCount,
            'week' => $weekCount,
            'month' => $monthCount,
        ];
    }
}
