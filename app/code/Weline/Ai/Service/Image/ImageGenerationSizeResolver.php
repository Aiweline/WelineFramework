<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Image;

/**
 * Derives a provider-safe request size when callers only supply aspect/target
 * geometry. PageBuilder intentionally omits `size` and keeps `target_size` as
 * the post-acceptance rendered contract.
 */
final class ImageGenerationSizeResolver
{
    /**
     * @param array<string,mixed> $params
     */
    public function resolve(array $params, ?string $modelCode = null): string
    {
        $explicit = \trim((string)($params['size'] ?? ''));
        if ($this->isValidSize($explicit)) {
            return $explicit;
        }

        $ratio = $this->resolveAspectRatio($params);
        $highResWide = $this->supportsHighResWide($modelCode);
        if ($ratio >= 2.2) {
            return $highResWide ? '1920x1080' : '1792x1024';
        }
        if ($ratio >= 1.55) {
            return $highResWide ? '1920x1080' : '1792x1024';
        }
        if ($ratio > 0.0 && $ratio <= 0.7) {
            return '1024x1792';
        }

        return '1024x1024';
    }

    /**
     * @param array<string,mixed> $params
     */
    private function resolveAspectRatio(array $params): float
    {
        foreach (['aspect_ratio', 'target_aspect_ratio'] as $key) {
            $ratio = $this->parseRatio((string)($params[$key] ?? ''));
            if ($ratio > 0.0) {
                return $ratio;
            }
        }

        $targetSize = \trim((string)($params['target_size'] ?? ''));
        if ($this->isValidSize($targetSize)) {
            [$width, $height] = \array_map('intval', \explode('x', \strtolower($targetSize), 2));
            if ($width > 0 && $height > 0) {
                return $width / $height;
            }
        }

        return 1.0;
    }

    private function parseRatio(string $value): float
    {
        $value = \trim($value);
        if ($value === '') {
            return 0.0;
        }
        if (\preg_match('/^(\d+(?:\.\d+)?)\s*[:\/xX]\s*(\d+(?:\.\d+)?)$/', $value, $matches) === 1) {
            $width = (float)$matches[1];
            $height = (float)$matches[2];
            return ($width > 0.0 && $height > 0.0) ? ($width / $height) : 0.0;
        }
        if (\is_numeric($value)) {
            $ratio = (float)$value;

            return $ratio > 0.0 ? $ratio : 0.0;
        }

        return 0.0;
    }

    private function supportsHighResWide(?string $modelCode): bool
    {
        $modelCode = \strtolower(\trim((string)$modelCode));
        if ($modelCode === '') {
            return false;
        }

        foreach (['seedream', 'flux', 'sd3', 'stable-diffusion-3'] as $needle) {
            if (\str_contains($modelCode, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isValidSize(string $size): bool
    {
        return \preg_match('/^\d{2,5}x\d{2,5}$/', \strtolower(\trim($size))) === 1;
    }
}
