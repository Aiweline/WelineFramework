<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\LocalDomainPolicy;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;
use Weline\Websites\Model\WebsiteLanguage;

class AiSiteDraftWebsiteService
{
    public function __construct(
        private readonly Website $websiteModel,
        private readonly WebsiteLanguage $websiteLanguage,
        private readonly ?WebsiteDomain $websiteDomainModel = null,
        private readonly ?DomainPool $domainPoolModel = null,
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $websiteProfile
     * @return array{website_id:int,created:bool,website:Website}
     */
    public function ensureDraftWebsite(array $scope, array $websiteProfile): array
    {
        $candidateIds = [
            (int)($scope['draft_website_id'] ?? 0),
            (int)($scope['website_id'] ?? 0),
            (int)($scope['selected_website_id'] ?? 0),
        ];

        foreach ($candidateIds as $candidateId) {
            if ($candidateId <= 0) {
                continue;
            }

            $existing = $this->loadById($candidateId);
            if ($existing !== null) {
                return $this->persistWebsite($existing, $websiteProfile, false);
            }
        }

        $targetDomain = $this->normalizeTargetDomain($websiteProfile['target_domain'] ?? $scope['target_domain'] ?? '');
        if ($targetDomain !== '') {
            $existing = $this->loadByDomainBinding($targetDomain);
            if ($existing !== null) {
                return $this->persistWebsite($existing, $websiteProfile, false);
            }

            $existing = $this->loadByUrl($targetDomain);
            if ($existing !== null) {
                return $this->persistWebsite($existing, $websiteProfile, false);
            }
        }

        $website = clone $this->websiteModel;
        $website->clearData()->clearQuery();

        return $this->persistWebsite($website, $websiteProfile, true);
    }

    /**
     * @param array<string, mixed> $websiteProfile
     * @return array{website_id:int,created:bool,website:Website}
     */
    private function persistWebsite(Website $website, array $websiteProfile, bool $created): array
    {
        $siteTitle = \trim((string)($websiteProfile['site_title'] ?? ''));
        $targetDomain = $this->normalizeTargetDomain($websiteProfile['target_domain'] ?? '');
        $defaultLocale = \trim((string)($websiteProfile['default_locale'] ?? 'en_US'));
        $locales = \is_array($websiteProfile['locales'] ?? null) ? $websiteProfile['locales'] : [];

        $websiteName = $siteTitle !== '' ? $siteTitle : ($targetDomain !== '' ? $targetDomain : 'PageBuilder AI Draft');
        $websiteCode = 'pagebuilder-ai-' . $this->slugify($siteTitle !== '' ? $siteTitle : $targetDomain) . '-' . \substr(\md5((string)\microtime(true)), 0, 8);
        $websiteUrl = $targetDomain !== ''
            ? $this->buildWebsiteUrl($targetDomain)
            : $this->resolveDraftWebsiteUrl($website, $websiteName, $websiteCode);
        $websiteName = $this->resolveDraftWebsiteName($website, $websiteName, $websiteCode);

        if (!$website->getWebsiteId()) {
            $website->setCode($websiteCode);
        }

        $website->setName($websiteName)
            ->setUrl($websiteUrl)
            ->setDefaultLanguage($defaultLocale !== '' ? $defaultLocale : 'en_US')
            ->setDefaultCurrency($website->getDefaultCurrency() ?: 'USD')
            ->setDefaultTimezone($website->getDefaultTimezone() !== '' ? $website->getDefaultTimezone() : 'UTC')
            ->setScope('page_builder')
            ->save();

        $websiteId = (int)$website->getWebsiteId();
        if ($websiteId <= 0) {
            throw new \RuntimeException((string)__('Failed to create PageBuilder draft website'));
        }

        if ($locales !== []) {
            $language = clone $this->websiteLanguage;
            $language->setWebsiteLanguages($websiteId, $locales);
        }
        $this->ensureWebsiteDomainBinding($websiteId, $targetDomain);

        return [
            'website_id' => $websiteId,
            'created' => $created,
            'website' => $website,
        ];
    }

    private function loadById(int $websiteId): ?Website
    {
        $website = clone $this->websiteModel;
        $website->clearData()->clearQuery()->load($websiteId);

        return $website->getWebsiteId() > 0 ? $website : null;
    }

    private function loadByDomainBinding(string $targetDomain): ?Website
    {
        $targetDomain = $this->normalizeTargetDomain($targetDomain);
        if ($targetDomain === '') {
            return null;
        }

        $domain = clone $this->websiteDomainModel();
        $domain->clearData()->clearQuery()->loadByDomain($targetDomain);
        $websiteId = (int)$domain->getWebsiteId();
        if ($domain->getDomainId() <= 0 || $websiteId <= 0) {
            return null;
        }

        return $this->loadById($websiteId);
    }

    private function loadByUrl(string $targetDomain): ?Website
    {
        $urls = \array_values(\array_unique([
            $this->buildWebsiteUrl($targetDomain),
            \preg_replace('/^http:\/\//', 'https://', $this->buildWebsiteUrl($targetDomain)) ?: '',
        ]));

        foreach ($urls as $url) {
            if ($url === '') {
                continue;
            }

            $website = clone $this->websiteModel;
            $website->clearData()->clearQuery()
                ->where(Website::schema_fields_URL, $url)
                ->find()
                ->fetch();

            if ($website->getWebsiteId() > 0) {
                return $website;
            }
        }

        return null;
    }

    private function ensureWebsiteDomainBinding(int $websiteId, string $targetDomain): void
    {
        $targetDomain = $this->normalizeTargetDomain($targetDomain);
        if ($websiteId <= 0 || $targetDomain === '') {
            return;
        }

        $domain = clone $this->websiteDomainModel();
        $domain->clearData()->clearQuery()->loadByDomain($targetDomain);
        if ($domain->getDomainId() > 0) {
            $changed = false;
            if ((int)$domain->getWebsiteId() !== $websiteId) {
                $domain->setWebsiteId($websiteId);
                $changed = true;
            }
            if ($domain->getStatus() !== WebsiteDomain::STATUS_ACTIVE) {
                $domain->setStatus(WebsiteDomain::STATUS_ACTIVE);
                $changed = true;
            }
            if (!$domain->isPrimary()) {
                $domain->setIsPrimary(true);
                $changed = true;
            }
            if ($changed) {
                $domain->save(true);
            }
            return;
        }

        $domain = clone $this->websiteDomainModel();
        $domain->clearData()->clearQuery();
        $domain->setWebsiteId($websiteId)
            ->setDomain($targetDomain)
            ->setSubPath('')
            ->setIsPrimary(true)
            ->setStatus(WebsiteDomain::STATUS_ACTIVE);

        $pool = clone $this->domainPoolModel();
        $pool->clearData()->clearQuery()->loadByDomain($targetDomain);
        if ((int)$pool->getPoolId() > 0) {
            $domain->setPoolId((int)$pool->getPoolId());
            $domain->syncFromPool();
        }

        $domain->save(true);
    }

    private function buildWebsiteUrl(string $targetDomain): string
    {
        $targetDomain = \trim($targetDomain);
        if ($targetDomain === '') {
            return 'http://pagebuilder-ai-draft.local.test';
        }

        if (\preg_match('/^https?:\/\//i', $targetDomain)) {
            return $targetDomain;
        }

        return 'http://' . $targetDomain;
    }

    private function normalizeTargetDomain(mixed $targetDomain): string
    {
        $targetDomain = \strtolower(\trim((string)$targetDomain));
        if ($targetDomain === '') {
            return '';
        }

        return $this->isReservedProjectHost($targetDomain) ? '' : $targetDomain;
    }

    private function isReservedProjectHost(string $targetDomain): bool
    {
        $host = LocalDomainPolicy::normalizeDomain($targetDomain);
        if (\str_starts_with($host, 'www.')) {
            $host = (string)\substr($host, 4);
        }

        return LocalDomainPolicy::isStandardProjectHost($host);
    }

    private function resolveDraftWebsiteUrl(Website $website, string $websiteName, string $websiteCode): string
    {
        $existingUrl = \trim((string)$website->getUrl());
        if ($existingUrl !== '') {
            return $existingUrl;
        }

        $slug = $this->slugify($websiteName !== '' ? $websiteName : $websiteCode);
        if ($slug === '') {
            $slug = 'draft';
        }

        return 'http://pagebuilder-ai-' . $slug . '-' . \substr(\md5($websiteCode), 0, 8) . '.local.test';
    }

    private function resolveDraftWebsiteName(Website $website, string $websiteName, string $websiteCode): string
    {
        $websiteName = \trim($websiteName);
        if ($websiteName === '') {
            $websiteName = 'PageBuilder AI Draft';
        }

        $existing = $this->loadByName($websiteName);
        if ($existing === null) {
            return $websiteName;
        }

        if ((int)$existing->getWebsiteId() === (int)$website->getWebsiteId()) {
            return $websiteName;
        }

        return $websiteName . ' ' . \strtoupper(\substr(\md5($websiteCode), 0, 6));
    }

    private function loadByName(string $websiteName): ?Website
    {
        $websiteName = \trim($websiteName);
        if ($websiteName === '') {
            return null;
        }

        $website = clone $this->websiteModel;
        $website->clearData()->clearQuery()
            ->where(Website::schema_fields_NAME, $websiteName)
            ->find()
            ->fetch();

        return $website->getWebsiteId() > 0 ? $website : null;
    }

    private function slugify(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = (string)\preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = \trim($value, '-');

        return $value !== '' ? $value : 'draft';
    }

    private function websiteDomainModel(): WebsiteDomain
    {
        return $this->websiteDomainModel ?? ObjectManager::getInstance(WebsiteDomain::class);
    }

    private function domainPoolModel(): DomainPool
    {
        return $this->domainPoolModel ?? ObjectManager::getInstance(DomainPool::class);
    }
}
