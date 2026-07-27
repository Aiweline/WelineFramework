<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Namespace;

/** Pure logical-key decoration. It never reads runtime state or storage. */
final class NamespaceKeyDecorator
{
    private const KEY_PREFIX = '@ns/';
    private const TAG_PREFIX = '@ns-tag/';

    /** @param array<string, int> $generations */
    public function fingerprint(array $generations): string
    {
        if ($generations === []) {
            throw new \InvalidArgumentException(__('命名空间代际向量不能为空'));
        }

        $pairs = [];
        foreach ($generations as $namespace => $generation) {
            if (!is_string($namespace) || $namespace === '' || !is_int($generation) || $generation < 0) {
                throw new \InvalidArgumentException(__('命名空间代际向量包含无效成员'));
            }
            $pairs[hash('sha256', $namespace) . '=' . $generation] = true;
        }
        $pairs = array_keys($pairs);
        sort($pairs, SORT_STRING);
        return hash('sha256', 'ns-v1|' . implode(';', $pairs));
    }

    public function decorate(string $logicalKey, string $fingerprint): string
    {
        $this->assertFingerprint($fingerprint);
        return self::KEY_PREFIX . $fingerprint . '/' . $logicalKey;
    }

    public function decorateTag(string $tag, string $fingerprint): string
    {
        $this->assertFingerprint($fingerprint);
        return self::TAG_PREFIX . $fingerprint . '/' . $tag;
    }

    public function undecorate(string $decoratedKey, string $fingerprint): ?string
    {
        $this->assertFingerprint($fingerprint);
        $prefix = self::KEY_PREFIX . $fingerprint . '/';
        return str_starts_with($decoratedKey, $prefix) ? substr($decoratedKey, strlen($prefix)) : null;
    }

    public function undecorateTag(string $decoratedTag, string $fingerprint): ?string
    {
        $this->assertFingerprint($fingerprint);
        $prefix = self::TAG_PREFIX . $fingerprint . '/';
        return str_starts_with($decoratedTag, $prefix) ? substr($decoratedTag, strlen($prefix)) : null;
    }

    private function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException(__('命名空间指纹必须是小写 SHA-256'));
        }
    }
}
