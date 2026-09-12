<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * File-backed payment link store (var/helppay/links.json) until ORM Upgrade is promoted.
 */
final class PaymentLinkRecordRepository
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $base = \defined('BP') ? rtrim((string) BP, '/\\') : dirname(__DIR__, 5);
        $this->path = $path ?? ($base . '/var/helppay/links.json');
    }

    /** @param array<string,mixed> $record */
    public function save(array $record): void
    {
        $kind = (string) ($record['kind'] ?? '');
        $token = (string) ($record['token'] ?? '');
        if ($kind === '' || $token === '') {
            return;
        }
        $all = $this->all();
        $all[$kind . ':' . $token] = $record;
        $this->write($all);
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $kind, string $token): ?array
    {
        $all = $this->all();

        return $all[$kind . ':' . $token] ?? null;
    }

    /** @return array<string, array<string,mixed>> */
    private function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $raw = (string) file_get_contents($this->path);
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, array<string,mixed>> $all */
    private function write(array $all): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents(
            $this->path,
            json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
