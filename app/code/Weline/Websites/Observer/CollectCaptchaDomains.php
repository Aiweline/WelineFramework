<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

/**
 * 向 Captcha 提供站点域名。Captcha 不需要反向依赖 Websites。
 */
final class CollectCaptchaDomains implements ObserverInterface
{
    public function __construct(
        private readonly Website $websites,
        private readonly WebsiteDomain $websiteDomains,
    ) {
    }

    public function execute(Event &$event): void
    {
        $domains = \is_array($event->getData('domains')) ? $event->getData('domains') : [];

        $websiteRows = (clone $this->websites)->clearData()->clearQuery()
            ->select()
            ->fetchArray();
        foreach ((array)$websiteRows as $row) {
            $host = \parse_url((string)($row[Website::schema_fields_URL] ?? ''), PHP_URL_HOST);
            if (\is_string($host) && $host !== '') {
                $domains[] = \strtolower($host);
            }
        }

        $domainRows = (clone $this->websiteDomains)->clearData()->clearQuery()
            ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
        foreach ((array)$domainRows as $row) {
            $host = \strtolower(\trim((string)($row[WebsiteDomain::schema_fields_DOMAIN] ?? '')));
            if ($host !== '') {
                $domains[] = $host;
            }
        }

        $domains = \array_values(\array_unique(\array_filter(\array_map(
            static fn(mixed $domain): string => \strtolower(\trim((string)$domain)),
            $domains,
        ))));
        $event->setData('domains', $domains);
    }
}
