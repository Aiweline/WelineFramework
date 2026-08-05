<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Sitemap;

final class SitemapOperationLock
{
    /**
     * @param list<int|string> $identity
     * @return array{acquired:bool,result:mixed}
     */
    public function run(string $purpose, array $identity, callable $callback): array
    {
        $purpose = preg_replace('/[^a-z0-9_-]+/i', '-', trim($purpose)) ?: 'operation';
        $directory = BP . '/var/locks/weline-seo';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException((string)__('无法创建 Sitemap 锁目录'));
        }

        $encoded = json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $path = $directory . '/' . $purpose . '-' . hash('sha256', $encoded) . '.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException((string)__('无法打开 Sitemap 操作锁'));
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return ['acquired' => false, 'result' => null];
        }

        try {
            return ['acquired' => true, 'result' => $callback()];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
