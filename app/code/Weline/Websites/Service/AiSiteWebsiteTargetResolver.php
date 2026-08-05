<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Exception\AiSiteProvisioningException;
use Weline\Websites\Model\AiSiteProvisioningRequest;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

/**
 * Resolves the durable Website that owns one AI-site domain.
 *
 * Existing bindings retain their current semantics, including the valid
 * system default website_id=0. A new PageBuilder domain receives a
 * deterministic positive Website ID so sessions cannot overwrite each other.
 */
class AiSiteWebsiteTargetResolver
{
    private const PAGEBUILDER_SOURCE_MODULE = 'GuoLaiRen_PageBuilder';
    private const PAGEBUILDER_SCOPE = 'pagebuilder_ai_site';

    public function __construct(
        private readonly Website $website,
        private readonly WebsiteDomain $websiteDomain,
        private readonly DefaultWebsiteService $defaultWebsiteService,
    ) {
    }

    public function resolve(AiSiteProvisioningRequest $request, string $domain): int
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            throw new AiSiteProvisioningException(
                'TARGET_DOMAIN_INVALID',
                (string)__('目标域名格式无效。')
            );
        }

        $requestedWebsiteId = $request->getRequestedWebsiteId();
        if ($requestedWebsiteId !== null && $requestedWebsiteId < Website::ID_DEFAULT) {
            throw new AiSiteProvisioningException(
                'WEBSITE_NOT_FOUND',
                (string)__('请求绑定的网站不存在。')
            );
        }

        $sourceModule = \trim((string)$request->getData(
            AiSiteProvisioningRequest::schema_fields_SOURCE_MODULE
        ));
        $boundWebsiteId = $this->boundWebsiteId($domain);
        if ($boundWebsiteId !== null
            && ($boundWebsiteId > Website::ID_DEFAULT
                || $sourceModule !== self::PAGEBUILDER_SOURCE_MODULE)
        ) {
            return $boundWebsiteId > Website::ID_DEFAULT
                ? $this->assertWebsiteExists($boundWebsiteId)
                : Website::ID_DEFAULT;
        }
        if ($requestedWebsiteId !== null
            && ($sourceModule !== self::PAGEBUILDER_SOURCE_MODULE
                || $requestedWebsiteId > Website::ID_DEFAULT)
        ) {
            if ($requestedWebsiteId === Website::ID_DEFAULT) {
                $this->defaultWebsiteService->ensureDefaultWebsite(false);

                return Website::ID_DEFAULT;
            }

            return $this->assertWebsiteExists($requestedWebsiteId);
        }

        $url = 'https://' . $domain;
        if (\strlen($url) > 128) {
            throw new AiSiteProvisioningException(
                'TARGET_DOMAIN_INVALID',
                (string)__('目标域名格式无效。')
            );
        }

        $hash = \hash('sha256', \trim((string)$request->getData(
            AiSiteProvisioningRequest::schema_fields_SOURCE_PUBLIC_ID
        )) . '|' . $domain);
        $code = 'pagebuilder_ai_' . \substr($hash, 0, 20);

        $existingWebsiteId = $this->reusableWebsiteId($domain, $code);
        if ($existingWebsiteId !== null) {
            return $existingWebsiteId;
        }

        $defaults = $this->defaultWebsiteService->ensureDefaultWebsite(false);
        $website = clone $this->website;
        $website->clearData()->clearQuery();
        $website->setName(
            'PageBuilder AI ' . \substr($domain, 0, 80) . ' ' . \strtoupper(\substr($hash, 0, 6))
        )
            ->setCode($code)
            ->setUrl($url)
            ->setDefaultCurrency(
                \trim((string)($defaults[Website::schema_fields_DEFAULT_CURRENCY] ?? '')) ?: 'CNY'
            )
            ->setDefaultLanguage(
                \trim((string)($defaults[Website::schema_fields_DEFAULT_LANGUAGE] ?? '')) ?: 'zh_Hans_CN'
            )
            ->setDefaultTimezone(
                \trim((string)($defaults[Website::schema_fields_DEFAULT_TIMEZONE] ?? '')) ?: 'Asia/Shanghai'
            )
            ->setScope(self::PAGEBUILDER_SCOPE);

        try {
            $website->save(true);
        } catch (\Throwable $throwable) {
            $existingWebsiteId = $this->reusableWebsiteId($domain, $code);
            if ($existingWebsiteId !== null) {
                return $existingWebsiteId;
            }

            throw $throwable;
        }

        $websiteId = $website->getWebsiteId();
        if ($websiteId <= Website::ID_DEFAULT) {
            throw new AiSiteProvisioningException(
                'WEBSITE_CREATE_FAILED',
                (string)__('网站保存失败，未能获取网站ID')
            );
        }

        return $websiteId;
    }

    private function boundWebsiteId(string $domain): ?int
    {
        $binding = clone $this->websiteDomain;
        $binding->clearData()->clearQuery()->loadByDomainAndSubPath($domain, '');
        if ($binding->getDomainId() <= 0) {
            return null;
        }

        $websiteId = $binding->getWebsiteId();
        if ($websiteId < Website::ID_DEFAULT) {
            throw new AiSiteProvisioningException(
                'WEBSITE_NOT_FOUND',
                (string)__('请求绑定的网站不存在。')
            );
        }

        return $websiteId;
    }

    private function assertWebsiteExists(int $websiteId): int
    {
        $website = clone $this->website;
        $website->clearData()->clearQuery()->load($websiteId);
        if ($website->getWebsiteId() !== $websiteId) {
            throw new AiSiteProvisioningException(
                'WEBSITE_NOT_FOUND',
                (string)__('请求绑定的网站不存在。')
            );
        }

        return $websiteId;
    }

    private function reusableWebsiteId(string $domain, string $code): ?int
    {
        foreach (['https://' . $domain, 'http://' . $domain] as $url) {
            $website = clone $this->website;
            $website->clearData()->clearQuery()
                ->where(Website::schema_fields_URL, $url)
                ->find()
                ->fetch();
            if ($website->getWebsiteId() > Website::ID_DEFAULT) {
                return $website->getWebsiteId();
            }
        }

        $website = clone $this->website;
        $website->clearData()->clearQuery()
            ->where(Website::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        return $website->getWebsiteId() > Website::ID_DEFAULT
            ? $website->getWebsiteId()
            : null;
    }
}
