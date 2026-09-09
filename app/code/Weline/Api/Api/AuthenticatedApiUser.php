<?php
declare(strict_types=1);

namespace Weline\Api\Api;

/** 已验证的 API 用户身份；仅由服务端认证流程写入当前请求。 */
final readonly class AuthenticatedApiUser
{
    public function __construct(private int $userId, private int $roleId)
    {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getRoleId(): int
    {
        return $this->roleId;
    }

    public function getIdempotencyScope(): string
    {
        return 'api_user:' . $this->userId;
    }
}
