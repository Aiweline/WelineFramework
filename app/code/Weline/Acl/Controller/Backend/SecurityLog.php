<?php
declare(strict_types=1);

namespace Weline\Acl\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Acl\Model\SecurityLog as SecurityLogModel;

/**
 * 安全日志控制器
 * 
 * 功能：
 * - 查看安全日志
 * - 记录登录失败、权限拒绝等安全事件
 */
#[Acl('Weline_Acl::security_log', '安全日志', 'mdi-shield-alert', '安全日志')]
class SecurityLog extends BackendController
{
    /**
     * 安全日志列表
     * 
     * @return string
     */
    #[Acl('Weline_Acl::security_log_index', '查看安全日志', 'mdi-shield-alert', '查看安全日志')]
    public function index(): string
    {
        try {
            /** @var SecurityLogModel $logModel */
            $logModel = ObjectManager::getInstance(SecurityLogModel::class);
            
            // 获取查询参数
            $page = (int)($this->request->getGet('page') ?? 1);
            $limit = 20;
            $eventType = $this->request->getGet('event_type', '');
            $keyword = $this->request->getGet('keyword', '');
            
            // 构建查询
            $query = $logModel->reset();
            
            if ($eventType) {
                $query->where(SecurityLogModel::schema_fields_EVENT_TYPE, $eventType);
            }
            
            if ($keyword) {
                $query->where(SecurityLogModel::schema_fields_MESSAGE, "%{$keyword}%", 'LIKE')
                      ->orWhere(SecurityLogModel::schema_fields_IP, "%{$keyword}%", 'LIKE');
            }
            
            $query->order(SecurityLogModel::schema_fields_CREATED_AT, 'DESC');
            $collection = $query->pagination($page, $limit);
            
            $logs = $collection->getItems();
            $total = $collection->getTotal();
            $totalPages = (int)ceil($total / $limit);
            
            // 事件类型列表
            $eventTypes = [
                SecurityLogModel::EVENT_LOGIN_FAILED => __('登录失败'),
                SecurityLogModel::EVENT_LOGIN_SUCCESS => __('登录成功'),
                SecurityLogModel::EVENT_PERMISSION_DENIED => __('权限拒绝'),
                SecurityLogModel::EVENT_ACL_VIOLATION => __('ACL违规'),
                SecurityLogModel::EVENT_SUSPICIOUS_ACTIVITY => __('可疑活动'),
            ];
            
            $this->assign('logs', $logs);
            $this->assign('total', $total);
            $this->assign('page', $page);
            $this->assign('limit', $limit);
            $this->assign('total_pages', $totalPages);
            $this->assign('event_type', $eventType);
            $this->assign('keyword', $keyword);
            $this->assign('event_types', $eventTypes);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载安全日志失败：%{1}', $e->getMessage()));
            $this->assign('logs', []);
            $this->assign('total', 0);
            $this->assign('page', 1);
            $this->assign('limit', 20);
            $this->assign('total_pages', 0);
            $this->assign('event_type', '');
            $this->assign('keyword', '');
            $this->assign('event_types', []);
            return $this->fetch();
        }
    }
}

