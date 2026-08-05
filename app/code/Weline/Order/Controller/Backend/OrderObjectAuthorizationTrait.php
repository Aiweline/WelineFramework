<?php

declare(strict_types=1);

namespace Weline\Order\Controller\Backend;

use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Order\Model\Order;
use Weline\Order\Service\OrderObjectAccessService;

trait OrderObjectAuthorizationTrait
{
    /** @return array{allowed:bool,grant_version:int} */
    private function orderActionGrant(int $orderId, string $action): array
    {
        $record = ObjectManager::getInstance(OrderObjectAccessService::class)->find($orderId);
        if ($record === null) {
            return ['allowed' => false, 'grant_version' => 0];
        }
        $grant = ObjectManager::getInstance(
            BackendObjectAuthorizationGuardInterface::class,
        )->check($action, $record['scope']);

        return [
            'allowed' => $grant->allowed,
            'grant_version' => $grant->allowed ? $grant->matchedGrantVersion : 0,
        ];
    }

    /**
     * @return array{order:Order,scope:ScopeIdentity}
     */
    private function requireOrderSubmit(int $orderId, string $action): array
    {
        $record = ObjectManager::getInstance(OrderObjectAccessService::class)->find($orderId);
        $guard = ObjectManager::getInstance(BackendObjectAuthorizationGuardInterface::class);
        if ($record === null) {
            $guard->denyForQuery($action, ScopeIdentity::global());
        }
        $guard->requireSubmitForQuery($action, $record['scope'], $this->orderExpectedGrantVersion());

        return $record;
    }

    /**
     * @return array{order:Order,scope:ScopeIdentity}
     */
    private function requireOrderRead(int $orderId, string $action): array
    {
        $record = ObjectManager::getInstance(OrderObjectAccessService::class)->find($orderId);
        $guard = ObjectManager::getInstance(BackendObjectAuthorizationGuardInterface::class);
        if ($record === null) {
            $guard->denyForQuery($action, ScopeIdentity::global());
        }
        $guard->requireForQuery($action, $record['scope']);

        return $record;
    }

    private function orderExpectedGrantVersion(): int
    {
        $value = $this->request->getParam('expected_grant_version', 0);
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return (int)$value;
        }

        return 0;
    }
}
