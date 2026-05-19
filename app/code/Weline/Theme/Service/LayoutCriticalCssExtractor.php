<?php
declare(strict_types=1);

namespace Weline\Theme\Service;

final class LayoutCriticalCssExtractor
{
    private const CACHE_DIR = 'var/cache/theme_layout_critical';

    public function shouldHandleSource(string $sourceFile): bool
    {
        $path = $this->normalizePath($sourceFile);
        $lowerPath = strtolower($path);

        if (!str_ends_with($lowerPath, '.phtml')) {
            return false;
        }

        return (str_contains($lowerPath, '/view/theme/') && str_contains($lowerPath, '/layouts/'))
            || preg_match('#/app/design/[^/]+/[^/]+/(frontend|backend)/layouts/#', $lowerPath) === 1;
    }

    public function shouldForceRecompile(string $sourceFile): bool
    {
        if (!$this->shouldHandleSource($sourceFile) || !is_file($sourceFile)) {
            return false;
        }

        return !$this->isMetadataFresh($sourceFile);
    }

    public function extractAndPersist(string $content, string $sourceFile): string
    {
        if (!$this->shouldHandleSource($sourceFile)) {
            return $content;
        }

        $blocks = [];
        $order = 0;
        $updated = preg_replace_callback(
            '#<style\b([^>]*)>(.*?)</style>#is',
            function (array $matches) use (&$blocks, &$order, $sourceFile): string {
                $attrs = trim((string)($matches[1] ?? ''));
                $css = (string)($matches[2] ?? '');
                $this->assertStaticStyle($attrs, $css, $sourceFile, $order);

                $trimmedCss = trim($css);
                if ($trimmedCss !== '') {
                    $blocks[] = [
                        'order' => $order,
                        'attrs' => $attrs,
                        'css' => $trimmedCss,
                    ];
                }
                $order++;

                return '';
            },
            $content
        );

        if ($updated === null) {
            throw new \RuntimeException('Failed to extract layout critical CSS from: ' . $sourceFile);
        }

        $this->writeMetadata($sourceFile, $blocks);

        return $updated;
    }

    public function isMetadataFresh(string $sourceFile): bool
    {
        $metadata = $this->loadMetadata($sourceFile);
        if ($metadata === []) {
            return false;
        }

        return ($metadata['fingerprint'] ?? null) === $this->buildFingerprint($sourceFile);
    }

    public function loadMetadata(string $sourceFile): array
    {
        $path = $this->getMetadataPath($sourceFile);
        if (!is_file($path)) {
            return [];
        }

        $metadata = include $path;

        return is_array($metadata) ? $metadata : [];
    }

    public function getMetadataPath(string $sourceFile): string
    {
        return $this->getCacheRoot() . DS . $this->getSourceHash($sourceFile) . '.php';
    }

    public function getSourceHash(string $sourceFile): string
    {
        return sha1($this->normalizePath($sourceFile));
    }

    private function writeMetadata(string $sourceFile, array $blocks): void
    {
        $root = $this->getCacheRoot();
        $lockRoot = $root . DS . 'locks';
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Unable to create layout critical CSS cache dir: ' . $root);
        }
        if (!is_dir($lockRoot) && !mkdir($lockRoot, 0775, true) && !is_dir($lockRoot)) {
            throw new \RuntimeException('Unable to create layout critical CSS lock dir: ' . $lockRoot);
        }

        $sourceHash = $this->getSourceHash($sourceFile);
        $lockPath = $lockRoot . DS . $sourceHash . '.lock';
        $lock = fopen($lockPath, 'c+');
        if (!$lock) {
            throw new \RuntimeException('Unable to open layout critical CSS lock: ' . $lockPath);
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock layout critical CSS metadata: ' . $lockPath);
            }

            $metadata = [
                'source' => $this->normalizePath($sourceFile),
                'source_hash' => $sourceHash,
                'fingerprint' => $this->buildFingerprint($sourceFile),
                'css' => $blocks,
                'compiled_at' => time(),
            ];

            $target = $this->getMetadataPath($sourceFile);
            $tmp = $target . '.tmp.' . getmypid();
            $content = "<?php\nreturn " . var_export($metadata, true) . ";\n";
            if (file_put_contents($tmp, $content) === false) {
                throw new \RuntimeException('Unable to write layout critical CSS metadata: ' . $tmp);
            }
            if (!rename($tmp, $target)) {
                @unlink($tmp);
                throw new \RuntimeException('Unable to publish layout critical CSS metadata: ' . $target);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function assertStaticStyle(string $attrs, string $css, string $sourceFile, int $order): void
    {
        $probe = $attrs . "\n" . $css;
        $patterns = [
            '#<\?(?:php|=|\s)#i',
            '#\{\{.*?\}\}#s',
            '#@static\s*\(#i',
            '#\$_(?:SESSION|COOKIE|REQUEST|POST|GET|SERVER)\b#i',
            '#\$this\s*->\s*(?:request|session|cookie)\b#i',
            '#\$(?:auth|csrf|customer|cookie|login|request|session|token|user)\b#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $probe) === 1) {
                throw new \RuntimeException(
                    'Dynamic CSS is not allowed in layout critical CSS: '
                    . $sourceFile
                    . ' style#'
                    . $order
                );
            }
        }
    }

    private function buildFingerprint(string $sourceFile): string
    {
        if (!is_file($sourceFile)) {
            return 'missing/0/';
        }

        return ((int)filemtime($sourceFile))
            . '/'
            . ((int)filesize($sourceFile))
            . '/'
            . (string)md5_file($sourceFile);
    }

    private function getCacheRoot(): string
    {
        return rtrim(BP, '\\/') . DS . str_replace('/', DS, self::CACHE_DIR);
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);
        if ($real !== false) {
            $path = $real;
        }

        return str_replace('\\', '/', $path);
    }
}
