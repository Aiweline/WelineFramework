<?php

declare(strict_types=1);

namespace Weline\Help\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Help\Api\Uri\HelpNamespace;
use Weline\Help\Service\HelpHubContent;
use Weline\Help\Service\HelpSeoFactsBuilder;
use Weline\Seo\Interface\SeoProfileProviderInterface;

final class HelpSeoProfileProvider implements SeoProfileProviderInterface
{
    public function __construct(
        private readonly HelpSeoFactsBuilder $factsBuilder = new HelpSeoFactsBuilder(),
        private readonly HelpHubContent $hub = new HelpHubContent(),
    ) {
    }

    public function provideSeoProfile($template, array $context): array
    {
        $slot = strtolower(trim((string)($context['_slot'] ?? 'head')));
        if ($slot !== '' && $slot !== 'head') {
            return [];
        }

        $pageType = strtolower(str_replace(['-', ' '], '_', trim((string)($context['page_type'] ?? ''))));
        $url = trim((string)($context['canonical_url'] ?? $context['url'] ?? ''));
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        $path = trim(strtolower($path), '/');

        if (in_array($pageType, ['faq', 'help', 'help_hub'], true)
            || HelpNamespace::isHelpIdentifier($path)
            || $path === HelpNamespace::FAQ_ALIAS
        ) {
            if ($pageType === 'help_article' || preg_match('#^help/[a-z0-9-]+$#', $path) === 1) {
                return [];
            }
            $canonical = $url !== '' ? $url : '/help';
            $profile = $this->factsBuilder->buildHubProfile($canonical);
            if (empty($context['faqs'])) {
                $profile['faqs'] = $this->hub->seoFaqs();
            }

            $existingImage = trim((string)($context['image'] ?? ''));
            if ($existingImage !== '') {
                $profile['image'] = $existingImage;
                $existingAlt = trim((string)($context['image_alt'] ?? ''));
                if ($existingAlt !== '') {
                    $profile['image_alt'] = $existingAlt;
                }
            } else {
                $logo = trim((string)(($context['organization'] ?? [])['logo'] ?? ''));
                if ($logo !== '') {
                    $profile['image'] = $logo;
                    $siteName = trim((string)($context['site_name'] ?? ''));
                    if ($siteName !== '') {
                        $profile['image_alt'] = $siteName . ' — ' . (string)__('帮助中心');
                    }
                }
            }

            return $profile;
        }

        return [];
    }
}
