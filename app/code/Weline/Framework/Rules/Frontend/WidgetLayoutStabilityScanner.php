<?php

declare(strict_types=1);

namespace Weline\Framework\Rules\Frontend;

/**
 * Static gate: storefront widgets + product-card partials must reserve layout
 * (Theme .w-frame / .w-skeleton[data-size=card]) per widget_layout_stability_theme_primitives.
 */
final class WidgetLayoutStabilityScanner
{
    public const TYPE_MISSING_FRAME = 'missing_layout_frame';
    public const TYPE_MISSING_SKELETON = 'missing_layout_skeleton';

    /**
     * @return list<array{type:string,path:string,line:int,snippet:string}>
     */
    public function scanProject(?string $codeRoot = null): array
    {
        $roots = $this->resolveScanRoots($codeRoot);
        $violations = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'phtml') {
                    continue;
                }
                $abs = $file->getPathname();
                $rel = $this->toRelativePath($abs, $root);
                // Prefix design roots so reports stay unambiguous across app/code vs app/design.
                if ($this->isDesignRoot($root)) {
                    $rel = 'design:' . $rel;
                }
                if (!$this->isIncluded($rel)) {
                    continue;
                }
                $violations = array_merge($violations, $this->scanFile($abs, $rel));
            }
        }

        return $violations;
    }

    /**
     * @return list<array{type:string,path:string,line:int,snippet:string}>
     */
    public function scanFile(string $absolutePath, ?string $relativePath = null): array
    {
        $content = @file_get_contents($absolutePath);
        if ($content === false || $content === '') {
            return [];
        }
        $rel = $relativePath ?? $absolutePath;
        $violations = [];

        if ($this->needsHydrateSkeleton($content) && !$this->hasCardSkeleton($content)) {
            $violations[] = [
                'type' => self::TYPE_MISSING_SKELETON,
                'path' => $rel,
                'line' => $this->lineOf($content, 'data-pdp-lazy-shell')
                    ?: $this->lineOf($content, 'data-weline-hydrate'),
                'snippet' => 'hydrate/lazy-shell without .w-skeleton[data-size=card]',
            ];
        }

        if ($this->isCardPartial($rel) || $this->isFrontendWidget($rel)) {
            foreach ($this->unframedBusinessImgs($content) as $hit) {
                $violations[] = [
                    'type' => self::TYPE_MISSING_FRAME,
                    'path' => $rel,
                    'line' => $hit['line'],
                    'snippet' => $hit['snippet'],
                ];
            }
        }

        return $violations;
    }

    public function formatViolation(array $violation): string
    {
        return sprintf(
            '[%s] %s:%d — %s',
            (string)($violation['type'] ?? ''),
            (string)($violation['path'] ?? ''),
            (int)($violation['line'] ?? 0),
            (string)($violation['snippet'] ?? ''),
        );
    }

    private function needsHydrateSkeleton(string $content): bool
    {
        return str_contains($content, 'data-pdp-lazy-shell')
            || (str_contains($content, 'data-weline-hydrate') && str_contains($content, 'is-deferred'));
    }

    private function hasCardSkeleton(string $content): bool
    {
        return (bool)preg_match(
            '/class\s*=\s*["\'][^"\']*\bw-skeleton\b[^"\']*["\'][^>]*data-size\s*=\s*["\']card["\']'
            . '|data-size\s*=\s*["\']card["\'][^>]*class\s*=\s*["\'][^"\']*\bw-skeleton\b/i',
            $content
        );
    }

    private function hasFrame(string $content): bool
    {
        return (bool)preg_match('/\bw-frame\b/', $content);
    }

    /**
     * Business <img> tags that lack a nearby .w-frame open (icons / explicit exempt skipped).
     *
     * @return list<array{line:int,snippet:string}>
     */
    private function unframedBusinessImgs(string $content): array
    {
        // Flatten embedded PHP short/echo tags so attribute ">" inside them does not truncate <img>.
        $flat = $this->flattenPhpForTagScan($content);
        if (!preg_match_all('/<img\b[^>]*>/i', $flat, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $hits = [];
        foreach ($matches[0] as [$tag, $offset]) {
            if ($this->isExemptImgTag($tag)) {
                continue;
            }
            if (!$this->isBusinessImgTag($tag)) {
                continue;
            }
            if ($this->hasNearbyFrameOpen($flat, (int)$offset)) {
                continue;
            }
            $hits[] = [
                'line' => substr_count(substr($content, 0, (int)$offset), "\n") + 1,
                'snippet' => 'img without nearby .w-frame (use Theme frame or data-layout-exempt)',
            ];
        }

        return $hits;
    }

    private function flattenPhpForTagScan(string $content): string
    {
        $out = preg_replace_callback(
            '/<\?(?:php|=)?[\s\S]*?\?>/i',
            static function (array $m): string {
                // Keep literals (w-frame / data-layout-exempt) for nearby/exempt checks;
                // only neutralize ">" so it cannot truncate <img ...> matching.
                return str_replace('>', ' ', $m[0]);
            },
            $content
        );

        return is_string($out) ? $out : $content;
    }

    private function isExemptImgTag(string $tag): bool
    {
        return (bool)preg_match(
            '/\bw-icon\b|data-layout-exempt|aria-hidden\s*=\s*["\']true["\']'
            . '|police-icon|option-swatch|swatch--color|variant-swatch|__logo-img|logo-image/i',
            $tag
        );
    }

    private function isBusinessImgTag(string $tag): bool
    {
        if (preg_match('/\bsrc\s*=/i', $tag)) {
            return true;
        }

        // Account avatar etc. may omit src until hydrate; still need frame when width≥16.
        return (bool)preg_match('/\bwidth\s*=\s*["\']?(?:1[6-9]|[2-9]\d|\d{3,})\b/i', $tag);
    }

    private function hasNearbyFrameOpen(string $content, int $imgOffset): bool
    {
        $lookback = 500;
        $start = max(0, $imgOffset - $lookback);
        $near = substr($content, $start, $imgOffset - $start);

        return (bool)preg_match('/\bw-frame\b/i', $near);
    }

    private function isIncluded(string $rel): bool
    {
        $rel = str_replace('\\', '/', $rel);
        // Strip optional design: prefix used when scanning app/design.
        if (str_starts_with($rel, 'design:')) {
            $rel = substr($rel, strlen('design:'));
        }
        if (str_contains($rel, '/Backend/') || str_contains($rel, '/backend/')) {
            return false;
        }
        // Skip theme doc archives / 资料 snapshots (not live storefront sources).
        if (preg_match('#/(?:doc|资料|修复)/#u', $rel)) {
            return false;
        }
        // Skip compiled com_* / view/tpl mirrors — source templates are authoritative.
        if (str_contains($rel, '/view/tpl/') || preg_match('#/com_[^/]+\.phtml$#i', $rel)) {
            return false;
        }
        if (preg_match('#partials/product-card[^/]*\.phtml$#i', $rel)) {
            return true;
        }
        // Module: …/(templates|theme)/frontend/widgets/… ; design: …/frontend/widgets/…
        if (preg_match('#(?:^|/)(?:(?:templates|theme)/)?frontend/widgets/#i', $rel)) {
            return true;
        }
        if (preg_match('#/view/theme/frontend/widgets/#i', $rel)) {
            return true;
        }

        return false;
    }

    private function isCardPartial(string $rel): bool
    {
        $rel = str_replace('\\', '/', $rel);
        if (str_starts_with($rel, 'design:')) {
            $rel = substr($rel, strlen('design:'));
        }

        return (bool)preg_match('#partials/product-card[^/]*\.phtml$#i', $rel);
    }

    private function isFrontendWidget(string $rel): bool
    {
        $rel = str_replace('\\', '/', $rel);
        if (str_starts_with($rel, 'design:')) {
            $rel = substr($rel, strlen('design:'));
        }

        return (bool)preg_match('#(?:^|/)(?:(?:templates|theme)/)?frontend/widgets/#i', $rel)
            || (bool)preg_match('#/view/theme/frontend/widgets/#i', $rel);
    }

    private function lineOf(string $content, string $needle): int
    {
        $pos = stripos($content, $needle);
        if ($pos === false) {
            return 1;
        }

        return substr_count(substr($content, 0, $pos), "\n") + 1;
    }

    /**
     * @return list<string>
     */
    private function resolveScanRoots(?string $codeRoot): array
    {
        if ($codeRoot !== null && $codeRoot !== '') {
            return [rtrim($codeRoot, '/')];
        }
        $bp = defined('BP') ? (string)BP : (string)getcwd();

        return [
            rtrim($bp, '/') . '/app/code',
            rtrim($bp, '/') . '/app/design',
        ];
    }

    private function isDesignRoot(string $root): bool
    {
        $root = str_replace('\\', '/', rtrim($root, '/'));

        return str_ends_with($root, '/app/design') || str_ends_with($root, '/design');
    }

    private function toRelativePath(string $abs, string $root): string
    {
        $abs = str_replace('\\', '/', $abs);
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if (str_starts_with($abs, $root)) {
            return substr($abs, strlen($root));
        }

        return $abs;
    }
}
