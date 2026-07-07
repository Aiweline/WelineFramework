<?php
declare(strict_types=1);

namespace Weline\Websites\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\WebsiteBackupService;

/**
 * 网站备份控制器
 * 
 * 功能：
 * - 支持数据库和文件备份
 */
#[Acl('Weline_Websites::website_backup', '网站备份', 'mdi-backup-restore', '网站备份', 'Weline_Websites::website_service')]
class Backup extends BackendController
{
    private WebsiteBackupService $backupService;

    public function __init()
    {
        parent::__init();
        $this->backupService = ObjectManager::getInstance(WebsiteBackupService::class);
    }

    /**
     * 备份管理首页
     * 
     * @return string
     */
    #[Acl('Weline_Websites::website_backup_index', '查看网站备份', 'mdi-backup-restore', '查看网站备份')]
    public function index(): string
    {
        try {
            /** @var Website $websiteModel */
            $websiteModel = ObjectManager::getInstance(Website::class);
            $websites = $websiteModel->select()->fetchArray();
            $backups = $this->backupService->listBackups();
            
            $this->assign('websites', $websites);
            $this->assign('backups', $backups);
            $this->assign('backup_types', $this->backupService->getTypeOptions());
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载备份管理失败：%{1}', $e->getMessage()));
            $this->assign('websites', []);
            $this->assign('backups', []);
            $this->assign('backup_types', []);
            return $this->fetch();
        }
    }
    
    /**
     * 创建备份
     * 
     * @return string
     */
    #[Acl('Weline_Websites::website_backup_create', '创建网站备份', 'mdi-content-save', '创建网站备份')]
    public function create(): string
    {
        $backupAction = (string)($this->request->getPost('backup_action', $this->request->getGet('backup_action', 'create')) ?: 'create');
        if ($backupAction === 'download') {
            $this->sendDownload((string)$this->request->getParam('filename', ''));
        }

        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }
        
        try {
            if ($backupAction === 'delete') {
                $filename = (string)$this->request->getPost('filename', '');
                $this->backupService->deleteBackup($filename);
                return $this->jsonResponse(true, __('备份已删除'));
            }

            $websiteIdRaw = $this->request->getPost('website_id', null);
            $websiteId = (int)($websiteIdRaw ?? 0);
            $backupType = $this->request->getPost('backup_type', 'full'); // full, database, files
            
            if ($websiteIdRaw === null || $websiteIdRaw === '' || $websiteId < Website::ID_DEFAULT) {
                return $this->jsonResponse(false, __('无效的网站ID'));
            }

            $backup = $this->backupService->createBackup(
                $websiteId,
                (string)$backupType,
                (int)$this->session->getLoginUserID()
            );
            
            return $this->jsonResponse(true, __('备份创建成功'), ['backup' => $backup]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('备份创建失败：%{1}', $e->getMessage()));
        }
    }

    private function sendDownload(string $filename): never
    {
        try {
            $path = $this->backupService->getBackupPath($filename);
        } catch (\Throwable $e) {
            Message::error(__('下载备份失败：%{1}', $e->getMessage()));
            $this->request->getResponse()->redirect($this->getBackendUrl('*/backup/index'))->send();
        }

        $this->request->getResponse()->download($path, $filename);
    }
    
    /**
     * JSON响应
     * 
     * @param bool $success
     * @param string $message
     * @param array $data
     * @return string
     */
    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
