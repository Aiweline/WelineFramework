<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Attribute\Acl as AclAttribute;
use Weline\Framework\Manager\Message;

/**
 * 前端用户管理控制器
 */
class User extends BackendController
{
    /**
     * 获取前端用户模型（懒加载）
     */
    private function getFrontendUser(): \Weline\Frontend\Model\FrontendUser
    {
        return \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Frontend\Model\FrontendUser::class);
    }

    /**
     * 用户列表页
     */
    #[AclAttribute('Weline_Ai::ai_user_index', '前端用户列表', 'mdi-account-multiple', '查看前端用户列表')]
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
            $query = $this->getFrontendUser()->reset()->select();
            
            // 搜索过滤
            if (!empty($keyword)) {
                $query->where('username', 'like', "%{$keyword}%")
                      ->orWhere('email', 'like', "%{$keyword}%")
                      ->orWhere('nickname', 'like', "%{$keyword}%");
            }
            
            // 状态过滤
            if ($status !== '') {
                $query->where('is_active', '=', (int)$status);
            }
            
            // 统计
            $total = $query->count();
            
            // 分页查询
            $users = $query
                ->limit($pageSize, ($page - 1) * $pageSize)
                ->order('user_id', 'DESC')
                ->fetch()
                ->getItems();
            
            // 计算分页信息
            $totalPages = ceil($total / $pageSize);
            
            $this->assign('users', $users);
            $this->assign('total', $total);
            $this->assign('page', $page);
            $this->assign('pageSize', $pageSize);
            $this->assign('totalPages', $totalPages);
            $this->assign('keyword', $keyword);
            $this->assign('current_status', $status);
            
            return $this->fetch();
            
        } catch (\Exception $e) {
            Message::error(__('加载用户列表失败：%{1}', $e->getMessage()));
            $this->assign('users', []);
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
     * 创建/编辑用户表单
     */
    #[AclAttribute('Weline_Ai::ai_user_form', '用户表单', 'mdi-form', '创建/编辑前端用户表单')]
    public function form(): string
    {
        $id = (int)$this->request->getGet('id');
        
        if ($id) {
            $user = $this->getFrontendUser()->reset()->load($id);
            
            // FrontendUser的主键是user_id
            if (!$user->getData('user_id')) {
                return '<div class="alert alert-danger">' . __('用户不存在') . '</div>';
            }
            
            $this->assign('user', $user);
        } else {
            $this->assign('user', $this->getFrontendUser()->reset());
        }
        
        return $this->fetch();
    }

    /**
     * 保存用户
     */
    #[AclAttribute('Weline_Ai::ai_user_save', '保存用户', 'mdi-content-save', '保存前端用户')]
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
            $user = $this->getFrontendUser()->reset();
            
            if ($id) {
                $user->load($id);
                
                // FrontendUser的主键是user_id，需要特殊处理
                if (!$user->getData('user_id')) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('用户不存在')
                    ]);
                }
            }
            
            // 验证必填字段
            if (empty($data['username'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('用户名不能为空')
                ]);
            }

            if (empty($data['email'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('邮箱不能为空')
                ]);
            }
            
            // 检查用户名重复
            if (!$id || $data['username'] !== $user->getData('username')) {
                $existUser = $this->getFrontendUser()->reset()
                    ->where('username', '=', $data['username'])
                    ->fetchOne();
                if ($existUser && $existUser->getData('user_id')) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('用户名已存在')
                    ]);
                }
            }
            
            // 检查邮箱重复
            if (!empty($data['email']) && (!$id || $data['email'] !== $user->getData('email'))) {
                $existUser = $this->getFrontendUser()->reset()
                    ->where('email', '=', $data['email'])
                    ->fetchOne();
                if ($existUser && $existUser->getData('user_id')) {
                    return $this->jsonResponse([
                        'success' => false,
                        'message' => __('邮箱已存在')
                    ]);
                }
            }
            
            // 设置数据
            $user->setData('username', $data['username']);
            $user->setData('email', $data['email']);
            $user->setData('nickname', $data['nickname'] ?? $data['username']);
            $user->setData('is_active', isset($data['is_active']) ? (int)$data['is_active'] : 1);
            
            // 如果是新用户且设置了密码
            if (!$id && !empty($data['password'])) {
                $user->setData('password', password_hash($data['password'], PASSWORD_DEFAULT));
            }
            // 如果是编辑且提供了新密码
            elseif ($id && !empty($data['password'])) {
                $user->setData('password', password_hash($data['password'], PASSWORD_DEFAULT));
            }
            
            // 保存
            $user->save();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __('保存成功'),
                'data' => ['id' => $user->getData('user_id')]
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('保存失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 切换用户状态
     */
    #[AclAttribute('Weline_Ai::ai_user_toggle_status', '切换用户状态', 'mdi-toggle-switch', '切换用户激活状态')]
    public function toggleStatus(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        // 支持两种格式的请求
        $postData = $this->request->getPost();
        $jsonData = json_decode(file_get_contents('php://input'), true);
        $id = (int)($postData['id'] ?? $jsonData['id'] ?? 0);
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('用户ID不能为空')
            ]);
        }
        
        try {
            $user = $this->getFrontendUser()->reset()->load($id);
            
            // FrontendUser的主键是user_id
            if (!$user->getData('user_id')) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('用户不存在')
                ]);
            }
            
            // 切换状态
            $currentStatus = (int)$user->getData('is_active');
            $newStatus = $currentStatus ? 0 : 1;
            $user->setData('is_active', $newStatus);
            $user->save();
            
            return $this->jsonResponse([
                'success' => true,
                'message' => __($newStatus ? '用户已激活' : '用户已禁用')
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('操作失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 删除用户
     */
    #[AclAttribute('Weline_Ai::ai_user_delete', '删除用户', 'mdi-delete', '删除前端用户')]
    public function delete(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        // 支持两种格式的请求
        $postData = $this->request->getPost();
        $jsonData = json_decode(file_get_contents('php://input'), true);
        $id = (int)($postData['id'] ?? $jsonData['id'] ?? 0);
        
        if (!$id) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('用户ID不能为空')
            ]);
        }
        
        try {
            $user = $this->getFrontendUser()->reset()->load($id);
            
            // FrontendUser的主键是user_id
            if (!$user->getData('user_id')) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => __('用户不存在')
                ]);
            }
            
            $user->delete();
            
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

