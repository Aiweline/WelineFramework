<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Model\Website;

/**
 * Early AI-site Website shell: allocates a durable website_code (+ website_id)
 * at requirements confirmation, before domain materialization.
 */
final class AiSiteWebsiteShellService
{
    public const SCOPE = 'pagebuilder_ai_site';
    public const CODE_PREFIX = 'aisite_';

    public function __construct(
        private readonly Website $website,
        private readonly DefaultWebsiteService $defaultWebsiteService,
    ) {
    }

    /**
     * @param array{
     *   public_id?:string,
     *   website_code?:string,
     *   website_id?:int|null,
     *   site_name?:string,
     *   language?:string,
     *   currency?:string,
     *   timezone?:string
     * } $context
     * @return array{website_code:string,website_id:int,created:bool}
     */
    public function ensureShell(array $context): array
    {
        $existingId = isset($context['website_id'])
            ? \filter_var($context['website_id'], \FILTER_VALIDATE_INT)
            : false;
        if ($existingId !== false && $existingId > Website::ID_DEFAULT) {
            $loaded = $this->loadById($existingId);
            if ($loaded !== null) {
                $code = \trim((string)$loaded->getCode());
                if ($code !== '' && $code !== Website::CODE_DEFAULT) {
                    return [
                        'website_code' => $code,
                        'website_id' => $existingId,
                        'created' => false,
                    ];
                }
            }
        }

        $code = $this->normalizeCode((string)($context['website_code'] ?? ''));
        if ($code === '') {
            $code = $this->generateCode((string)($context['public_id'] ?? ''));
        }
        $byCode = $this->loadByCode($code);
        if ($byCode !== null) {
            return [
                'website_code' => $code,
                'website_id' => $byCode->getWebsiteId(),
                'created' => false,
            ];
        }

        $defaults = $this->defaultWebsiteService->ensureDefaultWebsite(false);
        $siteName = \trim((string)($context['site_name'] ?? ''));
        $name = $this->uniqueName($siteName !== '' ? $siteName : 'AI Site', $code);
        $language = \trim((string)($context['language'] ?? ''))
            ?: (\trim((string)($defaults[Website::schema_fields_DEFAULT_LANGUAGE] ?? '')) ?: 'zh_Hans_CN');
        $currency = \trim((string)($context['currency'] ?? ''))
            ?: (\trim((string)($defaults[Website::schema_fields_DEFAULT_CURRENCY] ?? '')) ?: 'CNY');
        $timezone = \trim((string)($context['timezone'] ?? ''))
            ?: (\trim((string)($defaults[Website::schema_fields_DEFAULT_TIMEZONE] ?? '')) ?: 'Asia/Shanghai');

        $website = clone $this->website;
        $website->clearData()->clearQuery();
        $website->setName($name)
            ->setCode($code)
            ->setUrl($this->pendingUrl($code))
            ->setDefaultCurrency($currency)
            ->setDefaultLanguage($language)
            ->setDefaultTimezone($timezone)
            ->setScope(self::SCOPE);

        try {
            $website->save(true);
        } catch (\Throwable $throwable) {
            $race = $this->loadByCode($code);
            if ($race !== null) {
                return [
                    'website_code' => $code,
                    'website_id' => $race->getWebsiteId(),
                    'created' => false,
                ];
            }
            throw $throwable;
        }

        $websiteId = $website->getWebsiteId();
        if ($websiteId <= Website::ID_DEFAULT) {
            throw new \RuntimeException('AI site shell website save did not return a website_id.');
        }

        return [
            'website_code' => $code,
            'website_id' => $websiteId,
            'created' => true,
        ];
    }

    public function generateCode(string $publicId): string
    {
        $publicId = \strtolower(\preg_replace('/[^a-z0-9]+/i', '', $publicId) ?? '');
        $prefix = $publicId !== '' ? \substr($publicId, 0, 10) : \substr(\bin2hex(\random_bytes(5)), 0, 10);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $suffix = \substr(\bin2hex(\random_bytes(3)), 0, 6);
            $code = self::CODE_PREFIX . $prefix . '_' . $suffix;
            if ($this->loadByCode($code) === null) {
                return $code;
            }
        }

        return self::CODE_PREFIX . $prefix . '_' . \substr(\bin2hex(\random_bytes(8)), 0, 12);
    }

    public function normalizeCode(string $code): string
    {
        $code = \strtolower(\trim($code));
        if ($code === '' || $code === Website::CODE_DEFAULT) {
            return '';
        }
        if (\preg_match('/^[a-z0-9][a-z0-9_.-]{0,120}$/D', $code) !== 1) {
            return '';
        }

        return $code;
    }

    public function pendingUrl(string $code): string
    {
        $safe = $this->normalizeCode($code);
        if ($safe === '') {
            $safe = 'pending_' . \substr(\bin2hex(\random_bytes(4)), 0, 8);
        }

        return 'https://' . $safe . '.aisite-pending.weline.internal';
    }

    private function uniqueName(string $base, string $code): string
    {
        $base = \trim($base);
        if ($base === '') {
            $base = 'AI Site';
        }
        $candidate = \mb_substr($base, 0, 100, 'UTF-8') . ' [' . $code . ']';
        if (\mb_strlen($candidate, 'UTF-8') > 128) {
            $candidate = \mb_substr($candidate, 0, 128, 'UTF-8');
        }

        return $candidate;
    }

    private function loadByCode(string $code): ?Website
    {
        $code = $this->normalizeCode($code);
        if ($code === '') {
            return null;
        }
        $website = clone $this->website;
        $website->clearData()->clearQuery()
            ->where(Website::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        return $website->getWebsiteId() > Website::ID_DEFAULT ? $website : null;
    }

    private function loadById(int $websiteId): ?Website
    {
        if ($websiteId <= Website::ID_DEFAULT) {
            return null;
        }
        $website = clone $this->website;
        $website->clearData()->clearQuery()->load($websiteId);

        return $website->getWebsiteId() === $websiteId ? $website : null;
    }
}
