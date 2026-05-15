<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;
use Weline\Ai\Model\AiModel;

class DocumentTranslationAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'developer_document_translation';
    }

    public function getName(): string
    {
        return 'DeveloperWorkspace Document Translation';
    }

    public function getDescription(): string
    {
        return 'Translates DeveloperWorkspace Markdown/API docs while preserving format, code, URLs, identifiers, and protected tokens.';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getSupportedModelTypes(): array
    {
        return [AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT];
    }

    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $segments = $params['segments'] ?? [];
        $sourceLocale = (string)($params['source_locale'] ?? 'zh_Hans_CN');
        $targetLocale = (string)($params['target_locale'] ?? $params['locale'] ?? 'en_US');
        $protectedTokens = $params['protected_tokens'] ?? [];
        $batchIndex = max(1, (int)($params['batch_index'] ?? 1));
        $batchTotal = max(1, (int)($params['batch_total'] ?? 1));

        return implode("\n", [
            'You are the dedicated Weline DeveloperWorkspace document translation adapter.',
            'Translate Markdown/API documentation segments from ' . $sourceLocale . ' to ' . $targetLocale . '.',
            'Batch ' . $batchIndex . ' of ' . $batchTotal . ': translate only the segments included in this request.',
            'Your entire answer is consumed by a JSON parser. Any non-JSON character will fail the task.',
            '',
            'Output contract, all rules are mandatory:',
            '1. Return exactly one JSON object and nothing else.',
            '2. The JSON schema is exactly: {"segments":[{"id":"same input id","text":"translated text"}]}.',
            '3. Do not use markdown fences, XML tags, comments, explanations, apologies, summaries, or extra keys.',
            '4. Keep every input segment id exactly once in the output. Do not rename, omit, merge, split, or reorder ids.',
            '5. The output segments array length must equal the input segments array length.',
            '6. If a segment is already in the target language or is not translatable, copy it into text unchanged.',
            '7. Escape all JSON special characters correctly. Newlines inside text must be encoded as \\n.',
            '',
            'Translation rules:',
            '1. Preserve Markdown syntax, heading levels, list markers, table pipes, links, HTML tags, placeholders, and indentation.',
            '2. Never translate fenced code body, inline code, class names, method names, config keys, JSON/XML/YAML keys, URLs, or protected tokens.',
            '3. Code line comments and block comments may be translated, but comment markers and indentation must stay unchanged.',
            '4. Do not invent content and do not drop empty-looking punctuation or placeholders.',
            '5. For Arabic and other RTL target languages, still return standard JSON text with the same segment ids.',
            '',
            'Correct output example:',
            '{"segments":[{"id":"content_1","text":"Translated text"}]}',
            '',
            'Incorrect outputs that will fail:',
            '```json',
            '{"segments":[]}',
            '```',
            'Here is the translation: {"segments":[]}',
            '',
            'Protected tokens that must be copied exactly if they appear:',
            json_encode(array_keys(is_array($protectedTokens) ? $protectedTokens : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '',
            'Segments:',
            json_encode(['segments' => $segments], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function processResponse(string $response, array $params = []): string
    {
        $decoded = $this->decodeResponsePayload($response);
        $decoded = $this->normalizeDecodedPayload($decoded);
        if (!is_array($decoded) || !isset($decoded['segments']) || !is_array($decoded['segments'])) {
            throw new \InvalidArgumentException('Document translation response must be JSON with a segments array.');
        }

        $expected = [];
        foreach (($params['segments'] ?? []) as $segment) {
            if (is_array($segment) && isset($segment['id'])) {
                $expected[(string)$segment['id']] = true;
            }
        }

        $normalized = [];
        $seen = [];
        foreach ($decoded['segments'] as $segment) {
            if (is_array($segment) && !isset($segment['text'])) {
                foreach (['translation', 'translated_text', 'content', 'value'] as $textKey) {
                    if (isset($segment[$textKey])) {
                        $segment['text'] = $segment[$textKey];
                        break;
                    }
                }
            }
            if (!is_array($segment) || !isset($segment['id'], $segment['text'])) {
                throw new \InvalidArgumentException('Each translated segment must contain id and text.');
            }
            $id = (string)$segment['id'];
            if ($id === '' || isset($seen[$id])) {
                throw new \InvalidArgumentException('Translated segment id is empty or duplicated.');
            }
            if ($expected !== [] && !isset($expected[$id])) {
                throw new \InvalidArgumentException('Translated segment id does not exist in source segments: ' . $id);
            }
            $seen[$id] = true;
            $normalized[] = ['id' => $id, 'text' => (string)$segment['text']];
        }

        foreach ($expected as $id => $_) {
            if (!isset($seen[$id])) {
                throw new \InvalidArgumentException('Missing translated segment id: ' . $id);
            }
        }

        return json_encode(['segments' => $normalized], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeResponsePayload(string $response): mixed
    {
        $content = trim($this->stripByteOrderMark($response));
        $content = $this->stripMarkdownFence($content);

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        foreach ($this->extractJsonCandidates($content) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    private function normalizeDecodedPayload(mixed $decoded): mixed
    {
        if (!is_array($decoded)) {
            return $decoded;
        }
        if (isset($decoded['segments']) && is_array($decoded['segments'])) {
            return $decoded;
        }
        foreach (['data', 'result', 'output', 'response'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                $nested = $this->normalizeDecodedPayload($decoded[$key]);
                if (is_array($nested) && isset($nested['segments']) && is_array($nested['segments'])) {
                    return $nested;
                }
            }
        }
        foreach (['translations', 'items'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return ['segments' => $decoded[$key]];
            }
        }
        if (array_is_list($decoded) && $decoded !== []) {
            return ['segments' => $decoded];
        }

        return $decoded;
    }

    private function stripByteOrderMark(string $content): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
    }

    private function stripMarkdownFence(string $content): string
    {
        if (preg_match('/^\s*```(?:json|JSON)?\s*([\s\S]*?)\s*```\s*$/', $content, $matches)) {
            return trim((string)$matches[1]);
        }

        return $content;
    }

    private function extractJsonCandidates(string $content): array
    {
        $candidates = [];
        $length = strlen($content);
        for ($start = 0; $start < $length; $start++) {
            $char = $content[$start];
            if ($char !== '{' && $char !== '[') {
                continue;
            }
            $candidate = $this->extractBalancedJson($content, $start);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        usort($candidates, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return array_values(array_unique($candidates));
    }

    private function extractBalancedJson(string $content, int $start): ?string
    {
        $stack = [];
        $inString = false;
        $escaped = false;
        $length = strlen($content);
        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{') {
                $stack[] = '}';
                continue;
            }
            if ($char === '[') {
                $stack[] = ']';
                continue;
            }
            if (($char === '}' || $char === ']') && $stack !== []) {
                $expected = array_pop($stack);
                if ($char !== $expected) {
                    return null;
                }
                if ($stack === []) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    public function validateParams(array $params = []): array
    {
        $errors = [];
        if (empty($params['source_locale'])) {
            $errors[] = 'source_locale is required.';
        }
        if (empty($params['target_locale']) && empty($params['locale'])) {
            $errors[] = 'target_locale is required.';
        }
        if (empty($params['segments']) || !is_array($params['segments'])) {
            $errors[] = 'segments must be a non-empty array.';
        }

        return $errors;
    }

    public function getParamTemplate(): array
    {
        return [
            'source_locale' => 'zh_Hans_CN',
            'target_locale' => 'en_US',
            'format' => 'markdown',
            'segments' => [
                ['id' => 'title_1', 'text' => '文档标题'],
            ],
            'protected_tokens' => [],
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => 'Translate Markdown documentation',
                'input' => ['segments' => [['id' => 'content_1', 'text' => '## 使用 `Config::get()`']]],
                'expected_output' => '{"segments":[{"id":"content_1","text":"## Use `Config::get()`"}]}',
            ],
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return $modelCode !== '';
    }
}
