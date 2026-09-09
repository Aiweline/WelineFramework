<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Framework\View\Template;
use Weline\Seo\Interface\SeoProfileProviderInterface;
use Weline\Theme\Helper\SiteBrand;

/**
 * Fill Organization.logo from Theme SiteBrand when SEO context lacks a merchant logo.
 *
 * HeadRenderer owns JSON-LD; this provider only supplies the absolute-capable URL path.
 */
final class OrganizationLogoSeoProfileProvider implements SeoProfileProviderInterface
{
    public function __construct(
        private readonly SiteBrand $siteBrand,
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        $slot = strtolower(trim((string)($context['_slot'] ?? 'head')));
        if ($slot !== '' && $slot !== 'head') {
            return [];
        }

        $organization = is_array($context['organization'] ?? null) ? $context['organization'] : [];
        $existing = trim((string)($organization['logo'] ?? ''));
        if ($existing !== '') {
            return [];
        }

        $tpl = $template instanceof Template ? $template : null;
        if ($tpl === null) {
            return [];
        }

        try {
            $logo = trim($this->siteBrand->resolveFrontendLogoUrl($tpl));
        } catch (\Throwable) {
            return [];
        }
        if ($logo === '') {
            return [];
        }

        return [
            'organization' => [
                'logo' => $logo,
            ],
        ];
    }
}
