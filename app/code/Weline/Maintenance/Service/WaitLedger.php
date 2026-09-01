<?php

declare(strict_types=1);

namespace Weline\Maintenance\Service;

/**
 * File-backed wait-token ledger (usable while maintenance blocks DB).
 */
final class WaitLedger
{
    public const STATUS_WAITING = 'waiting';
    public const STATUS_ABANDONED = 'abandoned';
    public const STATUS_REDEEMED = 'redeemed';
    public const STATUS_EXPIRED = 'expired';

    public function __construct(private readonly ?string $basePath = null)
    {
    }

    public function ledgerDir(): string
    {
        return $this->root() . 'var/maintenance/wait_ledger';
    }

    public function cookieIndexPath(): string
    {
        return $this->ledgerDir() . '/_cookie_index.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function createWaiting(
        string $tokenHash,
        string $waveId,
        array $meta = [],
    ): array {
        $now = \time();
        $record = [
            'token_hash' => $tokenHash,
            'wave_id' => $waveId,
            'status' => self::STATUS_WAITING,
            'issued_at' => $now,
            'last_seen_at' => $now,
            'ip_hash' => (string)($meta['ip_hash'] ?? ''),
            'ua_hash' => (string)($meta['ua_hash'] ?? ''),
            'guest_token_hash' => (string)($meta['guest_token_hash'] ?? ''),
            'customer_id' => (string)($meta['customer_id'] ?? ''),
            'cookie_key' => (string)($meta['cookie_key'] ?? ''),
            'min_wait_sec' => (int)($meta['min_wait_sec'] ?? UpgradeWaveService::MIN_WAIT_SECONDS_DEFAULT),
            'redeemed_coupon_code' => '',
            'redeemed_at' => null,
        ];
        $this->writeRecord($record);
        $cookieKey = (string)($record['cookie_key'] ?? '');
        if ($cookieKey !== '') {
            $this->indexCookie($cookieKey, $tokenHash);
        }

        return $record;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $path = $this->recordPath($tokenHash);
        if (!\is_file($path)) {
            return null;
        }
        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByCookieKey(string $cookieKey, string $waveId): ?array
    {
        $index = $this->readCookieIndex();
        $hash = (string)($index[$cookieKey] ?? '');
        if ($hash === '') {
            return null;
        }
        $record = $this->findByTokenHash($hash);
        if ($record === null) {
            return null;
        }
        if ((string)($record['wave_id'] ?? '') !== $waveId) {
            return null;
        }
        if ((string)($record['status'] ?? '') !== self::STATUS_WAITING) {
            return null;
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function writeRecord(array $record): void
    {
        $hash = (string)($record['token_hash'] ?? '');
        if ($hash === '' || !\preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new \InvalidArgumentException('Invalid token hash.');
        }
        $dir = $this->ledgerDir();
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        $payload = \json_encode($record, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        if (!\is_string($payload)) {
            throw new \RuntimeException('Unable to encode wait ledger record.');
        }
        if (@\file_put_contents($this->recordPath($hash), $payload . "\n", \LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write wait ledger record.');
        }
    }

    public function touchHeartbeat(string $tokenHash, ?int $now = null): ?array
    {
        $record = $this->findByTokenHash($tokenHash);
        if ($record === null || (string)($record['status'] ?? '') !== self::STATUS_WAITING) {
            return $record;
        }
        $record['last_seen_at'] = $now ?? \time();
        $this->writeRecord($record);

        return $record;
    }

    public function markAbandoned(string $tokenHash): ?array
    {
        $record = $this->findByTokenHash($tokenHash);
        if ($record === null || (string)($record['status'] ?? '') !== self::STATUS_WAITING) {
            return $record;
        }
        $record['status'] = self::STATUS_ABANDONED;
        $record['last_seen_at'] = \time();
        $this->writeRecord($record);

        return $record;
    }

    public function markExpired(string $tokenHash): ?array
    {
        $record = $this->findByTokenHash($tokenHash);
        if ($record === null || (string)($record['status'] ?? '') !== self::STATUS_WAITING) {
            return $record;
        }
        $record['status'] = self::STATUS_EXPIRED;
        $this->writeRecord($record);

        return $record;
    }

    public function markRedeemed(string $tokenHash, string $couponCode): ?array
    {
        $record = $this->findByTokenHash($tokenHash);
        if ($record === null) {
            return null;
        }
        $record['status'] = self::STATUS_REDEEMED;
        $record['redeemed_coupon_code'] = \strtoupper(\trim($couponCode));
        $record['redeemed_at'] = \time();
        $this->writeRecord($record);

        return $record;
    }

    private function recordPath(string $tokenHash): string
    {
        return $this->ledgerDir() . '/' . $tokenHash . '.json';
    }

    /**
     * @return array<string, string>
     */
    private function readCookieIndex(): array
    {
        $path = $this->cookieIndexPath();
        if (!\is_file($path)) {
            return [];
        }
        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function indexCookie(string $cookieKey, string $tokenHash): void
    {
        $index = $this->readCookieIndex();
        $index[$cookieKey] = $tokenHash;
        $dir = $this->ledgerDir();
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        $payload = \json_encode($index, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        if (\is_string($payload)) {
            @\file_put_contents($this->cookieIndexPath(), $payload . "\n", \LOCK_EX);
        }
    }

    private function root(): string
    {
        $base = $this->basePath ?? (\defined('BP') ? (string)BP : '');
        if ($base === '') {
            throw new \RuntimeException('WaitLedger base path is unavailable.');
        }

        return \rtrim($base, "/\\") . '/';
    }
}
