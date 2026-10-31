<?php
declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Websites\Data\WebsiteData;
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

        // Fast path: the Worker preload provider already built the context for
        // this scope. Loading the website row here — only to read its display
        // name and default locale — cost ~1.2 ms per request even when the build
        // was cached, because the row read ran before the cache lookup. Use the
        // site object the scope installer already published into process memory,
        // and only touch the database when that is unavailable.
        $storeMode = $identity->storeMode ?? ScopeIdentity::MODE_NORMAL;
        $published = WebsiteData::getWebsite();
        if ($published instanceof Website
            && (int)$published->getId() === (int)$identity->websiteId
        ) {
            $publishedLocale = \trim((string)$published->getDefaultLanguage());
            if ($publishedLocale !== '') {
                $cached = $this->producer->cachedRuntimeContext(
                    $identity,
                    $storeMode,
                    $publishedLocale,
                    (string)$published->getName(),
                );
                if ($cached !== null) {
                    $cached->install();

                    return;
                }
            }
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
            storeMode: $storeMode,
            defaultLocale: $locale,
            displayName: (string)$website->getName(),
            purpose: 'runtime',
        )->install();
    }
}
