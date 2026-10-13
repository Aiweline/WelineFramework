<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Framework\Http\Fpc\FpcPierceStamp;
use Weline\Framework\Runtime\Runtime;

/**
 * WLS：Webhook URL 附带 FPC 签章打穿；FPM：不附带。
 * 签章变更后由 ack hash 提示开发者去 Git 换地址。
 */
class DeployWebhookPierceGate
{
    public const ACK_CONFIG_KEY = 'webhook_pierce_ack_hash';

    public function __construct(
        private readonly DeployConfigService $deployConfigService
    ) {
    }

    /**
     * WLS 运行时才把签章拼进 Webhook URL / 启用确认提示。
     */
    public function shouldAttachPierceToWebhookUrl(): bool
    {
        try {
            return Runtime::isWls();
        } catch (\Throwable) {
            return false;
        }
    }

    public function currentPierceSecret(): string
    {
        try {
            return \strtolower(\trim(FpcPierceStamp::readSecret()));
        } catch (\Throwable) {
            return '';
        }
    }

    public function currentPierceFingerprint(): string
    {
        $secret = $this->currentPierceSecret();
        if ($secret === '' || \preg_match('/\A[a-f0-9]{64}\z/', $secret) !== 1) {
            // FpcPierceStamp secrets are 32-byte hex (64 chars).
            return '';
        }

        return \hash('sha256', $secret);
    }

    public function acknowledgedFingerprint(): string
    {
        $settings = $this->deployConfigService->getSettings();

        return \strtolower(\trim((string)($settings[self::ACK_CONFIG_KEY] ?? '')));
    }

    /**
     * 开发者须「确认已换」Git Webhook URL 中的签章 query。
     */
    public function isPierceAckPending(): bool
    {
        if (!$this->shouldAttachPierceToWebhookUrl()) {
            return false;
        }
        $current = $this->currentPierceFingerprint();
        if ($current === '') {
            return false;
        }

        return !\hash_equals($current, $this->acknowledgedFingerprint());
    }

    public function acknowledgeCurrentPierce(): bool
    {
        $fingerprint = $this->currentPierceFingerprint();
        if ($fingerprint === '') {
            return false;
        }
        $settings = $this->deployConfigService->getSettings();
        $settings[self::ACK_CONFIG_KEY] = $fingerprint;

        return $this->deployConfigService->saveSettings($settings);
    }

    /**
     * 在路径 URL 上附加 w_fpc_pierce（仅 WLS 且签章可读时）。
     */
    public function appendPierceQuery(string $webhookUrl): string
    {
        $webhookUrl = \rtrim(\trim($webhookUrl), '/');
        if ($webhookUrl === '' || !$this->shouldAttachPierceToWebhookUrl()) {
            return $webhookUrl;
        }
        $secret = $this->currentPierceSecret();
        if ($secret === '') {
            try {
                $secret = \strtolower(\trim(FpcPierceStamp::ensureSecret()));
            } catch (\Throwable) {
                return $webhookUrl;
            }
        }
        if ($secret === '') {
            return $webhookUrl;
        }
        $queryName = FpcPierceStamp::QUERY_NAME;
        $parts = \parse_url($webhookUrl);
        if (!\is_array($parts)) {
            return $webhookUrl;
        }
        $query = [];
        if (!empty($parts['query'])) {
            \parse_str((string)$parts['query'], $query);
        }
        $query[$queryName] = $secret;
        $rebuilt = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= ($parts['path'] ?? '');
        $qs = \http_build_query($query);

        return $qs !== '' ? $rebuilt . '?' . $qs : $rebuilt;
    }
}
