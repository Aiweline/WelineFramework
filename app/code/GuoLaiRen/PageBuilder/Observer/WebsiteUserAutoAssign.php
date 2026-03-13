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
 * - 新建站点：默认分配给创建者（当前登录用户）
 * - 编辑站点：不覆盖已有分配，用户可在「站点分配」页面修改
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

        // 若已有分配记录则跳过（默认分配仅对新建生效，编辑不覆盖，可在站点分配页面修改）
        $existing = clone $this->websiteUser;
        $existing->clear()
            ->where(WebsiteUser::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();
        if ($existing->getId()) {
            return;
        }

        // 新建站点：默认分配给当前用户（创建者）
        $newMapping = clone $this->websiteUser;
        $newMapping->clear()
            ->setData(WebsiteUser::schema_fields_WEBSITE_ID, $websiteId)
            ->setData(WebsiteUser::schema_fields_BACKEND_USER_ID, $backendUserId)
            ->setData(WebsiteUser::schema_fields_IS_OWNER, 1)
            ->save(true);
    }
}

