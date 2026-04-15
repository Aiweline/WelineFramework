<?php

declare(strict_types=1);

namespace Weline\Server\Service;

final class SslHandshakeFailureClassifier
{
    /**
     * Only classify server-side inbound TLS handshake failures.
     *
     * Current WLS runtime performs inbound TLS handshakes in `worker_ssl.php`.
     * Dispatcher/PassthroughCore TLS probe failures are outbound/backend diagnostics
     * and intentionally keep their own wording and thresholds.
     *
     * This boundary is explicit on purpose so `worker_ssl.php`-only integration
     * is not mistaken for incomplete coverage in later reviews.
     *
     * @var array<string, string>
     */
    private const BENIGN_REASON_PATTERNS = [
        'certificate unknown' => '客户端不信任当前证书链/CA',
        'alert unknown ca' => '客户端不信任当前证书链/CA',
        'sslv3 alert certificate unknown' => '客户端不信任当前证书链/CA',
        'connection reset by peer' => '客户端在握手阶段重置了连接',
        'software caused connection abort' => '客户端在握手阶段中止了连接',
        '你的主机中的软件中止了一个已建立的连接' => '客户端在握手阶段中止了连接',
    ];

    public static function isBenign(string $errorMessage): bool
    {
        return self::matchBenignReason($errorMessage) !== null;
    }

    /**
     * @return array{benign: bool, level: 'info'|'warning', message: string}
     */
    public static function classify(string $peerName, int $connectionId, string $errorMessage): array
    {
        $benignReason = self::matchBenignReason($errorMessage);
        if ($benignReason !== null) {
            return [
                'benign' => true,
                'level' => 'info',
                'message' => "SSL 握手已中止: {$peerName} (connId: {$connectionId}) - {$benignReason}; {$errorMessage} [benign]",
            ];
        }

        return [
            'benign' => false,
            'level' => 'warning',
            'message' => "SSL 握手失败: {$peerName} (connId: {$connectionId}) - {$errorMessage}",
        ];
    }

    private static function matchBenignReason(string $errorMessage): ?string
    {
        $normalizedMessage = \strtolower($errorMessage);

        foreach (self::BENIGN_REASON_PATTERNS as $pattern => $reason) {
            if (\str_contains($normalizedMessage, $pattern)) {
                return $reason;
            }
        }

        return null;
    }
}
