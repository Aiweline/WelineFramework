<?php
declare(strict_types=1);

namespace Weline\Backend\Controller;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\Message;

/**
 * 访问日志控制器
 * 
 * 功能：
 * - 显示网站访问日志
 */
#[Acl('Weline_Backend::access_log', '访问日志', 'mdi-file-document-outline', '访问日志', 'Weline_Backend::monitor_log')]
class AccessLog extends BackendController
{
    /**
     * 访问日志列表
     * 
     * @return string
     */
    #[Acl('Weline_Backend::access_log_index', '查看访问日志', 'mdi-file-document-outline', '查看访问日志')]
    public function index(): string
    {
        try {
            // 获取查询参数
            $page = (int)($this->request->getGet('page') ?? 1);
            $limit = 20;
            $keyword = $this->request->getGet('keyword', '');
            
            // TODO: 从日志文件或数据库获取访问日志
            $logs = [];
            $total = 0;
            $totalPages = 0;
            
            $this->assign('logs', $logs);
            $this->assign('total', $total);
            $this->assign('page', $page);
            $this->assign('limit', $limit);
            $this->assign('total_pages', $totalPages);
            $this->assign('keyword', $keyword);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载访问日志失败：%{1}', $e->getMessage()));
            $this->assign('logs', []);
            $this->assign('total', 0);
            $this->assign('page', 1);
            $this->assign('limit', 20);
            $this->assign('total_pages', 0);
            $this->assign('keyword', '');
            return $this->fetch();
        }
    }
}

