<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Attribute\Acl as AclAttribute;
use Weline\Framework\Manager\Message;
use Weline\Ai\Model\AiTenant;

/**
 * 租户管理控制器
 */
class Tenant extends BackendController
{
    private AiTenant $aiTenant;

    public function __construct(
        AiTenant $aiTenant
    ) {
        $this->aiTenant = $aiTenant;
    }

    /**
     * 租户列表页
     */
    #[AclAttribute('Weline_Ai::ai_tenant_index', '租户列表', 'mdi-domain', '查看租户列表')]
    public function index(): string
    {
        try {
            // 获取分页参数
            $page = (int)$this->request->getGet('p', 1);
            $pageSize = (int)$this->request->getGet('page_size', 20);
            
            // 获取搜索参数
            $keyword = trim($this->request->getGet('keyword', ''));
            $status = $this->request->getGet('status', '');
            
            // 构建查询
            $query = $this->aiTenant->reset()->select();
            
            // 搜索过滤
            if (!empty($keyword)) {
                $query->where('name', 'like', "%{$keyword}%")
                      ->orWhere('domain', 'like', "%{$keyword}%");
            }
            
            // 状态过滤
            if ($status !== '') {
                $query->where('is_active', '=', (int)$status);
            }
            
            // 统计
            $total = $query->count();
            
            // 分页查询
            $tenants = $query
                ->limit($pageSize, ($page - 1) * $pageSize)
                ->order('id', 'DESC')
                ->fetch()
                ->getItems();
            
            // 计算分页信息
            $totalPages = ceil($total / $pageSize);
            
            $this->assign('tenants', $tenants);
            $this->assign('total', $total);
            $this->assign('page', $page);
            $this->assign('pageSize', $pageSize);
            $this->assign('totalPages', $totalPages);
            $this->assign('keyword', $keyword);
            $this->assign('current_status', $status);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载租户列表失败：%{1}', $e->getMessage()));
            $this->assign('tenants', []);
            $this->assign('total', 0);
            $this->assign('page', 1);
            $this->assign('pageSize', 20);
            $this->assign('totalPages', 0);
            $this->assign('keyword', '');
            $this->assign('current_status', '');
            return $this->fetch();
        }
    }

    /**
     * 创建/编辑租户表单
     */
    #[AclAttribute('Weline_Ai::ai_tenant_form', '租户表单', 'mdi-form', '创建/编辑租户表单')]
    public function form(): string
    {
        $id = (int)$this->request->getGet('id');
        
        if ($id) {
            $tenant = $this->aiTenant->reset()->load($id);
            
            if (!$tenant->getId()) {
                return '<div class="alert alert-danger">' . __('租户不存在') . '</div>';
            }
            
            $this->assign('tenant', $tenant);
        } else {
            $this->assign('tenant', $this->aiTenant->reset());
        }
        
        return $this->fetch();
    }

    /**
     * 保存租户
     */
    #[AclAttribute('Weline_Ai::ai_tenant_save', '保存租户', 'mdi-content-save', '保存租户')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $data = $this->request->getPost();

        try {
            $tenant = $this->aiTenant->reset();
            
            if ($id) {
                $tenant->load($id);
                
                if (!$tenant->getId()) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('租户不存在')
                    ]);
                }
            }
            
            // 验证必填字段
            if (empty($data['name'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('租户名称不能为空')
                ]);
            }
            
            // 检查名称重复
            if (!$id || $data['name'] !== $tenant->getData('name')) {
                $existTenant = $this->aiTenant->reset()
                    ->where('name', '=', $data['name'])
                    ->find()
                    ->fetch();
                if ($existTenant->getId()) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('租户名称已存在')
                    ]);
                }
            }
            
            // 设置数据
            $tenant->setData('name', $data['name']);
            $tenant->setData('domain', $data['domain'] ?? '');
            $tenant->setData('description', $data['description'] ?? '');
            $tenant->setData('is_active', isset($data['is_active']) ? (int)$data['is_active'] : 1);
            $tenant->setData('max_users', isset($data['max_users']) ? (int)$data['max_users'] : 0);
            $tenant->setData('max_api_calls_per_day', isset($data['max_api_calls_per_day']) ? (int)$data['max_api_calls_per_day'] : 0);
            
            // 保存
            $tenant->save();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('保存成功'),
                'data' => ['id' => $tenant->getId()]
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 删除租户
     */
    #[AclAttribute('Weline_Ai::ai_tenant_delete', '删除租户', 'mdi-delete', '删除租户')]
    public function delete(): string
    {
        $id = (int)$this->request->getPost('id');
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('租户ID不能为空')
            ]);
        }
        
        try {
            $tenant = $this->aiTenant->reset()->load($id);
            
            if (!$tenant->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('租户不存在')
                ]);
            }
            
            $tenant->delete();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('删除成功')
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('删除失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * JSON响应
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}

