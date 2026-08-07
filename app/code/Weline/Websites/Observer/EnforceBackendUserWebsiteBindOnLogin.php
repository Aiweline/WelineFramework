<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Backend\Api\Auth\BackendLoginAccount;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\WebsiteAclGrantService;

/**
 * 密码校验通过后：非超管用户只能登录其绑定 website_id 的后台会话。
 * 通过 Admin 已有事件介入，避免 Admin/Acl 反向依赖 Websites。
 */
final class EnforceBackendUserWebsiteBindOnLogin implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if ((bool)$event->getData('handled')) {
            return;
        }

        $user = $event->getData('user');
        if (!$user instanceof BackendLoginAccount) {
            return;
        }

        $userId = (int)$user->getId();
        if ($userId === 1) {
            return;
        }

        /** @var WebsiteAclGrantService $grants */
        $grants = ObjectManager::getInstance(WebsiteAclGrantService::class);
        $currentWebsiteId = $grants->currentWebsiteId();

        /** @var BackendUser $backendUser */
        $backendUser = ObjectManager::getInstance(BackendUser::class, [], false)->load($userId);
        if ((int)$backendUser->getId() !== $userId) {
            return;
        }

        $boundWebsiteId = $backendUser->getWebsiteId();
        if ($boundWebsiteId !== $currentWebsiteId) {
            $event->setData('handled', true);
            $event->setData('error', new \RuntimeException(
                (string)__('当前后台站点与该管理员绑定站点不一致，无法登录')
            ));
        }
    }
}
