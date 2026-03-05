<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Observer;

use GuoLaiRen\PageBuilder\Model\WebsiteUser;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 网站保存后自动为当前后台用户建立站点归属关系
 *
 * 规则：
 * - 一个站点同一时间只能分配给一个后台用户
 * - 为站点分配新的用户时，会自动卸载之前的分配（删除旧记录）
 */
class WebsiteUserAutoAssign implements ObserverInterface
{
    private WebsiteUser $websiteUser;
    private AuthenticatedSessionInterface $backendSession;

    public function __construct(
        WebsiteUser   $websiteUser
    ) {
        $this->websiteUser = $websiteUser;
        $this->backendSession = SessionFactory::getInstance()->createBackendSession();
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        /** @var \Weline\Websites\Model\Website|null $website */
        $website = $event->getEvenData('website');
        if (!$website || !$website->getWebsiteId()) {
            return;
        }

        $backendUserId = (int)$this->backendSession->getUserId();
        if ($backendUserId <= 0) {
            return;
        }

        $websiteId = (int)$website->getWebsiteId();

        // 先删除该站点之前的归属关系（一个站点只能绑定一个用户）
        $mappingCleaner = clone $this->websiteUser;
        $mappingCleaner->clear()
            ->where(WebsiteUser::schema_fields_WEBSITE_ID, $websiteId)
            ->delete()
            ->fetch();

        // 为当前用户建立新的归属关系
        $newMapping = clone $this->websiteUser;
        $newMapping->clear()
            ->setData(WebsiteUser::schema_fields_WEBSITE_ID, $websiteId)
            ->setData(WebsiteUser::schema_fields_BACKEND_USER_ID, $backendUserId)
            ->setData(WebsiteUser::schema_fields_IS_OWNER, 1)
            ->save(true);
    }
}

