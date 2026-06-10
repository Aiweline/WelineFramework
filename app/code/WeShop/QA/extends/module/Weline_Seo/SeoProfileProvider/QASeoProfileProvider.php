<?php

declare(strict_types=1);

namespace WeShop\QA\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Seo\Interface\SeoProfileProviderInterface;
use Weline\Seo\Structure\Faq\FaqStructureNormalizer;

class QASeoProfileProvider implements SeoProfileProviderInterface
{
    public function __construct(
        private readonly ?FaqStructureNormalizer $faqStructureNormalizer = null
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        $qaList = $this->normalizeQaList($this->readTemplate($template, 'qa_list') ?? $context['qa_list'] ?? []);
        if ($qaList === []) {
            return [];
        }

        $faqs = ($this->faqStructureNormalizer ?? new FaqStructureNormalizer())->normalize($qaList);
        $profile = [
            'page_type' => 'qa_page',
            'qa_list' => $qaList,
        ];
        if ($faqs !== []) {
            $profile['faqs'] = $faqs;
        }

        return $profile;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeQaList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $list = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    private function readTemplate($template, string $key): mixed
    {
        return is_object($template) && method_exists($template, 'getData') ? $template->getData($key) : null;
    }
}
