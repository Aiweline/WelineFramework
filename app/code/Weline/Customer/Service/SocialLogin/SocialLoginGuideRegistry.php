<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

use Weline\Customer\Interface\SocialLoginProviderInterface;
use Weline\Framework\Http\Url;

/**
 * Build published social-login guide/policy entries for storefront navigation.
 */
final class SocialLoginGuideRegistry
{
    public function __construct(
        private readonly SocialLoginProviderCatalog $catalog,
        private readonly Url $url,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEntries(): array
    {
        $entries = [];
        foreach ($this->catalog->providers() as $provider) {
            $entry = $this->buildEntry($provider);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEntry(string $code): ?array
    {
        $provider = $this->catalog->get($code);
        if ($provider === null) {
            return null;
        }

        return $this->buildEntry($provider);
    }

    public function guideRoute(string $code): string
    {
        return 'guide/social-login/' . strtolower(trim($code));
    }

    public function policyRoute(string $code): string
    {
        return 'guide/social-login/' . strtolower(trim($code)) . '/policy';
    }

    public function hubRoute(): string
    {
        return 'guide/social-login';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildEntry(SocialLoginProviderInterface $provider): ?array
    {
        $code = strtolower(trim($provider->getCode()));
        if ($code === '' || !preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $code)) {
            return null;
        }

        $sourceModule = $this->catalog->sourceModule($code);
        $guideTemplateCode = $this->normalizeTemplateCode($provider->getGuideTemplateCode(), 'guide');
        $policyTemplateCode = $this->normalizeTemplateCode($provider->getPolicyTemplateCode(), 'policy');
        $guideRoute = $this->guideRoute($code);
        $policyRoute = $this->policyRoute($code);

        return [
            'code' => $code,
            'title' => $provider->getLabel(),
            'summary' => $provider->getSummary(),
            'guide_title' => $provider->getGuideTitle(),
            'policy_title' => $provider->getPolicyTitle(),
            'guide_layout_type' => $this->normalizeLayoutType($provider->getGuideLayoutType(), 'payment_guide'),
            'policy_layout_type' => $this->normalizeLayoutType($provider->getPolicyLayoutType(), 'payment_guide'),
            'guide_template' => $sourceModule . '::templates/Frontend/guide/social-login/' . $code . '/' . $guideTemplateCode . '.phtml',
            'policy_template' => $sourceModule . '::templates/Frontend/guide/social-login/' . $code . '/' . $policyTemplateCode . '.phtml',
            'guide_route' => $guideRoute,
            'policy_route' => $policyRoute,
            'guide_url' => $this->url->getUrl($guideRoute),
            'policy_url' => $this->url->getUrl($policyRoute),
            'hub_route' => $this->hubRoute(),
            'icon_svg' => $provider->getIconSvgMarkup(),
            'brand_class' => $provider->getBrandClass(),
            'sort_order' => $provider->getSortOrder(),
            'source_module' => $sourceModule,
        ];
    }

    private function normalizeTemplateCode(string $templateCode, string $fallback): string
    {
        $templateCode = strtolower(trim($templateCode));
        if ($templateCode === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $templateCode)) {
            return $fallback;
        }

        return $templateCode;
    }

    private function normalizeLayoutType(string $layoutType, string $fallback): string
    {
        $layoutType = strtolower(trim($layoutType));
        if ($layoutType === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $layoutType)) {
            return $fallback;
        }

        return $layoutType;
    }
}
