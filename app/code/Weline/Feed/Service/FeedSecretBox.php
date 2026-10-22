<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

/**
 * Seals account secret maps. The ciphertext is what the account table stores.
 */
final class FeedSecretBox
{
    public function __construct(private readonly string $keyMaterial)
    {
    }

    /**
     * @param array<string, string> $fields
     */
    public function seal(array $fields): string
    {
        $json = $this->encode($fields);
        $key = $this->key();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($cipher) || strlen($tag) !== 16) {
            throw new \RuntimeException('feed_secret_seal_failed');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    /**
     * @return array<string, string>
     */
    public function open(string $payload): array
    {
        $raw = base64_decode($payload, true);
        if (!is_string($raw) || strlen($raw) < 28) {
            throw new \RuntimeException('feed_secret_open_failed');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $json = openssl_decrypt($cipher, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($json)) {
            throw new \RuntimeException('feed_secret_open_failed');
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('feed_secret_open_failed');
        }
        $fields = [];
        foreach ($decoded as $name => $value) {
            if (!is_string($name) || (!is_string($value) && !is_int($value))) {
                continue;
            }
            $fields[$name] = (string)$value;
        }

        return $fields;
    }

    /**
     * @param array<string, string> $fields
     */
    public function fingerprint(array $fields): string
    {
        return hash('sha256', $this->encode($fields));
    }

    /**
     * @param array<string, string> $fields
     */
    private function encode(array $fields): string
    {
        ksort($fields);
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $json;
    }

    private function key(): string
    {
        $material = trim($this->keyMaterial);
        if ($material === '') {
            throw new \RuntimeException('feed_secret_key_missing');
        }

        return hash('sha256', $material, true);
    }
}
