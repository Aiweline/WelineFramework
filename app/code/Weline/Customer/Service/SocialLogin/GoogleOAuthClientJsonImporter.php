<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

/**
 * Parse Google Cloud "client_secret_*.apps.googleusercontent.com.json" downloads
 * into SystemConfig client_id / client_secret fields.
 */
final class GoogleOAuthClientJsonImporter
{
    public const JSON_FIELD_KEY = 'customer/social_login/google/oauth_client_json';
    public const CLIENT_ID_KEY = 'customer/social_login/google/client_id';
    public const CLIENT_SECRET_KEY = 'customer/social_login/google/client_secret';
    /** Multipart form field name for upload-and-parse (never persisted). */
    public const UPLOAD_FILE_INPUT = 'google_oauth_client_json_file';

    /**
     * @param array<string,mixed> $values
     * @param list<string> $inheritKeys
     * @return array{values:array<string,mixed>,inherit_keys:list<string>,imported:bool,error:?string}
     */
    public static function expandPostedValues(array $values, array $inheritKeys = []): array
    {
        $hadJsonKey = \array_key_exists(self::JSON_FIELD_KEY, $values)
            || \in_array(self::JSON_FIELD_KEY, \array_map('strval', $inheritKeys), true);
        $jsonRaw = $values[self::JSON_FIELD_KEY] ?? null;
        unset($values[self::JSON_FIELD_KEY]);
        $inheritKeys = array_values(array_filter(
            array_map('strval', $inheritKeys),
            static fn(string $key): bool => $key !== self::JSON_FIELD_KEY,
        ));

        if (!\is_string($jsonRaw) || \trim($jsonRaw) === '') {
            // Only force-inherit the scratchpad when this Customer form actually posted it.
            // Unrelated templates (e.g. Captcha) must not get this key injected into inherit_keys.
            if ($hadJsonKey) {
                $inheritKeys[] = self::JSON_FIELD_KEY;
            }

            return [
                'values' => $values,
                'inherit_keys' => \array_values(\array_unique($inheritKeys)),
                'imported' => false,
                'error' => null,
            ];
        }

        try {
            $credentials = self::extractCredentials($jsonRaw);
        } catch (\InvalidArgumentException $e) {
            return [
                'values' => $values,
                'inherit_keys' => \array_values(\array_unique([...$inheritKeys, self::JSON_FIELD_KEY])),
                'imported' => false,
                'error' => self::humanizeError($e->getMessage()),
            ];
        }

        $values[self::CLIENT_ID_KEY] = $credentials['client_id'];
        $values[self::CLIENT_SECRET_KEY] = $credentials['client_secret'];
        $inheritKeys = array_values(array_filter(
            $inheritKeys,
            static fn(string $key): bool => !\in_array($key, [
                self::CLIENT_ID_KEY,
                self::CLIENT_SECRET_KEY,
                self::JSON_FIELD_KEY,
            ], true),
        ));
        $inheritKeys[] = self::JSON_FIELD_KEY;

        return [
            'values' => $values,
            'inherit_keys' => \array_values(\array_unique($inheritKeys)),
            'imported' => true,
            'error' => null,
        ];
    }

    /**
     * @return array{client_id:string,client_secret:string}
     */
    public static function extractCredentials(string $json): array
    {
        $json = \trim($json);
        if ($json === '') {
            throw new \InvalidArgumentException('google_oauth_json_empty');
        }

        try {
            $decoded = \json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('google_oauth_json_invalid');
        }

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('google_oauth_json_invalid');
        }

        $section = null;
        foreach (['web', 'installed', 'ios', 'android'] as $key) {
            if (isset($decoded[$key]) && \is_array($decoded[$key])) {
                $section = $decoded[$key];
                break;
            }
        }
        if ($section === null
            && isset($decoded['client_id'], $decoded['client_secret'])
        ) {
            $section = $decoded;
        }
        if (!\is_array($section)) {
            throw new \InvalidArgumentException('google_oauth_json_missing_web_section');
        }

        $clientId = \trim((string)($section['client_id'] ?? ''));
        $clientSecret = \trim((string)($section['client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            throw new \InvalidArgumentException('google_oauth_json_missing_credentials');
        }
        if (!\str_contains($clientId, '.apps.googleusercontent.com')) {
            throw new \InvalidArgumentException('google_oauth_json_client_id_unexpected');
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    private static function humanizeError(string $code): string
    {
        return match ($code) {
            'google_oauth_json_empty' => '内容为空',
            'google_oauth_json_invalid' => '不是合法 JSON',
            'google_oauth_json_missing_web_section' => '缺少 web/installed 段或顶层 client_id/client_secret',
            'google_oauth_json_missing_credentials' => '缺少 client_id 或 client_secret',
            'google_oauth_json_client_id_unexpected' => 'client_id 不像 Google OAuth 客户端（须含 .apps.googleusercontent.com）',
            default => $code,
        };
    }
}
