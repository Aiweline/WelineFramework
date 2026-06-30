<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Faq;

/**
 * FAQ 结构化事实的唯一归一化入口。
 */
class FaqStructureNormalizer extends AbstractFaqStructureNormalizer
{
    /**
     * @return array<int, array{question:string,answer:string}>
     */
    public function normalize(mixed $faqs): array
    {
        return $this->dedupe($this->normalizeList($faqs));
    }

    /**
     * @param array<int, array{question:string,answer:string}> ...$lists
     * @return array<int, array{question:string,answer:string}>
     */
    public function mergeAndNormalize(array ...$lists): array
    {
        $merged = [];
        foreach ($lists as $list) {
            foreach ($this->normalizeList($list) as $faq) {
                $merged[] = $faq;
            }
        }

        return $this->dedupe($merged);
    }

    /**
     * @return array<int, array{question:string,answer:string}>
     */
    private function normalizeList(mixed $faqs): array
    {
        $normalized = [];
        foreach ($this->filterList($faqs) as $faq) {
            if (!is_array($faq)) {
                continue;
            }
            $question = $this->firstString($faq, ['question', 'q', 'title', 'name']);
            $answer = $this->firstString($faq, ['answer', 'a', 'text', 'content', 'acceptedAnswer']);
            if ($question !== '' && $answer !== '') {
                $normalized[] = [
                    'question' => $question,
                    'answer' => $answer,
                ];
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, array{question:string,answer:string}> $faqs
     * @return array<int, array{question:string,answer:string}>
     */
    private function dedupe(array $faqs): array
    {
        $seen = [];
        $result = [];
        foreach ($faqs as $faq) {
            $key = mb_strtolower($faq['question']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $faq;
        }

        return $result;
    }
}
