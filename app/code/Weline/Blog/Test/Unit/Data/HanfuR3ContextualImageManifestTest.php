<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Data;

use PHPUnit\Framework\TestCase;

final class HanfuR3ContextualImageManifestTest extends TestCase
{
    /** @return array<string,array{slots:list<array<string,mixed>>}> */
    private function manifest(): array
    {
        $imageManifestPath = dirname(__DIR__, 3) . '/data/hanfu-r3-image-manifest.php';
        self::assertFileExists($imageManifestPath);
        require_once $imageManifestPath;

        $path = dirname(__DIR__, 3) . '/data/hanfu-r3-contextual-image-manifest.php';
        self::assertFileExists($path, 'The R3 contextual image manifest must exist before its contract can pass.');
        require_once $path;
        self::assertTrue(function_exists('hanfuR3ContextualImageManifest'));

        /** @var array<string,array{slots:list<array<string,mixed>>}> $manifest */
        $manifest = \hanfuR3ContextualImageManifest();
        return $manifest;
    }

    public function testManifestCoversEveryBaseTopicWithContextualSlots(): void
    {
        $manifest = $this->manifest();

        self::assertCount(160, $manifest);
        $expectedSlugs = array_keys(\hanfuR3ImageManifest());
        $actualSlugs = array_keys($manifest);
        sort($expectedSlugs, SORT_STRING);
        sort($actualSlugs, SORT_STRING);
        self::assertSame($expectedSlugs, $actualSlugs, 'The contextual manifest must cover the R3 cover manifest slug set exactly.');
        foreach ($manifest as $slug => $topic) {
            self::assertArrayHasKey('slots', $topic, $slug . ':slots');
            self::assertGreaterThanOrEqual($this->minimumSlots($slug), count($topic['slots']), $slug . ':minimum_slots');
            $visualRoles = array_column($topic['slots'], 'visual_role');
            self::assertCount(
                count($visualRoles),
                array_unique($visualRoles),
                $slug . ':unique_visual_roles',
            );
            foreach ($topic['slots'] as $slot) {
                self::assertContains($slot['visual_role'] ?? null, ['context', 'form', 'craft', 'care', 'evidence'], $slug . ':visual_role');
                self::assertGreaterThanOrEqual(1, $slot['anchor_h2'] ?? 0, $slug . ':anchor_h2');
                self::assertArrayHasKey('zh_Hans_CN', $slot['locale_copy'] ?? [], $slug . ':zh_Hans_CN');
                self::assertArrayHasKey('en_US', $slot['locale_copy'] ?? [], $slug . ':en_US');
                self::assertNotSame('', trim((string)($slot['locale_copy']['zh_Hans_CN'] ?? '')), $slug . ':zh_copy');
                self::assertNotSame('', trim((string)($slot['locale_copy']['en_US'] ?? '')), $slug . ':en_copy');
                self::assertNotSame('', trim((string)($slot['provenance'] ?? '')), $slug . ':provenance');
            }
        }
    }

    public function testRepresentativeTopicsHaveExplicitConservativeSemanticSlots(): void
    {
        $manifest = $this->manifest();
        $expectedRoles = [
            'hanfu-occasions-daily-wedding-festival' => ['context', 'form', 'craft'],
            'hanfu-styles-ruqun-mamian-yuanling' => ['context', 'form', 'evidence'],
            'hanfu-through-dynasties-tang-song-ming' => ['context', 'form', 'evidence'],
            'hanfu-fabrics-embroidery-green-manufacturing' => ['context', 'craft', 'evidence'],
            'hanfu-size-chart-care-guide' => ['context', 'care', 'evidence'],
            'hanfu-styling-complete-guide' => ['context', 'form', 'care'],
            'ethnic-blang-dress-overview' => ['context', 'form', 'evidence'],
            'ethnic-mongol-dress-overview' => ['context', 'form', 'evidence'],
        ];

        foreach ($expectedRoles as $slug => $roles) {
            self::assertArrayHasKey($slug, $manifest);
            self::assertSame($roles, array_column($manifest[$slug]['slots'], 'visual_role'), $slug . ':explicit_roles');
            foreach ($manifest[$slug]['slots'] as $slot) {
                self::assertStringContainsString('not', strtolower((string)$slot['provenance']), $slug . ':conservative_provenance');
            }
        }
    }

    public function testEveryTopicUsesVisuallyDistinctContextualSlots(): void
    {
        $root = dirname(__DIR__, 7);
        $violations = [];

        foreach ($this->manifest() as $slug => $topic) {
            $hashes = [];
            foreach ($topic['slots'] as $slot) {
                $role = (string)($slot['visual_role'] ?? '');
                $path = $root . '/var/hanfu-production/final/blog-inline/' . $slug . '/' . $role . '.webp';
                self::assertFileExists($path, $slug . '/' . $role);
                $hashes[$role] = $this->dHash($path);
            }

            $roles = array_keys($hashes);
            $count = count($roles);
            for ($left = 0; $left < $count; ++$left) {
                for ($right = $left + 1; $right < $count; ++$right) {
                    $distance = $this->hammingDistance($hashes[$roles[$left]], $hashes[$roles[$right]]);
                    if ($distance <= 16) {
                        $violations[] = sprintf(
                            '%s %s/%s = %d',
                            $slug,
                            $roles[$left],
                            $roles[$right],
                            $distance,
                        );
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Contextual slots within one article must not be alternate crops of the same mother image:\n"
                . implode("\n", $violations),
        );
    }

    public function testContextualReplacementCleanupAllowlistIsExactAndExcludesCurrentAssets(): void
    {
        $path = dirname(__DIR__, 3) . '/data/hanfu-r3-contextual-replacement-allowlist.php';
        self::assertFileExists($path);
        require_once $path;
        self::assertTrue(function_exists('hanfuR3ContextualReplacementAllowlist'));

        $expected = [
            'blog/hanfu/r3/inline/doresuwe-costume-hanfu-formal/evidence-bcf9540f0882.webp',
            'blog/hanfu/r3/inline/east-meets-dress-chinese-wedding/care-5f24f2b0fa8c.webp',
            'blog/hanfu/r3/inline/ethnic-han-dress-overview/evidence-d9bf14fd4a7f.webp',
            'blog/hanfu/r3/inline/ethnic-hani-occasion-craft/evidence-ce5b783a76d2.webp',
            'blog/hanfu/r3/inline/ethnic-russian-occasion-craft/form-0945e519249c.webp',
            'blog/hanfu/r3/inline/hanfu-styling-complete-guide/care-928e610a2e11.webp',
            'blog/hanfu/r3/inline/hanfu-wedding-festival-styling/care-05afb2960534.webp',
            'blog/hanfu/r3/inline/shein-new-chinese-style-hanfu/evidence-02de98b39fee.webp',
        ];
        self::assertSame($expected, \hanfuR3ContextualReplacementAllowlist());

        $provenance = json_decode(
            (string)file_get_contents(dirname(__DIR__, 7) . '/var/hanfu-production/final/blog-inline/provenance.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $current = [];
        foreach ($provenance as $sha => $record) {
            $current[] = 'blog/hanfu/r3/inline/' . $record['base_slug'] . '/' . $record['visual_role']
                . '-' . substr((string)$sha, 0, 12) . '.webp';
        }
        self::assertSame([], array_values(array_intersect($expected, $current)));
    }

    private function minimumSlots(string $slug): int
    {
        if (in_array($slug, $this->ethnicTopicSlugs(), true)) {
            return 3;
        }

        $profile = \hanfuR3ImageCoreTopics()[$slug] ?? [];
        $elevatedThemes = [
            'occasion_styling',
            'garment_form_history',
            'fabric_craft_sizing_care',
        ];

        return in_array($profile['editorial_role'] ?? null, $elevatedThemes, true) ? 3 : 2;
    }

    /** @return list<string> */
    private function ethnicTopicSlugs(): array
    {
        $profiles = require dirname(__DIR__, 3) . '/data/china-ethnic-groups.php';
        $slugs = [];
        foreach ($profiles as $profile) {
            foreach (['dress-overview', 'occasion-craft'] as $variant) {
                $slugs[] = 'ethnic-' . $profile['code'] . '-' . $variant;
            }
        }

        return $slugs;
    }

    private function dHash(string $path): string
    {
        $source = imagecreatefromwebp($path);
        self::assertNotFalse($source, $path);
        $sample = imagecreatetruecolor(9, 8);
        imagecopyresampled(
            $sample,
            $source,
            0,
            0,
            0,
            0,
            9,
            8,
            imagesx($source),
            imagesy($source),
        );
        imagedestroy($source);

        $bits = '';
        for ($y = 0; $y < 8; ++$y) {
            for ($x = 0; $x < 8; ++$x) {
                $bits .= $this->luminance(imagecolorat($sample, $x, $y))
                    > $this->luminance(imagecolorat($sample, $x + 1, $y)) ? '1' : '0';
            }
        }
        imagedestroy($sample);

        $hex = '';
        for ($offset = 0; $offset < 64; $offset += 4) {
            $hex .= dechex(bindec(substr($bits, $offset, 4)));
        }
        return $hex;
    }

    private function luminance(int $color): int
    {
        $red = ($color >> 16) & 0xff;
        $green = ($color >> 8) & 0xff;
        $blue = $color & 0xff;
        return (299 * $red) + (587 * $green) + (114 * $blue);
    }

    private function hammingDistance(string $left, string $right): int
    {
        static $bitCounts = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
        $distance = 0;
        for ($index = 0; $index < 16; ++$index) {
            $distance += $bitCounts[hexdec($left[$index]) ^ hexdec($right[$index])];
        }
        return $distance;
    }
}
