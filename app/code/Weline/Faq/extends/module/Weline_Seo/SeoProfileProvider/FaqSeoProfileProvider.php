<?php

declare(strict_types=1);

namespace Weline\Faq\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Faq\Api\Uri\FaqNamespace;
use Weline\Faq\Service\FaqHubContent;
use Weline\Faq\Service\FaqSeoFactsBuilder;
use Weline\Seo\Interface\SeoProfileProviderInterface;

final class FaqSeoProfileProvider implements SeoProfileProviderInterface
{
    public function __construct(
        private readonly FaqSeoFactsBuilder $factsBuilder = new FaqSeoFactsBuilder(),
        private readonly FaqHubContent $hub = new FaqHubContent(),
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

        if (in_array($pageType, ['faq', 'faq_hub'], true) || FaqNamespace::isFaqIdentifier($path)) {
            if ($pageType === 'faq_article' || preg_match('#^faq/[a-z0-9-]+$#', $path) === 1) {
                return [];
            }
            $canonical = $url !== '' ? $url : '/faq';
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
                        $profile['image_alt'] = $siteName . ' — ' . (string)__('FAQ');
                    }
                }
            }

            return $profile;
        }

        return [];
    }
}
