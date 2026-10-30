<?php

declare(strict_types=1);

namespace Weline\Frontend\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Frontend\Api\Auth\FrontendAccountFacadeInterface;
use Weline\Frontend\Api\Auth\FrontendUserIdentity;
use Weline\Frontend\Api\Auth\FrontendUserSearchResult;
use Weline\Frontend\Model\FrontendUser;

final class FrontendAccountFacade implements FrontendAccountFacadeInterface
{
    public function __construct(
        private readonly Request $request,
    ) {
    }

    public function search(string $search = '', int $page = 1, int $pageSize = 20): FrontendUserSearchResult
    {
        $users = $this->newUserModel();
        $search = trim($search);
        if ($search !== '') {
            $users->concat_like('username,email', '%' . $search . '%');
        }

        // 保留 query 引用：fetch() 返回 RowCollection（行在容器上），而分页
        // 状态仍在 query model 上，两者不能混用同一个变量。
        $collection = $users
            ->order(FrontendUser::schema_fields_ID, 'DESC')
            ->pagination(max(1, $page), max(1, $pageSize))
            ->select();
        $rows = $collection->fetchArray();

        // 列表映射只需数组字段，不逐行构造模型对象。
        $identities = [];
        foreach ($rows as $userRow) {
            if (\is_array($userRow)) {
                $identities[] = $this->map($userRow);
            }
        }

        return new FrontendUserSearchResult($identities, $collection->getPagination());
    }

    public function find(int $userId): ?FrontendUserIdentity
    {
        if ($userId <= 0) {
            return null;
        }

        $user = $this->newUserModel()->load($userId);
        return $user->getId() ? $this->map($user) : null;
    }

    public function findByUsernameOrEmail(string $username, string $email): ?FrontendUserIdentity
    {
        $user = $this->newUserModel();
        $username = trim($username);
        $email = trim($email);
        if ($username !== '') {
            $user->where(FrontendUser::schema_fields_username, $username)->find()->fetch();
        }
        if (!$user->getId() && $email !== '') {
            $user->clear()->where('email', $email)->find()->fetch();
        }

        return $user->getId() ? $this->map($user) : null;
    }

    public function loginTrustedIdentity(FrontendUserIdentity $identity, string $avatar = ''): FrontendUserIdentity
    {
        $user = $this->newUserModel()->load($identity->getId());
        if (!$user->getId()) {
            throw new \RuntimeException((string) __('用户不存在，请先在系统中注册'));
        }

        $session = SessionFactory::getInstance()->createFrontendSession();
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

    private function newUserModel(): FrontendUser
    {
        return ObjectManager::getInstance(FrontendUser::class, [], false);
    }

    /**
     * @param array<string, mixed> $user fetch() 的数组行
     */
    private function map(array $user): FrontendUserIdentity
    {
        return new FrontendUserIdentity(
            (int) ($user[FrontendUser::schema_fields_ID] ?? 0),
            (string) ($user[FrontendUser::schema_fields_username] ?? ''),
            (string) ($user['email'] ?? ''),
            (string) ($user[FrontendUser::schema_fields_avatar] ?? ''),
        );
    }
}
