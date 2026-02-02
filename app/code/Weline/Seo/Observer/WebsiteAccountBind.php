<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoWebsiteAccount;

/**
 * 站点绑定SEO账户事件监听器
 *
 * 监听 Weline_Seo::domain::website_account_bind 事件，
 * 自动保存站点与SEO账户的关联关系。
 */
class WebsiteAccountBind implements ObserverInterface
{
    private SeoWebsiteAccount $seoWebsiteAccountModel;

    public function __construct(SeoWebsiteAccount $seoWebsiteAccountModel)
    {
        $this->seoWebsiteAccountModel = $seoWebsiteAccountModel;
    }

    /**
     * 执行事件处理
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $websiteId = (int)$event->getData('website_id');
        $accountId = (int)$event->getData('account_id');
        $isAutoSubmit = $event->getData('is_auto_submit') ?? true;

        if ($websiteId <= 0 || $accountId <= 0) {
            // 无效参数，跳过处理
            return;
        }

        try {
            // 使用 Model 方法绑定站点与账户
            $this->seoWebsiteAccountModel->bindWebsiteAccount(
                $websiteId,
                $accountId,
                ['is_auto_submit' => (bool)$isAutoSubmit]
            );
        } catch (\Exception $e) {
            // 记录错误日志，但不中断流程
            error_log(sprintf(
                '[Weline_Seo] WebsiteAccountBind error: website_id=%d, account_id=%d, error=%s',
                $websiteId,
                $accountId,
                $e->getMessage()
            ));
        }
    }
}
