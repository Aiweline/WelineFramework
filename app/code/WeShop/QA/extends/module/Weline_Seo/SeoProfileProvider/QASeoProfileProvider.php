<?php

declare(strict_types=1);

namespace WeShop\QA\Extends\Module\Weline_Seo\SeoProfileProvider;

use Weline\Seo\Interface\SeoProfileProviderInterface;

class QASeoProfileProvider implements SeoProfileProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        $questions = $this->questions($this->readTemplate($template, 'qa_list') ?? $context['qa_list'] ?? []);
        if ($questions === []) {
            return [];
        }

        $url = (string)($context['canonical_url'] ?? $context['url'] ?? '');
        $profile = [
            'page_type' => 'qa_page',
            'schema_nodes' => [[
                '@type' => 'QAPage',
                '@id' => $url !== '' ? $url . '#qa' : '#qa',
                'mainEntity' => $questions,
            ]],
        ];

        $faqs = [];
        foreach ($questions as $question) {
            $answer = $question['acceptedAnswer']['text'] ?? '';
            if ((string)$answer !== '') {
                $faqs[] = [
                    'question' => (string)$question['name'],
                    'answer' => (string)$answer,
                ];
            }
        }
        if ($faqs !== []) {
            $profile['faqs'] = $faqs;
        }

        return $profile;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function questions(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $questions = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = trim((string)($item['question'] ?? $item['title'] ?? ''));
            if ($question === '') {
                continue;
            }
            $node = [
                '@type' => 'Question',
                'name' => $question,
            ];
            $answer = trim((string)($item['answer'] ?? ''));
            if ($answer !== '') {
                $node['acceptedAnswer'] = [
                    '@type' => 'Answer',
                    'text' => $answer,
                ];
            }
            $questions[] = $node;
        }

        return $questions;
    }

    private function readTemplate($template, string $key): mixed
    {
        return is_object($template) && method_exists($template, 'getData') ? $template->getData($key) : null;
    }
}
