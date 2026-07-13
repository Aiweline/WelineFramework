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

        $users = $users
            ->order(FrontendUser::schema_fields_ID, 'DESC')
            ->pagination(max(1, $page), max(1, $pageSize))
            ->select()
            ->fetch();

        $identities = [];
        foreach ($users->getItems() as $user) {
            if ($user instanceof FrontendUser) {
                $identities[] = $this->map($user);
            }
        }

        return new FrontendUserSearchResult($identities, $users->getPagination());
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

    private function map(FrontendUser $user): FrontendUserIdentity
    {
        return new FrontendUserIdentity(
            (int) ($user->getId() ?: $user->getData(FrontendUser::schema_fields_ID)),
            (string) ($user->getData(FrontendUser::schema_fields_username) ?: ''),
            (string) ($user->getData('email') ?: ''),
            (string) ($user->getData(FrontendUser::schema_fields_avatar) ?: ''),
        );
    }
}
