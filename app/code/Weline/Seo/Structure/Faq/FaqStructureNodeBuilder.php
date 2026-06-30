<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Faq;

class FaqStructureNodeBuilder extends AbstractFaqStructureNodeBuilder
{
    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    protected function buildFactNodes(array $context, string $url): array
    {
        $faqs = (new FaqStructureNormalizer())->normalize($context['faqs'] ?? []);
        if ($faqs === []) {
            return [];
        }

        return [$this->buildFaqPageNode($faqs, $url)];
    }

    /**
     * @param array<int, array{question:string,answer:string}> $faqs
     * @return array<string, mixed>
     */
    public function buildFaqPageNode(array $faqs, string $url): array
    {
        return [
            '@type' => 'FAQPage',
            '@id' => $this->nodeId($url, 'faq'),
            'mainEntity' => array_map(static fn (array $faq): array => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ],
            ], $faqs),
        ];
    }
}
