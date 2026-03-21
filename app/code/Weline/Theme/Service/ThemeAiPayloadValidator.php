<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

class ThemeAiPayloadValidator
{
    public function extractPayload(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $this->normalizePayload($decoded);
        }

        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $this->normalizePayload($decoded);
            }
        }

        return null;
    }

    public function validatePayload(array $payload): array
    {
        $payload = $this->normalizePayload($payload);
        $errors = [];

        if (($payload['name'] ?? '') === '') {
            $errors[] = 'name is required';
        }

        if (($payload['category'] ?? '') === '') {
            $errors[] = 'category is required';
        }

        if (($payload['component_code'] ?? '') === '') {
            $errors[] = 'component_code is required';
        }

        if (($payload['template_content'] ?? '') === '') {
            $errors[] = 'template_content is required';
        }

        foreach (['config_schema_json', 'default_config_json', 'meta_json'] as $field) {
            if (!is_array($payload[$field] ?? null)) {
                $errors[] = "{$field} must be an object/array";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'payload' => $payload,
        ];
    }

    public function normalizePayload(array $payload): array
    {
        $name = trim((string)($payload['name'] ?? ''));
        $category = $this->slugify((string)($payload['category'] ?? 'basic'));
        $componentCode = trim((string)($payload['component_code'] ?? $payload['code'] ?? ''));
        if ($componentCode === '') {
            $componentCode = $this->slugify($name !== '' ? $name : 'component');
        }
        if (!str_contains($componentCode, '/')) {
            $componentCode = "{$category}/{$componentCode}";
        }

        $normalized = [
            'name' => $name,
            'description' => (string)($payload['description'] ?? ''),
            'category' => $category,
            'component_code' => $this->normalizeComponentCode($componentCode),
            'template_content' => (string)($payload['template_content'] ?? $payload['template'] ?? ''),
            'config_schema_json' => $this->normalizeArray($payload['config_schema_json'] ?? $payload['config_schema'] ?? []),
            'default_config_json' => $this->normalizeArray($payload['default_config_json'] ?? $payload['default_config'] ?? []),
            'meta_json' => $this->normalizeArray($payload['meta_json'] ?? $payload['meta'] ?? []),
            'icon' => (string)($payload['icon'] ?? ''),
            'render_mode' => (string)($payload['render_mode'] ?? 'template_content'),
        ];

        $normalized['meta_json']['position'] = $this->normalizeStringArray(
            $normalized['meta_json']['position'] ?? ['content'],
            ['content']
        );
        $normalized['meta_json']['page_layouts'] = $this->normalizeStringArray(
            $normalized['meta_json']['page_layouts'] ?? ['*'],
            ['*']
        );

        return $normalized;
    }

    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function normalizeStringArray(array|string $value, array $fallback): array
    {
        if (is_array($value)) {
            $items = array_values(array_filter(array_map('strval', $value), static fn(string $item): bool => $item !== ''));
            return $items ?: $fallback;
        }

        $items = array_values(array_filter(array_map(
            static fn(string $item): string => trim($item),
            explode(',', $value)
        ), static fn(string $item): bool => $item !== ''));

        return $items ?: $fallback;
    }

    private function normalizeComponentCode(string $componentCode): string
    {
        [$category, $code] = explode('/', $componentCode, 2);
        return $this->slugify($category) . '/' . $this->slugify($code);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'component';
        return trim($value, '-') ?: 'component';
    }
}
