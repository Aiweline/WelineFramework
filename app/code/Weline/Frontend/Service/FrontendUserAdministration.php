<?php

declare(strict_types=1);

namespace Weline\Frontend\Service;

use Weline\Frontend\Api\User\FrontendUserAdministrationInterface;
use Weline\Frontend\Api\User\FrontendUserMutationResult;
use Weline\Frontend\Api\User\FrontendUserPage;
use Weline\Frontend\Api\User\FrontendUserRecord;
use Weline\Frontend\Api\User\FrontendUserSaveCommand;
use Weline\Frontend\Model\FrontendUser;
use Weline\Frontend\Model\FrontendUserToken;

final class FrontendUserAdministration implements FrontendUserAdministrationInterface
{
    public function __construct(
        private readonly FrontendUser $userPrototype,
        private readonly FrontendUserToken $tokenPrototype,
    ) {
    }

    public function search(string $keyword, int $page, int $pageSize): FrontendUserPage
    {
        $users = $this->newUser()->reset();
        if ($keyword !== '') {
            $users->where(FrontendUser::schema_fields_username, "%{$keyword}%", 'LIKE');
        }

        $collection = $users
            ->order(FrontendUser::schema_fields_ID, 'DESC')
            ->pagination($page, $pageSize);
        $items = $collection->select()->fetch()->getItems();
        $total = (int)$collection->getTotal();

        $rows = [];
        $userIds = [];
        foreach ($items as $item) {
            $data = is_object($item) && method_exists($item, 'getData') ? $item->getData() : (array)$item;
            $userId = (int)($data[FrontendUser::schema_fields_ID] ?? $data['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $rows[$userId] = $data;
            $userIds[] = $userId;
        }

        $tokenCounts = [];
        $tokenCountError = null;
        if ($userIds !== []) {
            try {
                $tokens = $this->newToken();
                foreach (array_unique($userIds) as $userId) {
                    $tokenCounts[$userId] = (int)$tokens->reset()
                        ->where(FrontendUserToken::schema_fields_user_id, $userId)
                        ->count();
                }
            } catch (\Throwable $exception) {
                $tokenCountError = $exception->getMessage();
            }
        }

        $records = [];
        foreach ($rows as $userId => $data) {
            $records[] = $this->map($data, $tokenCounts[$userId] ?? 0);
        }

        return new FrontendUserPage($records, $total, $page, $pageSize, $tokenCountError);
    }

    public function find(int $userId): ?FrontendUserRecord
    {
        if ($userId <= 0) {
            return null;
        }
        $user = $this->newUser()->load($userId);

        return $user->getId() ? $this->map($user->getData(), 0) : null;
    }

    public function save(FrontendUserSaveCommand $command): FrontendUserMutationResult
    {
        $user = $this->newUser();
        if ($command->userId > 0) {
            $user->load($command->userId);
            if (!$user->getId()) {
                return new FrontendUserMutationResult(FrontendUserMutationResult::NOT_FOUND);
            }
        } else {
            $user->reset();
        }

        $duplicate = $this->newUser()->reset()
            ->where(FrontendUser::schema_fields_username, $command->username);
        if ($command->userId > 0) {
            $duplicate->where(FrontendUser::schema_fields_ID, $command->userId, '!=');
        }
        $duplicate->find()->fetch();
        if ($duplicate->getId()) {
            return new FrontendUserMutationResult(FrontendUserMutationResult::DUPLICATE_USERNAME);
        }

        $user->setUsername($command->username);
        if ($command->avatar !== '') {
            $user->setAvatar($command->avatar);
        }
        $user->setSandboxAccount($command->sandbox);

        if ($command->password !== '') {
            $user->setPassword($command->password);
        } elseif ($command->userId <= 0) {
            return new FrontendUserMutationResult(FrontendUserMutationResult::PASSWORD_REQUIRED);
        }

        if ($command->resetAttempts) {
            $user->setData(FrontendUser::schema_fields_attempt_times, 0);
            $user->setAttemptIp('');
        }

        $user->save();

        return new FrontendUserMutationResult(FrontendUserMutationResult::SAVED);
    }

    public function delete(int $userId): FrontendUserMutationResult
    {
        $user = $this->newUser()->load($userId);
        if (!$user->getId()) {
            return new FrontendUserMutationResult(FrontendUserMutationResult::NOT_FOUND);
        }

        $this->newToken()->reset()
            ->where(FrontendUserToken::schema_fields_user_id, $userId)
            ->delete()
            ->fetch();

        $user->delete()->fetch();

        return new FrontendUserMutationResult(
            $user->getId() ? FrontendUserMutationResult::DELETE_FAILED : FrontendUserMutationResult::DELETED,
        );
    }

    public function resetToken(int $userId): FrontendUserMutationResult
    {
        $user = $this->newUser()->load($userId);
        if (!$user->getId()) {
            return new FrontendUserMutationResult(FrontendUserMutationResult::NOT_FOUND);
        }

        $this->newToken()->reset()
            ->where(FrontendUserToken::schema_fields_user_id, $userId)
            ->delete();

        $user->setSessionId('')->save();

        return new FrontendUserMutationResult(FrontendUserMutationResult::TOKEN_RESET);
    }

    public function resetPassword(int $userId, string $newPassword): FrontendUserMutationResult
    {
        $user = $this->newUser()->load($userId);
        if (!$user->getId()) {
            return new FrontendUserMutationResult(FrontendUserMutationResult::NOT_FOUND);
        }

        $user->setPassword($newPassword);
        $user->setSessionId('');
        $user->save();

        $this->newToken()->reset()
            ->where(FrontendUserToken::schema_fields_user_id, $userId)
            ->delete();

        return new FrontendUserMutationResult(FrontendUserMutationResult::PASSWORD_RESET);
    }

    /** @param array<string, mixed> $data */
    private function map(array $data, int $tokenCount): FrontendUserRecord
    {
        return new FrontendUserRecord(
            id: (int)($data[FrontendUser::schema_fields_ID] ?? $data['user_id'] ?? 0),
            username: (string)($data[FrontendUser::schema_fields_username] ?? ''),
            avatar: (string)($data[FrontendUser::schema_fields_avatar] ?? ''),
            loginIp: (string)($data[FrontendUser::schema_fields_login_ip] ?? ''),
            attemptTimes: (int)($data[FrontendUser::schema_fields_attempt_times] ?? 0),
            sessionId: (string)($data[FrontendUser::schema_fields_sess_id] ?? ''),
            tokenCount: $tokenCount,
            sandbox: (bool)($data[FrontendUser::schema_fields_is_sandbox] ?? false),
        );
    }

    private function newUser(): FrontendUser
    {
        return (clone $this->userPrototype)->clearData()->clearQuery();
    }

    private function newToken(): FrontendUserToken
    {
        return (clone $this->tokenPrototype)->clearData()->clearQuery();
    }
}
