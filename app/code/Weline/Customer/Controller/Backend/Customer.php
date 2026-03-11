<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Frontend\Model\FrontendUser;
use Weline\Frontend\Model\FrontendUserToken;

#[Acl('Weline_Customer::customer', '前端客户', 'mdi-account-group', '前端客户', 'Weline_Backend::customer_group')]
class Customer extends BackendController
{
    #[Acl('Weline_Customer::customer_index', '查看前端客户', 'mdi-account', '查看前端客户')]
    public function index(): string
    {
        try {
            $page = max(1, (int)($this->request->getParam('page') ?? 1));
            $limit = (int)($this->request->getParam('limit') ?? 20);
            $limit = $limit > 0 ? min($limit, 100) : 20;
            $keyword = trim((string)($this->request->getParam('keyword') ?? ''));

            /** @var FrontendUser $userModel */
            $userModel = ObjectManager::getInstance(FrontendUser::class, [], false);
            $query = $userModel->reset();

            if ($keyword !== '') {
                $query->where(FrontendUser::schema_fields_username, "%{$keyword}%", 'LIKE');
            }

            $query->order(FrontendUser::schema_fields_ID, 'DESC');
            $collection = $query->pagination($page, $limit);
            $items = $collection->select()->fetch()->getItems();
            $total = (int)$collection->getTotal();
            $totalPages = (int)ceil($total / $limit);

            $customers = [];
            $userIds = [];

            foreach ($items as $item) {
                $data = is_object($item) ? $item->getData() : (array)$item;
                $userId = (int)($data[FrontendUser::schema_fields_ID] ?? $data['user_id'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }
                $userIds[] = $userId;
                $customers[] = [
                    'user_id'       => $userId,
                    'username'      => $data['username'] ?? '',
                    'avatar'        => $data['avatar'] ?? '',
                    'login_ip'      => $data['login_ip'] ?? '',
                    'attempt_times' => (int)($data['attempt_times'] ?? 0),
                    'sess_id'       => $data['sess_id'] ?? '',
                    'token_count'   => 0,
                    'is_sandbox'    => (int)($data[FrontendUser::schema_fields_is_sandbox] ?? 0),
                ];
            }

            if (!empty($userIds)) {
                try {
                /** @var FrontendUserToken $tokenModel */
                $tokenModel = ObjectManager::getInstance(FrontendUserToken::class, [], false);
                    $userIds = array_unique($userIds);
                    $tokenCounts = [];
                    foreach ($userIds as $userId) {
                        $tokenCounts[$userId] = (int)$tokenModel->reset()
                            ->where(FrontendUserToken::schema_fields_user_id, $userId)
                            ->count();
                    }

                    foreach ($customers as &$customer) {
                        $id = $customer['user_id'];
                        $customer['token_count'] = $tokenCounts[$id] ?? 0;
                    }
                    unset($customer);
                } catch (\Throwable $tokenException) {
                    Message::warning(__('无法统计令牌数量：%{1}', [$tokenException->getMessage()]));
                }
            }

            $this->assign('customers', $customers);
            $this->assign('customers_json', json_encode($customers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));
            $this->assign('total', $total);
            $this->assign('page', $page);
            $this->assign('limit', $limit);
            $this->assign('total_pages', $totalPages);
            $this->assign('keyword', $keyword);

            return $this->fetch();
        } catch (\Throwable $e) {
            Message::error(__('加载前端客户失败：%{1}', [$e->getMessage()]));
            $this->assign('customers', []);
            $this->assign('customers_json', '[]');
            $this->assign('total', 0);
            $this->assign('page', 1);
            $this->assign('limit', 20);
            $this->assign('total_pages', 0);
            $this->assign('keyword', '');
            return $this->fetch();
        }
    }

    #[Acl('Weline_Customer::customer_detail', '查看前端客户详情', 'mdi-account-details', '查看前端客户详情')]
    public function detail(): string
    {
        $userId = (int)($this->request->getParam('user_id') ?? 0);
        if ($userId <= 0) {
            return $this->jsonResponse(false, __('无效的用户ID'));
        }

        /** @var FrontendUser $userModel */
        $userModel = ObjectManager::getInstance(FrontendUser::class, [], false);
        $user = $userModel->reset()->load($userId);

        if (!$user->getId()) {
            return $this->jsonResponse(false, __('用户不存在'));
        }

        $data = $user->getData();
        unset($data['password']);

        return $this->jsonResponse(true, __('获取成功'), [
            'user' => [
                'user_id'       => (int)$data[FrontendUser::schema_fields_ID],
                'username'      => $data['username'] ?? '',
                'avatar'        => $data['avatar'] ?? '',
                'login_ip'      => $data['login_ip'] ?? '',
                'attempt_times' => (int)($data['attempt_times'] ?? 0),
                'sess_id'       => $data['sess_id'] ?? '',
                'is_sandbox'    => (int)($data[FrontendUser::schema_fields_is_sandbox] ?? 0),
            ],
        ]);
    }

    #[Acl('Weline_Customer::customer_save', '保存前端客户', 'mdi-content-save', '保存前端客户')]
    public function save(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        $userId = (int)$this->request->getPost('user_id', 0);
        $username = trim((string)$this->request->getPost('username', ''));
        $password = (string)$this->request->getPost('password', '');
        $avatar = trim((string)$this->request->getPost('avatar', ''));
        $resetAttempts = (int)$this->request->getPost('reset_attempts', 0);
        $isSandbox = (int)$this->request->getPost('is_sandbox', 0);

        if ($username === '') {
            return $this->jsonResponse(false, __('用户名不能为空'));
        }

        /** @var FrontendUser $userModel */
        $userModel = ObjectManager::getInstance(FrontendUser::class);
        if ($userId > 0) {
            $userModel->load($userId);
            if (!$userModel->getId()) {
                return $this->jsonResponse(false, __('用户不存在'));
            }
        } else {
            $userModel->reset();
        }

        // 检查用户名是否重复
        /** @var FrontendUser $duplicateChecker */
        $duplicateChecker = ObjectManager::getInstance(FrontendUser::class, [], false);
        $duplicateChecker->reset()
            ->where(FrontendUser::schema_fields_username, $username);
        if ($userId > 0) {
            $duplicateChecker->where(FrontendUser::schema_fields_ID, $userId, '!=');
        }
        $duplicateChecker->find()->fetch();
        if ($duplicateChecker->getId()) {
            return $this->jsonResponse(false, __('用户名已存在'));
        }

        $userModel->setUsername($username);
        if ($avatar !== '') {
            $userModel->setAvatar($avatar);
        }

        $userModel->setSandboxAccount((bool)$isSandbox);

        if ($password !== '') {
            $userModel->setPassword($password);
        } elseif ($userId <= 0) {
            return $this->jsonResponse(false, __('新建用户必须设置密码'));
        }

        if ($resetAttempts) {
            $userModel->setData(FrontendUser::schema_fields_attempt_times, 0);
            $userModel->setAttemptIp('');
        }

        $userModel->save();

        return $this->jsonResponse(true, __('保存成功'));
    }

    #[Acl('Weline_Customer::customer_delete', '删除前端客户', 'mdi-delete', '删除前端客户')]
    public function postDelete(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        $userId = (int)$this->request->getPost('user_id', 0);
        if ($userId <= 0) {
            return $this->jsonResponse(false, __('无效的用户ID'));
        }

        /** @var FrontendUser $userModel */
        $userModel = ObjectManager::getInstance(FrontendUser::class, [], false);
        $userModel->load($userId);
        if (!$userModel->getId()) {
            return $this->jsonResponse(false, __('用户不存在'));
        }

        /** @var FrontendUserToken $tokenModel */
        $tokenModel = ObjectManager::getInstance(FrontendUserToken::class, [], false);
        $tokenModel->reset()
            ->where(FrontendUserToken::schema_fields_user_id, $userId)
            ->delete()->fetch();

        $userModel->delete()->fetch();

        if ($userModel->getId()) {
            return $this->jsonResponse(false, __('删除失败'));
        }

        return $this->jsonResponse(true, __('删除成功'));
    }

    #[Acl('Weline_Customer::customer_reset_token', '重置前端令牌', 'mdi-refresh', '重置前端令牌')]
    public function resetToken(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        $userId = (int)$this->request->getPost('user_id', 0);
        if ($userId <= 0) {
            return $this->jsonResponse(false, __('无效的用户ID'));
        }

        /** @var FrontendUser $userModel */
        $userModel = ObjectManager::getInstance(FrontendUser::class, [], false);
        $userModel->load($userId);
        if (!$userModel->getId()) {
            return $this->jsonResponse(false, __('用户不存在'));
        }

        /** @var FrontendUserToken $tokenModel */
        $tokenModel = ObjectManager::getInstance(FrontendUserToken::class, [], false);
        $tokenModel->builder()
            ->where(FrontendUserToken::schema_fields_user_id, $userId)
            ->delete();

        $userModel->setSessionId('')->save();

        return $this->jsonResponse(true, __('令牌已重置'));
    }

    #[Acl('Weline_Customer::customer_reset_password', '重置前端密码', 'mdi-lock-reset', '重置前端密码')]
    public function resetPassword(): string
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(false, __('无效的请求方法'));
        }

        $userId = (int)$this->request->getPost('user_id', 0);
        if ($userId <= 0) {
            return $this->jsonResponse(false, __('无效的用户ID'));
        }

        $newPassword = (string)$this->request->getPost('new_password', '');
        if ($newPassword === '') {
            $newPassword = $this->generateTempPassword();
        }

        /** @var FrontendUser $userModel */
        $userModel = ObjectManager::getInstance(FrontendUser::class, [], false);
        $userModel->load($userId);
        if (!$userModel->getId()) {
            return $this->jsonResponse(false, __('用户不存在'));
        }

        $userModel->setPassword($newPassword);
        $userModel->setSessionId('');
        $userModel->save();

        /** @var FrontendUserToken $tokenModel */
        $tokenModel = ObjectManager::getInstance(FrontendUserToken::class, [], false);
        $tokenModel->builder()
            ->where(FrontendUserToken::schema_fields_user_id, $userId)
            ->delete();

        return $this->jsonResponse(true, __('密码已重置'), [
            'new_password' => $newPassword,
        ]);
    }

    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function generateTempPassword(int $length = 10): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$%!&*';
        $chars = str_split($alphabet);
        $password = '';
        $maxIndex = count($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $maxIndex)];
        }
        return $password;
    }
}

