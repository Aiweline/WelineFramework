<?php

declare(strict_types=1);

namespace Weline\Product\Service\Ai;

use Weline\Product\Model\Ai\AiDraft;

/**
 * Prompt contracts for Product AI optimization.
 *
 * The model may only rephrase facts supplied here; quantity, material,
 * certification and dimensions in the output must come from the snapshot.
 * Description output is plain text and is turned into escaped paragraphs, so a
 * prompt-injected model response can never write raw HTML onto the storefront.
 */
final class ProductAiPromptBuilder
{
    private const MAX_FIELD_BYTES = 2000;
    private const MAX_DESCRIPTION_BYTES = 20000;
    private const MAX_PARAGRAPHS = 64;
    private const FIELD_HINTS = [
        'name' => '商品标题，突出品类与核心卖点，不要堆砌关键词',
        'short_description' => '一句话卖点摘要，80 字以内',
        'description' => '商品详情正文，分段纯文本，不要输出 HTML 标签',
        'meta_name' => 'SEO 标题标签',
        'meta_description' => 'SEO 描述，160 字以内',
        'meta_keywords' => '英文逗号分隔的关键词，不要重复',
    ];

    /** @param array<string,mixed> $snapshot */
    public function copyPrompt(array $snapshot, string $scope = ''): string
    {
        $fields = \is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
        $facts = $this->factLines($snapshot, $fields);
        $schema = [];
        foreach (AiDraft::COPY_FIELDS as $code) {
            $schema[$code] = '<' . self::FIELD_HINTS[$code] . '>';
        }
        $locale = (string)($snapshot['locale'] ?? '');
        $scopeLine = match (\strtolower(\trim($scope))) {
            'missing', 'empty' => '当前缺失或过短，重点补全信息。',
            'weak' => '当前内容单薄，重点是信息密度与说服力。',
            'seo' => '当前 SEO 元信息薄弱，重点补全标题、描述与关键词。',
            default => '在保持事实不变的前提下提升清晰度、信息密度与转化表达。',
        };

        return \implode("\n", [
            '你是资深电商商品文案编辑。请基于下列事实重写商品文案。',
            '硬性约束：',
            '1. 只能使用「已知事实」中的信息；缺失的参数保持缺失，禁止编造材质、产地、认证、尺寸、功效或售后承诺。',
            '2. 不得添加价格、折扣、库存、销量、评价等未提供的数据。',
            '3. 输出必须是单个 JSON 对象，键只能是 ' . \implode('、', AiDraft::COPY_FIELDS) . '，不要输出 Markdown 代码块或解释。',
            '4. 每个值都是纯文本，禁止 HTML 标签、脚本和外部链接。',
            '5. 语言：' . ($locale !== '' ? $locale : '与现有内容一致') . '；不要混用其他语言。',
            '6. 改写要求：' . $scopeLine,
            '',
            '已知事实：',
            ...$facts,
            '',
            '输出 JSON 模板：',
            \json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<string,mixed> $snapshot */
    public function imagePrompt(array $snapshot, int $candidates = 3): string
    {
        $fields = \is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
        $name = (string)($fields['name'] ?? '');
        $categories = \is_array($snapshot['category_names'] ?? null) ? $snapshot['category_names'] : [];
        $sku = (string)($snapshot['sku'] ?? '');

        return \implode("\n", [
            'Generate a premium e-commerce main product image.',
            'Subject: ' . ($name !== '' ? $name : 'the product described by the supplied facts') . '.',
            $sku !== '' ? 'SKU reference: ' . $sku . '.' : '',
            $categories !== [] ? 'Category: ' . \implode(' > ', $categories) . '.' : '',
            'Composition:',
            '- Pure white seamless background, soft studio lighting, no props.',
            '- Product occupies at least 80% of the frame, centered, fully visible, sharp focus.',
            '- 4:5 vertical aspect ratio, catalogue-grade color accuracy.',
            '- Keep every existing pattern, texture, stitch and print detail of the product.',
            '- No text, no watermark, no logo overlay, no border, no collage, no human model unless the product is wearable.',
            'Output must be a single photographic image. Candidate ' . \max(1, $candidates) . '.',
        ]);
    }

    /**
     * Parses the model response into whitelisted, length-bounded field values.
     *
     * @return array<string,string>
     */
    public function parseCopyResponse(string $raw): array
    {
        $json = $this->extractJsonObject($raw);
        if ($json === null) {
            throw new ProductAiException(
                'product_ai_invalid_response',
                (string)__('AI 返回内容不是有效的 JSON'),
                502,
            );
        }

        $fields = [];
        foreach (AiDraft::COPY_FIELDS as $code) {
            if (!\array_key_exists($code, $json) || !\is_scalar($json[$code])) {
                continue;
            }
            $value = $this->normalizeCopyValue($code, (string)$json[$code]);
            if ($value === '') {
                continue;
            }
            $fields[$code] = $value;
        }

        return $fields;
    }

    public function normalizeCopyValue(string $code, string $value): string
    {
        $text = \html_entity_decode(\strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = \str_replace("\r\n", "\n", \str_replace("\r", "\n", $text));
        if ($code === 'description') {
            return $this->paragraphHtml($text);
        }
        $text = \preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return \mb_strcut(\trim($text), 0, self::MAX_FIELD_BYTES, 'UTF-8');
    }

    /** @param array<string,mixed> $snapshot @param array<string,string> $fields @return list<string> */
    private function factLines(array $snapshot, array $fields): array
    {
        $facts = ['- 站点内商品 ID：' . (string)($snapshot['product_id'] ?? '')];
        if ((string)($snapshot['sku'] ?? '') !== '') {
            $facts[] = '- SKU：' . (string)$snapshot['sku'];
        }
        if ((string)($snapshot['product_type'] ?? '') !== '') {
            $facts[] = '- 商品类型：' . (string)$snapshot['product_type'];
        }
        $categories = \is_array($snapshot['category_names'] ?? null) ? $snapshot['category_names'] : [];
        if ($categories !== []) {
            $facts[] = '- 所属分类：' . \implode(' > ', $categories);
        }
        foreach (AiDraft::COPY_FIELDS as $code) {
            $value = \trim((string)($fields[$code] ?? ''));
            if ($value === '') {
                $facts[] = '- ' . $code . '：（当前为空）';
                continue;
            }
            $plain = $code === 'description'
                ? \trim(\preg_replace('/\s+/u', ' ', \html_entity_decode(\strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '')
                : $value;
            $facts[] = '- ' . $code . '：' . \mb_strcut($plain, 0, 4000, 'UTF-8');
        }

        return $facts;
    }

    private function paragraphHtml(string $text): string
    {
        $lines = [];
        foreach (\preg_split('/\n\s*\n/u', \trim($text)) ?: [] as $paragraph) {
            $paragraph = \trim(\preg_replace('/\s*\n\s*/u', ' ', $paragraph) ?? '');
            if ($paragraph === '') {
                continue;
            }
            $lines[] = $paragraph;
            if (\count($lines) >= self::MAX_PARAGRAPHS) {
                break;
            }
        }
        if ($lines === []) {
            return '';
        }
        $budget = self::MAX_DESCRIPTION_BYTES - 7 * \count($lines) - 8;
        $html = '';
        foreach ($lines as $paragraph) {
            $paragraph = \mb_strcut($paragraph, 0, \max(1, $budget), 'UTF-8');
            $html .= '<p>' . \htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            $budget -= \strlen($paragraph);
            if ($budget <= 0) {
                break;
            }
        }

        return $html;
    }

    /** @return array<string,mixed>|null */
    private function extractJsonObject(string $raw): ?array
    {
        $raw = \trim($raw);
        if (\preg_match('/^```(?:json)?\s*(.+?)\s*```$/s', $raw, $matches) === 1) {
            $raw = \trim($matches[1]);
        }
        $start = \strpos($raw, '{');
        $end = \strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        try {
            $decoded = \json_decode(\substr($raw, $start, $end - $start + 1), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
