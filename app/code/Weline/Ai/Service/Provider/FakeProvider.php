<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;

final class FakeProvider implements ProviderInterface
{
    public function getProviderCode(): string
    {
        return 'fake';
    }

    public function supports(string $modelCode): bool
    {
        return str_starts_with(trim($modelCode), 'fake-');
    }

    public function getSupportedModels(): array
    {
        return ['fake-translator'];
    }

    public function generate(AiModel $model, string $prompt, array $params = []): array
    {
        $content = $this->fakeContent($prompt, $params);

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => $this->estimate($prompt),
                'completion_tokens' => $this->estimate($content),
                'total_tokens' => $this->estimate($prompt . $content),
            ],
            'model' => (string)$model->getModelCode(),
            'finish_reason' => 'stop',
        ];
    }

    public function generateStream(AiModel $model, string $prompt, callable $callback, array $params = []): array
    {
        $content = $this->fakeContent($prompt, $params);
        $callback($content);

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => $this->estimate($prompt),
                'completion_tokens' => $this->estimate($content),
                'total_tokens' => $this->estimate($prompt . $content),
            ],
            'model' => (string)$model->getModelCode(),
            'finish_reason' => 'stop',
        ];
    }

    private function fakeContent(string $prompt, array $params): string
    {
        $items = $this->extractBatchItems($prompt);
        if ($items !== []) {
            $lang = trim((string)($params['target_language'] ?? '')) ?: 'target';
            $list = [];
            foreach ($items as $item) {
                $list[] = '[' . $lang . '] ' . (string)$item;
            }

            return json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return '[fake] ' . mb_substr(trim($prompt), 0, 200);
    }

    private function extractBatchItems(string $prompt): array
    {
        $pos = strrpos($prompt, 'Input JSON:');
        if ($pos === false) {
            return [];
        }

        $json = trim(substr($prompt, $pos + strlen('Input JSON:')));
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (is_array($decoded) && array_is_list($decoded) && $decoded !== []) {
            return array_values($decoded);
        }

        return [];
    }

    private function estimate(string $text): int
    {
        return max(1, (int)ceil(mb_strlen($text) / 4));
    }
}
