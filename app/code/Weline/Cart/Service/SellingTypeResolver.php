<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CommerceCartTypeInterface;

/**
 * 解析当前售卖类型：仅认 Registry；会话偏好不是权威源。
 * 不 import Weline_B2B：需登录的类型由 Provider 元数据声明；membership 由可选钩子/Interface 注入。
 * 未注册偏好（含已卸载 Provider 的残留 code）fail-soft 到内置 toc，不抛热路径冲突。
 */
final class SellingTypeResolver
{
    public const ERROR_LOGIN_REQUIRED = 'cart_commerce_type_login_required';
    public const ERROR_MEMBERSHIP_REQUIRED = 'cart_commerce_type_membership_required';
    public const ERROR_PREFERENCE_UNKNOWN = 'cart_commerce_type_unregistered';

    /** @var (callable(string):bool)|null */
    private $membershipChecker;

    public function __construct(
        private readonly CommerceCartTypeRegistry $registry,
        ?callable $membershipChecker = null,
        private readonly ?CommerceTypeMembershipGate $membershipGate = null,
    ) {
        $this->membershipChecker = $membershipChecker;
    }

    public static function forTesting(
        ?CommerceCartTypeRegistry $registry = null,
        ?callable $membershipChecker = null,
        ?CommerceTypeMembershipGate $membershipGate = null,
    ): self {
        return new self(
            $registry ?? CommerceCartTypeRegistry::forTesting(),
            $membershipChecker,
            $membershipGate,
        );
    }

    /**
     * @return array{code:string,type:CommerceCartTypeInterface}
     */
    public function resolve(
        ?string $preferredCode,
        bool $customerLoggedIn,
        int $customerId = 0,
        int $websiteId = 0,
    ): array {
        $preferred = strtolower(trim((string)$preferredCode));
        if ($preferred === '') {
            $preferred = CommerceCartTypeRegistry::CODE_TOC;
        }

        if (!$this->registry->has($preferred)) {
            // Provider 已卸载（例：B2B 卸后会话/cookie 仍偏好 tob）→ 回落内置 toc，禁止热路径 500。
            $type = $this->registry->require(CommerceCartTypeRegistry::CODE_TOC);

            return ['code' => $type->getCode(), 'type' => $type];
        }

        $type = $this->registry->require($preferred);
        if ($type->requiresCustomerLogin() && !$customerLoggedIn) {
            throw new CartConflictException(
                self::ERROR_LOGIN_REQUIRED,
                __('该售卖类型需要登录'),
                ['code' => $type->getCode()],
            );
        }

        if ($type->getCode() !== CommerceCartTypeRegistry::CODE_TOC
            && !$this->customerHasMembership($type->getCode(), $customerId, $websiteId)
        ) {
            throw new CartConflictException(
                self::ERROR_MEMBERSHIP_REQUIRED,
                __('请先完成批发身份审核'),
                ['code' => $type->getCode()],
            );
        }

        return ['code' => $type->getCode(), 'type' => $type];
    }

    public function defaultCode(): string
    {
        return CommerceCartTypeRegistry::CODE_TOC;
    }

    private function customerHasMembership(string $typeCode, int $customerId, int $websiteId): bool
    {
        if ($this->membershipChecker !== null) {
            return (bool)($this->membershipChecker)($typeCode);
        }

        $gate = $this->membershipGate ?? new CommerceTypeMembershipGate();
        return $gate->hasMembership($typeCode, $customerId, $websiteId);
    }
}
