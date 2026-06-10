<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Qa;

class QaStructureNodeBuilder extends AbstractQaStructureNodeBuilder
{
    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    protected function buildFactNodes(array $context, string $url): array
    {
        $questions = $this->questions($context['qa_list'] ?? []);
        if ($questions === []) {
            return [];
        }

        return [[
            '@type' => 'QAPage',
            '@id' => $this->nodeId($url, 'qa'),
            'mainEntity' => $questions,
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function questions(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $questions = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? $item['title'] ?? ''));
            if ($question === '') {
                continue;
            }
            $node = [
                '@type' => 'Question',
                'name' => $question,
            ];
            $answer = trim((string) ($item['answer'] ?? ''));
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
}
