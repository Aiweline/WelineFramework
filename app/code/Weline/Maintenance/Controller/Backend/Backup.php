<?php

declare(strict_types=1);

/*
 * 备份管理控制器
 * 
 * @author Weline Framework
 * @package Weline\Maintenance\Controller\Backend
 */

namespace Weline\Maintenance\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Maintenance\Service\BackupManager;

/**
 * 备份管理控制器
 */
#[Acl('Weline_Maintenance::backup', '备份管理', 'mdi-backup-restore', '备份管理', 'Weline_Backend::system_maintenance')]
class Backup extends BackendController
{
    private BackupManager $backupManager;

    public function __init()
    {
        parent::__init();
        $this->backupManager = ObjectManager::getInstance(BackupManager::class);
    }

    /**
     * 备份列表
     * 
     * @return string
     */
    #[Acl('Weline_Maintenance::backup_index', '查看备份列表', 'mdi-list', '查看备份列表')]
    public function index(): string
    {
        try {
            $filters = [
                'backup_type' => $this->request->getParam('type', ''),
                'status' => $this->request->getParam('status', ''),
                'limit' => 50,
            ];

            $backups = $this->backupManager->getBackupList($filters);
            
            $this->assign('backups', $backups);
            $this->assign('filters', $filters);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            $this->assign('backups', []);
            $this->assign('error', $e->getMessage());
            return $this->fetch();
        }
    }

    /**
     * 创建备份
     * 
     * @return string
     */
    #[Acl('Weline_Maintenance::backup_create', '创建备份', 'mdi-content-save', '创建备份')]
    public function create(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $type = $this->request->getPost('type', 'full');
            
            if (!in_array($type, ['full', 'database', 'code', 'config'])) {
                return $this->jsonResponse(false, __('不支持的备份类型'));
            }

            $createdBy = $this->session->getLoginUserID();
            $result = $this->backupManager->createBackup($type, $createdBy);

            return $this->jsonResponse(true, __('备份创建成功'), [
                'backup' => is_array($result) ? $result : ['file_path' => $result],
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('备份创建失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 下载备份
     * 
     * @return void
     */
    #[Acl('Weline_Maintenance::backup_download', '下载备份', 'mdi-download', '下载备份')]
    public function download(): void
    {
        try {
            $backupId = (int)$this->request->getParam('id', 0);
            
            if ($backupId <= 0) {
                echo __('无效的备份ID');
                exit;
            }

            $filePath = $this->backupManager->getBackupPath($backupId);
            
            if (!$filePath || !is_file($filePath)) {
                echo __('备份文件不存在');
                exit;
            }

            $fileName = basename($filePath);
            
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($filePath));
            
            readfile($filePath);
            exit;
            
        } catch (\Exception $e) {
            echo __('下载失败：%{1}', $e->getMessage());
            exit;
        }
    }

    /**
     * 删除备份
     * 
     * @return string
     */
    #[Acl('Weline_Maintenance::backup_delete', '删除备份', 'mdi-delete', '删除备份')]
    public function delete(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        try {
            $backupId = (int)$this->request->getPost('id', 0);
            
            if ($backupId <= 0) {
                return $this->jsonResponse(false, __('无效的备份ID'));
            }

            $success = $this->backupManager->deleteBackup($backupId);
            
            if ($success) {
                return $this->jsonResponse(true, __('备份删除成功'));
            } else {
                return $this->jsonResponse(false, __('备份删除失败'));
            }
            
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('删除失败：%{1}', $e->getMessage()));
        }
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
        ], JSON_UNESCAPED_UNICODE);
    }
}
