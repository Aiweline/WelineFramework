<?php

declare(strict_types=1);

/*
 * 脱敏日志管理控制器
 */

namespace GuoLaiRen\Desensitization\Controller\Backend;

use GuoLaiRen\Desensitization\Model\DesensitizationLog;
use Weline\Framework\App\Controller\BackendController;

class Log extends BackendController
{
    private DesensitizationLog $logModel;

    public function __construct(DesensitizationLog $logModel)
    {
        $this->logModel = $logModel;
    }

    /**
     * 脱敏记录列表页面
     *
     * @return mixed
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            return $this->getLogList();
        }

        return $this->fetch();
    }

    /**
     * 获取脱敏记录列表（AJAX）
     *
     * @return mixed
     */
    private function getLogList()
    {
        try {
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = (int)$this->request->getGet('limit', 20);
            $start = ($page - 1) * $pageSize;

            // 获取日志列表
            $logs = $this->logModel->reset()
                ->order('log_id', 'DESC')
                ->limit($start, $pageSize)
                ->select()
                ->fetch();

            // 获取总数
            $total = $this->logModel->reset()->count('log_id');

            $data = [];
            foreach ($logs as $log) {
                $data[] = [
                    'log_id' => $log->getLogId(),
                    'original' => $log->getOriginalContent(),
                    'desensitized' => $log->getDesensitizedContent(),
                    'method' => $log->getMethod(),
                    'execution_time' => $log->getExecutionTime() . 's',
                    'user_id' => $log->getUserId(),
                    'ip_address' => $log->getIpAddress(),
                    'created_at' => $log->getCreatedAt()
                ];
            }

            return $this->success([
                'code' => 0,
                'msg' => 'success',
                'count' => $total,
                'data' => $data
            ])->json();
        } catch (\Exception $e) {
            return $this->error('获取日志失败: ' . $e->getMessage())->json();
        }
    }

    /**
     * 查看详情
     *
     * @return mixed
     */
    public function view()
    {
        $logId = (int)$this->request->getParam('log_id', 0);

        if (!$logId) {
            return $this->error()->json('参数错误');
        }

        try {
            $log = $this->logModel->load($logId);
            
            if (!$log->getId()) {
                return $this->error()->json('记录不存在');
            }

            $this->assign('log', [
                'log_id' => $log->getLogId(),
                'original' => $log->getOriginalContent(),
                'desensitized' => $log->getDesensitizedContent(),
                'method' => $log->getMethod(),
                'execution_time' => $log->getExecutionTime(),
                'user_id' => $log->getUserId(),
                'ip_address' => $log->getIpAddress(),
                'created_at' => $log->getCreatedAt()
            ]);

            return $this->fetch();
        } catch (\Exception $e) {
            return $this->error()->json('查看失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除日志
     *
     * @return mixed
     */
    public function delete()
    {
        $logId = (int)$this->request->getParam('log_id', 0);

        if (!$logId) {
            return $this->error()->json('参数错误');
        }

        try {
            $log = $this->logModel->load($logId);
            
            if (!$log->getId()) {
                return $this->error()->json('记录不存在');
            }

            if ($log->delete()) {
                return $this->success()->json('删除成功');
            } else {
                return $this->error()->json('删除失败');
            }
        } catch (\Exception $e) {
            return $this->error()->json('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量删除
     *
     * @return mixed
     */
    public function batchDelete()
    {
        if (!$this->isPost()) {
            return $this->error()->json('仅支持POST请求');
        }

        $data = $this->request->getParams();
        $logIds = $data['log_ids'] ?? [];

        if (empty($logIds) || !is_array($logIds)) {
            return $this->error()->json('参数错误');
        }

        try {
            $count = 0;
            foreach ($logIds as $logId) {
                $log = $this->logModel->load($logId);
                if ($log->getId()) {
                    $log->delete();
                    $count++;
                }
            }

            return $this->success()->json("成功删除 {$count} 条记录");
        } catch (\Exception $e) {
            return $this->error()->json('批量删除失败: ' . $e->getMessage());
        }
    }
}

