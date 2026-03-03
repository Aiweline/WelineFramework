<?php
declare(strict_types=1);

namespace Weline\Acl\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use Weline\Acl\Model\IpWhitelist as IpWhitelistModel;

/**
 * IP白名单控制器
 * 
 * 功能：
 * - 管理允许访问的IP地址
 */
#[Acl('Weline_Acl::ip_whitelist', 'IP白名单', 'mdi-shield-check', 'IP白名单')]
class IpWhitelist extends BackendController
{
    /**
     * IP白名单列表
     * 
     * @return string
     */
    #[Acl('Weline_Acl::ip_whitelist_index', '查看IP白名单', 'mdi-shield-check', '查看IP白名单')]
    public function index(): string
    {
        try {
            /** @var IpWhitelistModel $whitelistModel */
            $whitelistModel = ObjectManager::getInstance(IpWhitelistModel::class);
            
            // 获取查询参数
            $page = (int)($this->request->getGet('page') ?? 1);
            $limit = 20;
            $keyword = $this->request->getGet('keyword', '');
            
            // 构建查询
            $query = $whitelistModel->reset();
            
            if ($keyword) {
                $query->where(IpWhitelistModel::fields_IP, "%{$keyword}%", 'LIKE')
                      ->orWhere(IpWhitelistModel::fields_DESCRIPTION, "%{$keyword}%", 'LIKE');
            }
            
            $query->order(IpWhitelistModel::fields_CREATED_AT, 'DESC');
            $collection = $query->pagination($page, $limit);
            
            $items = $collection->getItems();
            $total = $collection->getTotal();
            $totalPages = (int)ceil($total / $limit);
            
            $this->assign('items', $items);
            $this->assign('total', $total);
            $this->assign('page', $page);
            $this->assign('limit', $limit);
            $this->assign('total_pages', $totalPages);
            $this->assign('keyword', $keyword);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载IP白名单失败：%{1}', $e->getMessage()));
            $this->assign('items', []);
            $this->assign('total', 0);
            $this->assign('page', 1);
            $this->assign('limit', 20);
            $this->assign('total_pages', 0);
            $this->assign('keyword', '');
            return $this->fetch();
        }
    }
    
    /**
     * 添加IP白名单
     * 
     * @return string
     */
    #[Acl('Weline_Acl::ip_whitelist_add', '添加IP白名单', 'mdi-plus', '添加IP白名单')]
    public function add(): string
    {
        if ($this->isPost()) {
            try {
                $ip = trim($this->request->getPost('ip', ''));
                $description = trim($this->request->getPost('description', ''));
                $isActive = (int)$this->request->getPost('is_active', 1);
                
                if (empty($ip)) {
                    return $this->jsonResponse(false, __('IP地址不能为空'));
                }
                
                // 验证IP格式
                if (!filter_var($ip, FILTER_VALIDATE_IP) && !$this->isValidIpRange($ip)) {
                    return $this->jsonResponse(false, __('IP地址格式不正确'));
                }
                
                /** @var IpWhitelistModel $whitelistModel */
                $whitelistModel = ObjectManager::getInstance(IpWhitelistModel::class);
                
                // 检查是否已存在
                $existing = $whitelistModel->reset()->where(IpWhitelistModel::fields_IP, $ip)->load();
                if ($existing->getId()) {
                    return $this->jsonResponse(false, __('该IP地址已存在'));
                }
                
                $whitelistModel->setData(IpWhitelistModel::fields_IP, $ip);
                $whitelistModel->setData(IpWhitelistModel::fields_DESCRIPTION, $description);
                $whitelistModel->setData(IpWhitelistModel::fields_IS_ACTIVE, $isActive);
                
                if ($whitelistModel->save()) {
                    return $this->jsonResponse(true, __('添加成功'));
                } else {
                    return $this->jsonResponse(false, __('添加失败'));
                }
                
            } catch (\Exception $e) {
                return $this->jsonResponse(false, __('添加失败：%{1}', $e->getMessage()));
            }
        }
        
        return $this->fetch();
    }
    
    /**
     * 编辑IP白名单
     * 
     * @return string
     */
    #[Acl('Weline_Acl::ip_whitelist_edit', '编辑IP白名单', 'mdi-pencil', '编辑IP白名单')]
    public function edit(): string
    {
        $id = (int)$this->request->getParam('id', 0);
        
        if ($this->isPost()) {
            try {
                $ip = trim($this->request->getPost('ip', ''));
                $description = trim($this->request->getPost('description', ''));
                $isActive = (int)$this->request->getPost('is_active', 1);
                
                if (empty($ip)) {
                    return $this->jsonResponse(false, __('IP地址不能为空'));
                }
                
                // 验证IP格式
                if (!filter_var($ip, FILTER_VALIDATE_IP) && !$this->isValidIpRange($ip)) {
                    return $this->jsonResponse(false, __('IP地址格式不正确'));
                }
                
                /** @var IpWhitelistModel $whitelistModel */
                $whitelistModel = ObjectManager::getInstance(IpWhitelistModel::class);
                $whitelistModel->load($id);
                
                if (!$whitelistModel->getId()) {
                    return $this->jsonResponse(false, __('记录不存在'));
                }
                
                // 检查IP是否被其他记录使用
                $existing = $whitelistModel->reset()
                    ->where(IpWhitelistModel::fields_IP, $ip)
                    ->where(IpWhitelistModel::fields_ID, $id, '!=')
                    ->load();
                if ($existing->getId()) {
                    return $this->jsonResponse(false, __('该IP地址已被其他记录使用'));
                }
                
                $whitelistModel->setData(IpWhitelistModel::fields_IP, $ip);
                $whitelistModel->setData(IpWhitelistModel::fields_DESCRIPTION, $description);
                $whitelistModel->setData(IpWhitelistModel::fields_IS_ACTIVE, $isActive);
                
                if ($whitelistModel->save()) {
                    return $this->jsonResponse(true, __('更新成功'));
                } else {
                    return $this->jsonResponse(false, __('更新失败'));
                }
                
            } catch (\Exception $e) {
                return $this->jsonResponse(false, __('更新失败：%{1}', $e->getMessage()));
            }
        }
        
        /** @var IpWhitelistModel $whitelistModel */
        $whitelistModel = ObjectManager::getInstance(IpWhitelistModel::class);
        $item = $whitelistModel->load($id);
        
        if (!$item->getId()) {
            Message::error(__('记录不存在'));
            $this->redirect('*/backend/ip-whitelist');
            return '';
        }
        
        $this->assign('item', $item->getData());
        return $this->fetch();
    }
    
    /**
     * 删除IP白名单
     * 
     * @return string
     */
    #[Acl('Weline_Acl::ip_whitelist_delete', '删除IP白名单', 'mdi-delete', '删除IP白名单')]
    public function delete(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }
        
        try {
            $id = (int)$this->request->getPost('id', 0);
            
            if ($id <= 0) {
                return $this->jsonResponse(false, __('无效的ID'));
            }
            
            /** @var IpWhitelistModel $whitelistModel */
            $whitelistModel = ObjectManager::getInstance(IpWhitelistModel::class);
            $whitelistModel->load($id);
            
            if (!$whitelistModel->getId()) {
                return $this->jsonResponse(false, __('记录不存在'));
            }
            
            if ($whitelistModel->delete()) {
                return $this->jsonResponse(true, __('删除成功'));
            } else {
                return $this->jsonResponse(false, __('删除失败'));
            }
            
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('删除失败：%{1}', $e->getMessage()));
        }
    }
    
    /**
     * 切换启用状态
     * 
     * @return string
     */
    #[Acl('Weline_Acl::ip_whitelist_toggle', '切换IP白名单状态', 'mdi-toggle-switch', '切换IP白名单状态')]
    public function toggle(): string
    {
        if (!$this->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }
        
        try {
            $id = (int)$this->request->getPost('id', 0);
            
            if ($id <= 0) {
                return $this->jsonResponse(false, __('无效的ID'));
            }
            
            /** @var IpWhitelistModel $whitelistModel */
            $whitelistModel = ObjectManager::getInstance(IpWhitelistModel::class);
            $whitelistModel->load($id);
            
            if (!$whitelistModel->getId()) {
                return $this->jsonResponse(false, __('记录不存在'));
            }
            
            $currentStatus = (int)$whitelistModel->getData(IpWhitelistModel::fields_IS_ACTIVE);
            $newStatus = $currentStatus ? 0 : 1;
            $whitelistModel->setData(IpWhitelistModel::fields_IS_ACTIVE, $newStatus);
            
            if ($whitelistModel->save()) {
                return $this->jsonResponse(true, __('状态更新成功'), ['is_active' => $newStatus]);
            } else {
                return $this->jsonResponse(false, __('状态更新失败'));
            }
            
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('状态更新失败：%{1}', $e->getMessage()));
        }
    }
    
    /**
     * 验证IP范围格式（支持CIDR）
     * 
     * @param string $ip
     * @return bool
     */
    private function isValidIpRange(string $ip): bool
    {
        // 支持CIDR格式，如 192.168.1.0/24
        if (strpos($ip, '/') !== false) {
            list($ipPart, $cidr) = explode('/', $ip, 2);
            if (!filter_var($ipPart, FILTER_VALIDATE_IP)) {
                return false;
            }
            $cidr = (int)$cidr;
            return $cidr >= 0 && $cidr <= 32;
        }
        return false;
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

