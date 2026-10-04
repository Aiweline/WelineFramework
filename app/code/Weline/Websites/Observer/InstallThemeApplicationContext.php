<?php
declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\ThemeApplicationContextProducer;

/** 仅处理网站正式请求；后台界面与资产/编辑输入分别由其使用方安装。 */
final class InstallThemeApplicationContext implements ObserverInterface
{
    public function __construct(
        private readonly ThemeApplicationContextProducer $producer,
        private readonly Website $websites,
    ) {
    }

    public function execute(Event &$event): void
    {
        $identity = $event->getData('scope_identity');
        if ($event->getData('area') !== 'frontend' || !$identity instanceof ScopeIdentity
            || $identity->websiteId === null || ThemeApplicationContext::current('frontend') !== null) {
            return;
        }
        $website = clone $this->websites;
        $website = $website->load($identity->websiteId, null, true);
        if (!$website->hasData(Website::schema_fields_ID)
            || (int)$website->getId() !== $identity->websiteId) {
            throw new \RuntimeException('website_theme_application_scope_missing');
        }
        $locale = (string)$website->getDefaultLanguage();
        if ($locale === '') {
            throw new \RuntimeException('website_theme_application_default_locale_missing');
        }
        $this->producer->build(
            identity: $identity,
            storeMode: $identity->storeMode ?? ScopeIdentity::MODE_NORMAL,
            defaultLocale: $locale,
            displayName: (string)$website->getName(),
            purpose: 'runtime',
        )->install();
    }
}
