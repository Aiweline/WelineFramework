<?php

declare(strict_types=1);

namespace Weline\Framework\MarketplaceMeta;

final class MarketplaceTag
{
    public static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return '';
        }

        if (str_contains($code, ':')) {
            [$type, $value] = explode(':', $code, 2);
            $type = self::normalizeType($type);
            $value = self::normalizeTypedValue($value);

            return $type !== '' && $value !== '' ? $type . ':' . $value : '';
        }

        $code = str_replace('-', '_', $code);
        $code = preg_replace('/\s+/', '_', $code) ?: $code;

        return trim($code, '.');
    }

    public static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/\s+/', '-', $type) ?: $type;

        return trim($type, '.:-_');
    }

    public static function isValidCode(string $code): bool
    {
        $code = self::normalizeCode($code);
        if ($code === '' || substr_count($code, ':') > 1) {
            return false;
        }

        return (bool)preg_match('/^[a-z0-9_.-]+(?::[a-z0-9_.-]+)?$/', $code);
    }

    /**
     * @return array{code:string,type:string,value:string,typed:bool}
     */
    public static function parse(string $code): array
    {
        $code = self::normalizeCode($code);
        if ($code === '') {
            return [
                'code' => '',
                'type' => '',
                'value' => '',
                'typed' => false,
            ];
        }

        if (str_contains($code, ':')) {
            [$type, $value] = explode(':', $code, 2);

            return [
                'code' => $code,
                'type' => $type,
                'value' => $value,
                'typed' => true,
            ];
        }

        if (str_contains($code, '.')) {
            [$type, $value] = explode('.', $code, 2);

            return [
                'code' => $code,
                'type' => $type,
                'value' => $value,
                'typed' => false,
            ];
        }

        return [
            'code' => $code,
            'type' => str_starts_with($code, 'custom_') ? 'custom' : 'system',
            'value' => $code,
            'typed' => false,
        ];
    }

    public static function typeFromCode(string $code): string
    {
        return self::parse($code)['type'];
    }

    public static function surfaceFromCode(string $code): string
    {
        $parsed = self::parse($code);

        return $parsed['type'] === 'surface' ? $parsed['value'] : '';
    }

    public static function seoSlug(string $code): string
    {
        return str_replace([':', '.', '_'], '-', self::normalizeCode($code));
    }

    public static function humanLabel(string $code): string
    {
        $parsed = self::parse($code);
        $subject = $parsed['value'] !== '' ? $parsed['value'] : $parsed['code'];

        return ucwords(str_replace(['-', '_', '.'], ' ', $subject));
    }

    private static function normalizeTypedValue(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', '-', $value) ?: $value;

        return trim($value, '.:-_');
    }
}
