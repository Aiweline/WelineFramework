<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

/**
 * 模拟 AI 供应商识别与预览构造。
 *
 * 规则：fake / fake-* 供应商产出的内容只允许做预览，绝不写入数据库。
 */
final class BlogTranslationSimulation
{
    public const FAKE_PROVIDER = 'fake';
    public const FAKE_MODEL_PREFIX = 'fake-';
    public const PREVIEW_CONTENT_LENGTH = 240;

    public static function isFake(?string $modelCode, ?string $supplier = null): bool
    {
        $supplier = \strtolower(\trim((string)$supplier));
        if ($supplier === self::FAKE_PROVIDER) {
            return true;
        }
        $modelCode = \strtolower(\trim((string)$modelCode));

        return $modelCode === self::FAKE_PROVIDER || \str_starts_with($modelCode, self::FAKE_MODEL_PREFIX);
    }

    /**
     * @param array<string, mixed> $data 即将落库的字段
     * @return array<string, string>
     */
    public static function preview(array $data): array
    {
        $content = \trim((string)($data['content'] ?? ''));

        return [
            'slug' => (string)($data['slug'] ?? ''),
            'title' => (string)($data['title'] ?? ''),
            'excerpt' => (string)($data['excerpt'] ?? ''),
            'status' => (string)($data['status'] ?? ''),
            'content_preview' => \mb_substr($content, 0, self::PREVIEW_CONTENT_LENGTH),
        ];
    }
}
