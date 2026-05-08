<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

final class ImageGenerationResponseNormalizer
{
    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public static function normalize(array $result, string $modelCode = ''): array
    {
        $images = [];
        foreach (is_array($result['images'] ?? null) ? $result['images'] : [] as $image) {
            if (!is_array($image)) {
                continue;
            }
            $normalized = self::normalizeImage($image);
            if ($normalized !== []) {
                $images[] = $normalized;
            }
        }

        return [
            'images' => $images,
            'content' => (string)($result['content'] ?? ''),
            'usage' => self::normalizeUsage(is_array($result['usage'] ?? null) ? $result['usage'] : []),
            'model' => (string)($result['model'] ?? $modelCode),
            'finish_reason' => (string)($result['finish_reason'] ?? 'stop'),
            'request_url' => (string)($result['request_url'] ?? ''),
            'request' => is_array($result['request'] ?? null) ? $result['request'] : [],
            'raw' => $result['raw'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @param array<string,mixed> $requestData
     * @return array<string,mixed>
     */
    public static function fromOpenAiImageResponse(array $response, string $modelCode, array $requestData, string $requestUrl = ''): array
    {
        $images = [];
        $fallbackMimeType = self::mimeTypeFromFormat((string)($requestData['output_format'] ?? $requestData['response_format'] ?? ''));
        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $image = self::normalizeImage($item, $fallbackMimeType);
            if ($image !== []) {
                $images[] = $image;
            }
        }

        return self::normalize([
            'images' => $images,
            'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : [],
            'model' => (string)($response['model'] ?? $modelCode),
            'finish_reason' => 'stop',
            'request_url' => $requestUrl,
            'request' => $requestData,
            'raw' => $response,
        ], $modelCode);
    }

    /**
     * @param array<string,mixed> $response
     * @param array<string,mixed> $requestData
     * @return array<string,mixed>
     */
    public static function fromGeminiGenerateContentResponse(array $response, string $modelCode, array $requestData, string $requestUrl = ''): array
    {
        $images = [];
        $textParts = [];
        $finishReason = 'stop';

        foreach (is_array($response['candidates'] ?? null) ? $response['candidates'] : [] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if (!empty($candidate['finishReason']) && is_scalar($candidate['finishReason'])) {
                $finishReason = (string)$candidate['finishReason'];
            }
            $content = is_array($candidate['content'] ?? null) ? $candidate['content'] : [];
            foreach (is_array($content['parts'] ?? null) ? $content['parts'] : [] as $part) {
                if (!is_array($part)) {
                    continue;
                }

                if (isset($part['text']) && is_scalar($part['text'])) {
                    $text = trim((string)$part['text']);
                    if ($text !== '') {
                        $textParts[] = $text;
                    }
                }

                $inlineData = is_array($part['inlineData'] ?? null)
                    ? $part['inlineData']
                    : (is_array($part['inline_data'] ?? null) ? $part['inline_data'] : []);
                if ($inlineData === []) {
                    continue;
                }

                $image = self::normalizeImage([
                    'b64_json' => $inlineData['data'] ?? '',
                    'mime_type' => $inlineData['mimeType'] ?? $inlineData['mime_type'] ?? 'image/png',
                ]);
                if ($image !== []) {
                    $images[] = $image;
                }
            }
        }

        $content = implode("\n", $textParts);
        if ($content !== '') {
            foreach ($images as &$image) {
                if (empty($image['revised_prompt'])) {
                    $image['revised_prompt'] = $content;
                }
            }
            unset($image);
        }

        return self::normalize([
            'images' => $images,
            'content' => $content,
            'usage' => is_array($response['usageMetadata'] ?? null) ? $response['usageMetadata'] : [],
            'model' => (string)($response['modelVersion'] ?? $modelCode),
            'finish_reason' => $finishReason,
            'request_url' => $requestUrl,
            'request' => $requestData,
            'raw' => $response,
        ], $modelCode);
    }

    /**
     * @param array<string,mixed> $image
     * @return array<string,string>
     */
    private static function normalizeImage(array $image, string $fallbackMimeType = ''): array
    {
        $normalized = [];
        foreach (['url', 'b64_json', 'revised_prompt'] as $key) {
            if (isset($image[$key]) && is_scalar($image[$key]) && trim((string)$image[$key]) !== '') {
                $normalized[$key] = (string)$image[$key];
            }
        }
        foreach (['base64', 'b64', 'image_base64', 'data'] as $key) {
            if (!empty($normalized['b64_json']) || !isset($image[$key]) || !is_scalar($image[$key])) {
                continue;
            }
            $value = trim((string)$image[$key]);
            if ($value !== '') {
                $normalized['b64_json'] = $value;
            }
        }

        $mimeType = '';
        foreach (['mime_type', 'mimeType'] as $key) {
            if (isset($image[$key]) && is_scalar($image[$key])) {
                $mimeType = strtolower(trim((string)$image[$key]));
                break;
            }
        }
        if ($mimeType === '' && !empty($normalized['b64_json'])) {
            $mimeType = $fallbackMimeType !== '' ? $fallbackMimeType : 'image/png';
        }
        if ($mimeType !== '') {
            $normalized['mime_type'] = $mimeType;
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $usage
     * @return array<string,int|float>
     */
    private static function normalizeUsage(array $usage): array
    {
        if (isset($usage['prompt_tokens']) || isset($usage['completion_tokens']) || isset($usage['total_tokens'])) {
            return [
                'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int)($usage['total_tokens'] ?? 0),
            ];
        }

        $promptTokens = (int)($usage['promptTokenCount'] ?? 0);
        $completionTokens = (int)($usage['candidatesTokenCount'] ?? 0);
        $totalTokens = (int)($usage['totalTokenCount'] ?? ($promptTokens + $completionTokens));

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
        ];
    }

    private static function mimeTypeFromFormat(string $format): string
    {
        return match (strtolower(trim($format))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'png' => 'image/png',
            default => '',
        };
    }

    private function __construct()
    {
    }
}
