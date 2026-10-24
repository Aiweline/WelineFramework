<?php

declare(strict_types=1);

namespace Weline\Social\Service;

final class SocialPublishRouter
{
    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $media
     * @param array<string, mixed> $platformDefinition
     * @param array<string, mixed> $options
     * @return array{channel: string, reason: string, content_kind: string, platform_code: string}
     */
    public function route(array $account, string $contentKind, array $media = [], array $platformDefinition = [], array $options = []): array
    {
        $platformCode = (string)($account['platform_code'] ?? $platformDefinition['code'] ?? '');
        $blocked = static function (string $channel, string $reason) use ($contentKind, $platformCode): array {
            return [
                'channel' => $channel,
                'reason' => $reason,
                'content_kind' => $contentKind,
                'platform_code' => $platformCode,
            ];
        };

        if (!\in_array($contentKind, ['text', 'image', 'video'], true)) {
            return $blocked('blocked', 'unknown_content_kind');
        }
        if (($options['mode'] ?? '') === 'preview') {
            return $blocked('draft_only', 'preview_mode');
        }
        $autoPlatformCodes = $options['auto_platform_codes'] ?? [];
        if (\is_array($autoPlatformCodes) && $autoPlatformCodes !== [] && !\in_array($platformCode, $autoPlatformCodes, true)) {
            return $blocked('draft_only', 'platform_not_selected');
        }
        if ($platformCode === '' || $platformDefinition === []) {
            return $blocked('blocked', 'unknown_platform');
        }
        if ((int)($account['publish_enabled'] ?? 0) !== 1 || (string)($account['test_status'] ?? '') !== 'passed') {
            return $blocked('blocked', 'account_not_verified');
        }
        if (\in_array($contentKind, ['image', 'video'], true) && $media === []) {
            return $blocked('blocked', 'missing_media');
        }
        if ((string)($platformDefinition['media_upload'][$contentKind] ?? 'none') === 'implemented') {
            return $blocked('api', '');
        }
        if (($platformDefinition['publish_ui'] ?? false) === true) {
            return $blocked('extension', '');
        }
        return $blocked('blocked', 'no_channel');
    }
}
