<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Api\Auth\BackendAccountFacadeInterface;
use Weline\Backend\Api\Auth\BackendUserIdentity;
use Weline\Backend\Api\Auth\BackendUserSearchResult;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;

final class BackendAccountFacade implements BackendAccountFacadeInterface
{
    public function __construct(
        private readonly Request $request,
    ) {
    }

    public function search(string $search = '', int $page = 1, int $pageSize = 20): BackendUserSearchResult
    {
        $users = $this->newUserModel()
            ->where(BackendUser::schema_fields_is_deleted, 0);
        $search = trim($search);
        if ($search !== '') {
            $users->concat_like('username,email', '%' . $search . '%');
        }

        // 保留 query 引用：行由 fetchArray() 以数组返回，分页状态仍在 query 上。
        $collection = $users
            ->order(BackendUser::schema_fields_ID, 'DESC')
            ->pagination(max(1, $page), max(1, $pageSize))
            ->select();
        $rows = $collection->fetchArray();

        $identities = [];
        foreach ($rows as $userRow) {
            if (\is_array($userRow)) {
                $identities[] = $this->map($userRow);
            }
        }

        return new BackendUserSearchResult($identities, $collection->getPagination());
    }

    public function find(int $userId): ?BackendUserIdentity
    {
        if ($userId <= 0) {
            return null;
        }

        $user = $this->newUserModel()->load($userId);
        return $user->getId() ? $this->map($user) : null;
    }

    public function findByUsernameOrEmail(string $username, string $email): ?BackendUserIdentity
    {
        $user = $this->newUserModel();
        $username = trim($username);
        $email = trim($email);
        if ($username !== '') {
            $user->where(BackendUser::schema_fields_username, $username)->find()->fetch();
        }
        if (!$user->getId() && $email !== '') {
            $user->clear()->where(BackendUser::schema_fields_email, $email)->find()->fetch();
        }

        return $user->getId() ? $this->map($user) : null;
    }

    public function loginTrustedIdentity(BackendUserIdentity $identity, string $avatar = ''): BackendUserIdentity
    {
        $user = $this->newUserModel()->load($identity->getId());
        if (!$user->getId()) {
            throw new \RuntimeException((string) __('用户不存在，请联系管理员'));
        }
        if (!$user->getIsEnabled()) {
            throw new \RuntimeException((string) __('用户已被禁用'));
        }

        $session = SessionFactory::getInstance()->createBackendSession();
        $session->login($user);
        $user->setSessionId($session->getSessionId())
            ->setLoginIp($this->request->clientIP())
            ->resetAttemptTimes()
            ->save();

        if ($avatar !== '') {
            $user->setAvatar($avatar)->save();
        }

        return $this->map($user);
    }

    private function newUserModel(): BackendUser
    {
        return ObjectManager::getInstance(BackendUser::class, [], false);
    }

    /**
     * @param array<string, mixed> $user fetchArray() 的数组行
     */
    private function map(array $user): BackendUserIdentity
    {
        return new BackendUserIdentity(
            (int) ($user[BackendUser::schema_fields_ID] ?? 0),
            (string) ($user[BackendUser::schema_fields_username] ?? ''),
            (string) ($user[BackendUser::schema_fields_email] ?? ''),
            (string) ($user[BackendUser::schema_fields_avatar] ?? ''),
            (bool) ($user[BackendUser::schema_fields_is_enabled] ?? false),
            (bool) ($user[BackendUser::schema_fields_is_deleted] ?? false),
        );
    }
}
