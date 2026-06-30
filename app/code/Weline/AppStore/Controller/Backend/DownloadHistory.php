<?php
declare(strict_types=1);

namespace Weline\AppStore\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\AppStore\Model\AppStoreDownloadLog;

/**
 * 下载历史控制器
 */
#[Acl('Weline_AppStore::download_history', '下载历史', 'bi-clock-history', '下载历史记录', 'Weline_AppStore::appstore')]
class DownloadHistory extends BackendController
{
    /**
     * 下载历史列表
     */
    #[Acl('Weline_AppStore::download_history_view', '查看历史', 'bi-list', '查看下载历史')]
    public function index(): string
    {
        /** @var AppStoreDownloadLog $logModel */
        $logModel = ObjectManager::getInstance(AppStoreDownloadLog::class);

        $logModel->reset()
            ->order('download_at', 'DESC')
            ->limit(50)
            ->select()
            ->fetch();

        $this->assign('logs', $logModel->getItems());
        $this->assign('page_title', __('下载历史'));

        return $this->fetch();
    }

    /**
     * 删除记录
     */
    #[Acl('Weline_AppStore::download_history_delete', '删除记录', 'bi-trash', '删除下载记录')]
    public function delete(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        $logId = $this->request->getPost('id');

        if (!$logId) {
            return $this->jsonResponse(false, __('缺少记录ID'));
        }

        try {
            /** @var AppStoreDownloadLog $logModel */
            $logModel = ObjectManager::getInstance(AppStoreDownloadLog::class);
            $log = $logModel->load($logId);

            if (!$log->getLogId()) {
                return $this->jsonResponse(false, __('记录不存在'));
            }

            $log->delete();

            return $this->jsonResponse(true, __('记录已删除'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('删除失败：') . $e->getMessage());
        }
    }

    /**
     * 清空历史
     */
    #[Acl('Weline_AppStore::download_history_clear', '清空历史', 'bi-trash', '清空下载历史')]
    public function clear(): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');

        try {
            /** @var AppStoreDownloadLog $logModel */
            $logModel = ObjectManager::getInstance(AppStoreDownloadLog::class);

            // 删除所有记录
            $tableName = $logModel::schema_table;
            $logModel->reset()->getAdapter()->query("DELETE FROM {$tableName}");

            return $this->jsonResponse(true, __('历史记录已清空'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('清空失败：') . $e->getMessage());
        }
    }

    /**
     * JSON 响应
     */
    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }
}
