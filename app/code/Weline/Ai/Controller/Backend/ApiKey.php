<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiApiKey;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Acl\Acl;

/**
 * API密钥管理后台控制器
 * 
 * 功能：
 * - API密钥列表展示
 * - 密钥审核管理
 * - 配额管理
 * - 使用统计
 */
#[Acl('Weline_Ai::ai_apikey_manager', 'API密钥管理', 'mdi-key', 'API密钥管理', 'Weline_Ai::ai')]
class ApiKey extends BackendController
{
    /**
     * @var AiApiKey
     */
    private AiApiKey $aiApiKey;

    /**
     * 构造函数
     * 
     * @param AiApiKey $aiApiKey
     */
    public function __construct(AiApiKey $aiApiKey)
    {
        $this->aiApiKey = $aiApiKey;
    }

    /**
     * API密钥列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_apikey_list', '查看API密钥列表', 'mdi-view-list', '查看API密钥列表')]
    public function index(): string
    {
        try {
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = 20;
            $status = $this->request->getGet('status', '');

            // 构建查询
            $query = $this->aiApiKey->reset();
            
            if ($status !== '') {
                $query->where('status', $status);
            }

            $apiKeys = $query->pagination($page, $pageSize)
                ->order('created_time', 'DESC')
                ->select()
                ->fetch();

            $pagination = $apiKeys->getPagination();
            $total = is_object($pagination) && method_exists($pagination, 'getTotal') 
                ? $pagination->getTotal() 
                : count($apiKeys->getItems());

            $this->assign('api_keys', $apiKeys->getItems());
            $this->assign('pagination', $pagination);
            $this->assign('total', $total);
            $this->assign('current_status', $status);

            // 统计各状态数量
            $stats = [
                'total' => $total,
                'pending' => $this->aiApiKey->reset()->where('status', 'pending')->select()->fetch()->count(),
                'approved' => $this->aiApiKey->reset()->where('status', 'approved')->select()->fetch()->count(),
                'rejected' => $this->aiApiKey->reset()->where('status', 'rejected')->select()->fetch()->count(),
                'active' => $this->aiApiKey->reset()->where('is_active', 1)->select()->fetch()->count(),
            ];
            $this->assign('stats', $stats);

            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载API密钥列表失败：%{1}', [$e->getMessage()]));
            $this->assign('api_keys', []);
            $this->assign('pagination', null);
            $this->assign('total', 0);
            $this->assign('current_status', $status ?? '');
            $this->assign('stats', [
                'total' => 0,
                'pending' => 0,
                'approved' => 0,
                'rejected' => 0,
                'active' => 0,
            ]);
            return $this->fetch();
        }
    }

    /**
     * 审核API密钥
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_apikey_approve', '审核API密钥', 'mdi-check', '审核API密钥')]
    public function approve(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $action = $this->request->getPost('action'); // approve or reject

        try {
            $apiKey = $this->aiApiKey->reset()->load($id);
            
            if (!$apiKey->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('API密钥不存在')
                ]);
            }

            if ($action === 'approve') {
                $apiKey->setData('status', 'approved');
                $apiKey->setData('is_active', 1);
                $message = __('API密钥已审核通过');
            } else {
                $apiKey->setData('status', 'rejected');
                $apiKey->setData('is_active', 0);
                $message = __('API密钥已拒绝');
            }

            $apiKey->setData('updated_time', time());
            $apiKey->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('操作失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 设置配额
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_apikey_set_quota', '设置配额', 'mdi-counter', '设置配额')]
    public function setQuota(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id');
        $quotaLimit = (int)$this->request->getPost('quota_limit');

        try {
            $apiKey = $this->aiApiKey->reset()->load($id);
            
            if (!$apiKey->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('API密钥不存在')
                ]);
            }

            $apiKey->setData('quota_limit', $quotaLimit);
            $apiKey->setData('updated_time', time());
            $apiKey->save();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('配额设置成功')
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('设置失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 删除API密钥
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_apikey_delete', '删除API密钥', 'mdi-delete', '删除API密钥')]
    public function delete(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $id = (int)$this->request->getPost('id');

        try {
            $apiKey = $this->aiApiKey->reset()->load($id);
            
            if (!$apiKey->getId()) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('API密钥不存在')
                ]);
            }

            $apiKey->delete();

            return $this->jsonResponse([
                'success' => true,
                'message' => __('API密钥已删除')
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('删除失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 显示创建/编辑API密钥表单（Offcanvas）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_apikey_form', '创建/编辑API密钥表单', 'mdi-form', '创建/编辑API密钥表单')]
    public function form(): string
    {
        $id = (int)$this->request->getGet('id');
        
        if ($id) {
            $apiKey = $this->aiApiKey->reset()->load($id);
            
            if (!$apiKey->getId()) {
                return '<div class="alert alert-danger">' . __('API密钥不存在') . '</div>';
            }
            
            $this->assign('apiKey', $apiKey);
        } else {
            // 创建新密钥时传空对象
            $this->assign('apiKey', $this->aiApiKey->reset());
        }
        
        return $this->fetch();
    }

    /**
     * 搜索前端用户（AJAX接口）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_apikey_search_users', '搜索用户', 'mdi-account-search', '搜索用户')]
    public function searchUsers(): string
    {
        $keyword = trim($this->request->getGet('keyword', ''));
        
        try {
            $frontendUserModel = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Frontend\Model\FrontendUser::class);
            
            if (empty($keyword)) {
                // 如果没有关键词，返回前20个用户
                $users = $frontendUserModel->reset()
                    ->select()
                    ->limit(20)
                    ->fetch()
                    ->getItems();
            } else {
                // 搜索用户名、邮箱
                $users = $frontendUserModel->reset()
                    ->where('username', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%")
                    ->limit(20)
                    ->fetch()
                    ->getItems();
            }
            
            $result = [];
            foreach ($users as $user) {
                $result[] = [
                    'id' => $user->getId(),
                    'username' => $user->getData('username'),
                    'email' => $user->getData('email'),
                    'nickname' => $user->getData('nickname') ?: $user->getData('username'),
                ];
            }
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $result,
                'total' => count($result)
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('搜索用户失败：%{1}', $e->getMessage()),
                'data' => []
            ]);
        }
    }

    /**
     * 搜索租户（AJAX接口）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_apikey_search_tenants', '搜索租户', 'mdi-domain', '搜索租户')]
    public function searchTenants(): string
    {
        $keyword = trim($this->request->getGet('keyword', ''));
        
        try {
            $tenantModel = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Ai\Model\AiTenant::class);
            
            if (empty($keyword)) {
                // 如果没有关键词，返回前20个租户
                $tenants = $tenantModel->reset()
                    ->select()
                    ->limit(20)
                    ->fetch()
                    ->getItems();
            } else {
                // 搜索租户名称
                $tenants = $tenantModel->reset()
                    ->where('name', 'like', "%{$keyword}%")
                    ->limit(20)
                    ->fetch()
                    ->getItems();
            }
            
            $result = [];
            foreach ($tenants as $tenant) {
                $result[] = [
                    'id' => $tenant->getId(),
                    'name' => $tenant->getData('name'),
                ];
            }
            
            return $this->jsonResponse([
                'success' => true,
                'data' => $result,
                'total' => count($result)
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('搜索租户失败：%{1}', $e->getMessage()),
                'data' => []
            ]);
        }
    }

    /**
     * 保存API密钥（创建或更新）
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_apikey_save', '保存API密钥', 'mdi-content-save', '保存API密钥')]
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
            $apiKey = $this->aiApiKey->reset();
            
            if ($id) {
                $apiKey->load($id);
                
                if (!$apiKey->getId()) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('API密钥不存在')
                    ]);
                }
            }
            
            // 验证必填字段
            if (empty($data['name'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('密钥名称不能为空')
                ]);
            }

            if (empty($data['user_id'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('用户ID不能为空')
                ]);
            }

            if (empty($data['tenant_id'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('租户ID不能为空')
                ]);
            }
            
            // 设置数据
            $apiKey->setData('name', $data['name']);
            $apiKey->setData('user_id', (int)$data['user_id']);
            $apiKey->setData('tenant_id', (int)$data['tenant_id']);
            $apiKey->setData('quota_daily', isset($data['quota_daily']) ? (int)$data['quota_daily'] : null);
            $apiKey->setData('quota_monthly', isset($data['quota_monthly']) ? (int)$data['quota_monthly'] : null);
            
            // 新建时自动生成API密钥
            if (!$id) {
                $apiKey->setData('token', $this->generateApiKey());
                $apiKey->setData('status', 'pending'); // 新建默认待审核
                $apiKey->setData('usage_daily', 0);
                $apiKey->setData('usage_monthly', 0);
            }
            
            // 时间字段由数据库自动管理（CURRENT_TIMESTAMP）
            
            // 保存
            $apiKey->save();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('API密钥保存成功'),
                'api_key_id' => $apiKey->getId()
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 生成随机API密钥
     * 
     * @return string
     */
    private function generateApiKey(): string
    {
        return 'ak_' . bin2hex(random_bytes(32));
    }

    /**
     * JSON响应
     * 
     * @param array $data
     * @return string
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}

