<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Data;

use PHPUnit\Framework\TestCase;

final class HanfuR3ImageManifestTest extends TestCase
{
    private const REPLACEMENTS = [
        'hanfu-styles-ruqun-mamian-yuanling' => [
            'image_path' => 'var/hanfu-production/final/blog-core/hanfu-styles-ruqun-mamian-yuanling.webp',
            'generation_source' => '.superpowers/sdd/2026-09-03-hanfu-blog-editorial-remediation/generated/forms-guide-cover.webp',
            'sha256' => '3d9726db3770da9ea2fb8929b7a70b5e433f4c988b4e764da1be9eeed21b16ee',
            'old_object_key' => 'blog/hanfu/r2/covers/core/hanfu-styles-ruqun-mamian-yuanling-2ed07e6cb2d6.webp',
        ],
        'choose-first-mamian-or-ruqun' => [
            'image_path' => 'var/hanfu-production/final/blog-core/choose-first-mamian-or-ruqun.webp',
            'generation_source' => '.superpowers/sdd/2026-09-03-hanfu-blog-editorial-remediation/generated/mamian-vs-ruqun-cover.webp',
            'sha256' => 'f1f70ad4df1f61e114044cb9634227ace866453b31c099b4fbd5ef1d4401695d',
            'old_object_key' => 'blog/hanfu/r2/covers/core/choose-first-mamian-or-ruqun-01907a1bee4d.webp',
        ],
        'hanfu-styling-complete-guide' => [
            'image_path' => 'var/hanfu-production/final/blog-core/hanfu-styling-complete-guide.webp',
            'generation_source' => '.superpowers/sdd/2026-09-03-hanfu-blog-editorial-remediation/generated/styling-guide-cover.webp',
            'sha256' => '24e3f964a8175e3548a0674e6d4c907ae5134e59c42ce52c2d1c01636ff05f96',
            'old_object_key' => 'blog/hanfu/r2/covers/core/hanfu-styling-complete-guide-6da1159389d7.webp',
        ],
        'ethnic-shui-dress-overview' => [
            'image_path' => 'var/hanfu-production/final/blog-ethnic/shui-overview.webp',
            'generation_source' => '.superpowers/sdd/2026-09-03-hanfu-blog-editorial-remediation/generated/shui-overview-cover.webp',
            'sha256' => 'b391920c44a99920b57c0606f0293606578ccd2772f908d61b47d6f85e1e3ce2',
            'old_object_key' => 'blog/hanfu/r2/covers/ethnic/shui-overview-1802aa2cee55.webp',
        ],
    ];

    /** @return array<string,array<string,mixed>> */
    private function manifest(): array
    {
        $path = dirname(__DIR__, 3) . '/data/hanfu-r3-image-manifest.php';
        self::assertFileExists($path, 'The R3 image manifest must exist before its contract can pass.');
        require_once $path;
        self::assertTrue(function_exists('hanfuR3ImageManifest'));

        /** @var array<string,array<string,mixed>> $manifest */
        $manifest = \hanfuR3ImageManifest();
        return $manifest;
    }

    /** @return list<string> */
    private function expectedTopicSlugs(): array
    {
        $root = dirname(__DIR__, 7);
        $slugs = [];
        foreach ((array)glob($root . '/var/hanfu-production/final/blog-core/*.webp') as $path) {
            $slugs[] = basename((string)$path, '.webp');
        }
        foreach ((array)glob($root . '/var/hanfu-production/final/blog-ethnic/*.webp') as $path) {
            self::assertSame(1, preg_match('/^([a-z0-9-]+)-(overview|occasion)\.webp$/D', basename((string)$path), $matches));
            $slugs[] = 'ethnic-' . $matches[1] . '-' . ($matches[2] === 'overview' ? 'dress-overview' : 'occasion-craft');
        }
        sort($slugs, SORT_STRING);
        return $slugs;
    }

    public function testManifestCoversEverySourceImageExactlyOnce(): void
    {
        $manifest = $this->manifest();
        $actual = array_keys($manifest);
        sort($actual, SORT_STRING);

        self::assertCount(160, $manifest);
        self::assertSame($this->expectedTopicSlugs(), $actual);
    }

    public function testEveryRowHasReviewedProvenanceAndManualBilingualMetadata(): void
    {
        $requiredFields = [
            'image_path',
            'garment_form',
            'required_visible_features',
            'visible_features',
            'forbidden_features',
            'source_type',
            'source',
            'license',
            'purpose',
            'relations',
            'created_for',
            'reviewer',
            'reviewed_at',
            'review_scope',
            'review_limitations',
            'review_method',
            'review_evidence',
            'research_references',
            'locales',
        ];
        $requiredBoundaries = [
            'object_level_evidence',
            'subgroup_identity',
            'ritual_rank',
            'dynasty_attribution',
            'fibre_identification',
            'handmade_proof',
        ];

        foreach ($this->manifest() as $slug => $row) {
            foreach ($requiredFields as $field) {
                self::assertArrayHasKey($field, $row, $slug . ':' . $field);
                self::assertNotEmpty($row[$field], $slug . ':' . $field);
            }
            self::assertSame('original_ai_generation', $row['source_type'], $slug . ':source_type');
            self::assertStringContainsString('OpenAI ImageGen', (string)$row['source'], $slug . ':source');
            self::assertStringNotContainsString('http://', (string)$row['license'], $slug . ':license');
            self::assertStringNotContainsString('https://', (string)$row['license'], $slug . ':license');
            self::assertSame($requiredBoundaries, array_values($row['forbidden_features']), $slug . ':boundaries');
            self::assertMatchesRegularExpression('/^2026-09-0[34]$/D', (string)$row['reviewed_at'], $slug . ':reviewed_at');
            self::assertStringContainsString('Codex', (string)$row['reviewer'], $slug . ':reviewer');
            self::assertStringContainsString('dHash', (string)$row['review_method'], $slug . ':review_method');
            self::assertStringContainsString('not historical or cultural authentication', (string)$row['review_method'], $slug . ':review_method_boundary');
            self::assertStringContainsString('does not authenticate', (string)$row['review_limitations'], $slug . ':review_limitations');
            self::assertGreaterThanOrEqual(3, count($row['review_evidence']), $slug . ':review_evidence');
            foreach ($row['research_references'] as $url) {
                self::assertMatchesRegularExpression('#^https://#D', (string)$url, $slug . ':research_reference');
            }
            foreach (['zh_Hans_CN', 'en_US'] as $locale) {
                self::assertArrayHasKey($locale, $row['locales'], $slug . ':' . $locale);
                foreach (['display_name', 'default_alt', 'description', 'default_caption'] as $field) {
                    self::assertNotSame('', trim((string)($row['locales'][$locale][$field] ?? '')), $slug . ':' . $locale . ':' . $field);
                }
                self::assertSame('reviewed', $row['locales'][$locale]['translation_state'] ?? null, $slug . ':' . $locale . ':state');
                self::assertSame('manual', $row['locales'][$locale]['translation_origin'] ?? null, $slug . ':' . $locale . ':origin');
            }
        }
    }

    public function testOnlyFrozenReplacementRowsCarryExactGenerationProvenance(): void
    {
        $manifest = $this->manifest();
        self::assertTrue(function_exists('hanfuR3ReplacedObjectAllowlist'));

        $actualReplacementRows = [];
        foreach ($manifest as $slug => $row) {
            if (isset($row['relations']['replaces_object_key'])) {
                $actualReplacementRows[$slug] = $row['relations']['replaces_object_key'];
            }
        }
        $expectedReplacementRows = [];
        foreach (self::REPLACEMENTS as $slug => $record) {
            $expectedReplacementRows[$slug] = $record['old_object_key'];
        }
        ksort($expectedReplacementRows, SORT_STRING);
        self::assertSame($expectedReplacementRows, $actualReplacementRows);

        foreach (self::REPLACEMENTS as $slug => $expected) {
            self::assertArrayHasKey($slug, $manifest);
            $row = $manifest[$slug];
            self::assertSame($expected['image_path'], $row['image_path'], $slug . ':image_path');
            self::assertSame($expected['sha256'], hash_file('sha256', dirname(__DIR__, 7) . '/' . $row['image_path']), $slug . ':sha256');
            self::assertStringContainsString($expected['generation_source'], (string)$row['source'], $slug . ':generation_source');
            self::assertStringContainsString($expected['sha256'], (string)$row['source'], $slug . ':generation_sha');
            self::assertSame($expected['old_object_key'], $row['relations']['replaces_object_key'] ?? null, $slug . ':old_object_key');
            self::assertGreaterThanOrEqual(2, count($row['research_references']), $slug . ':research_references');
        }

        self::assertSame(
            array_column(self::REPLACEMENTS, 'old_object_key'),
            \hanfuR3ReplacedObjectAllowlist(),
        );
    }

    public function testReplacementVisibleFeaturesStayWithinReviewedVisualNotes(): void
    {
        $manifest = $this->manifest();
        self::assertStringContainsString('round-collar robe', $manifest['hanfu-styles-ruqun-mamian-yuanling']['garment_form']);
        self::assertStringContainsString('standalone mamian skirt', $manifest['choose-first-mamian-or-ruqun']['garment_form']);
        self::assertStringContainsString('complete ruqun', $manifest['choose-first-mamian-or-ruqun']['garment_form']);
        self::assertStringContainsString('cross-collar upper-and-lower ensemble', $manifest['hanfu-styling-complete-guide']['garment_form']);
        self::assertStringContainsString('horsehair-embroidery technique detail', $manifest['ethnic-shui-dress-overview']['garment_form']);
        $forms = implode(' ', $manifest['hanfu-styles-ruqun-mamian-yuanling']['visible_features']);
        self::assertStringContainsString('right-closing cross-collar', $forms);
        self::assertStringContainsString('flat central door', $forms);
        self::assertStringContainsString('side pleats', $forms);
        self::assertStringContainsString('round-collar robe', $forms);
        self::assertStringContainsString("wearer's right", $forms);

        $comparison = implode(' ', $manifest['choose-first-mamian-or-ruqun']['visible_features']);
        self::assertStringContainsString('standalone mamian skirt', $comparison);
        self::assertStringContainsString('complete ruqun', $comparison);
        self::assertStringContainsString('traditional blouse', $comparison);
        self::assertStringNotContainsString('modern shirt', strtolower($comparison));

        $styling = implode(' ', $manifest['hanfu-styling-complete-guide']['visible_features']);
        foreach (['cross-collar', 'waist ties', 'shoes', 'belt', 'hair ornament', 'measuring tape'] as $cue) {
            self::assertStringContainsString($cue, $styling);
        }

        $shui = $manifest['ethnic-shui-dress-overview'];
        $shuiText = implode(' ', $shui['visible_features']) . ' '
            . $shui['locales']['en_US']['description'] . ' '
            . $shui['locales']['en_US']['default_caption'];
        foreach (['indigo ground', 'silk-wrapped horsehair cord', 'coloured braided fill', 'hand stitching', 'sequins'] as $cue) {
            self::assertStringContainsString($cue, $shuiText);
        }
        self::assertStringContainsString('AI-created editorial illustration', $shuiText);
        self::assertStringContainsString('not a photographed museum object', $shuiText);
        self::assertStringContainsString('not field evidence', $shuiText);
        self::assertContains('https://www.ihchina.cn/project_details/13992/', $shui['research_references']);
    }

    public function testManifestProjectsCompleteFileManagerPayloadsWithoutGenericTitleCopy(): void
    {
        $manifest = $this->manifest();
        self::assertTrue(function_exists('hanfuR3ImageAssetMetadata'));

        foreach ($manifest as $slug => $row) {
            $metadata = \hanfuR3ImageAssetMetadata($row);
            foreach ([
                'garment_form',
                'required_visible_features',
                'visible_features',
                'forbidden_features',
                'source_type',
                'source',
                'license',
                'purpose',
                'relations',
                'created_for',
                'reviewer',
                'reviewed_at',
                'review_scope',
                'review_limitations',
                'review_method',
                'review_evidence',
                'research_references',
                'review',
            ] as $field) {
                self::assertArrayHasKey($field, $metadata, $slug . ':' . $field);
                self::assertNotEmpty($metadata[$field], $slug . ':' . $field);
            }
            self::assertSame('contact_sheet_screened', $metadata['review']['state'] ?? null, $slug . ':review_state');
            self::assertSame($row['review_scope'], $metadata['review']['scope'] ?? null, $slug . ':review_scope_projection');
            self::assertSame($row['review_limitations'], $metadata['review']['limitations'] ?? null, $slug . ':review_limitations_projection');
            self::assertSame($row['review_method'], $metadata['review']['method'] ?? null, $slug . ':review_method_projection');
            self::assertSame($row['review_evidence'], $metadata['review']['evidence'] ?? null, $slug . ':review_evidence_projection');
            self::assertSame($row['source'], $metadata['source'], $slug . ':source_projection');
            self::assertSame($row['research_references'], $metadata['research_references'], $slug . ':research_projection');
        }
    }

    public function testEveryTopicImageIsWideAndVisuallyDistinctAcrossWholeAndPanels(): void
    {
        $manifest = $this->manifest();
        $slugs = array_keys($manifest);
        sort($slugs, SORT_STRING);
        $shaOwners = [];
        $hashes = [];
        $violations = [];
        foreach ($slugs as $slug) {
            $path = dirname(__DIR__, 7) . '/' . $manifest[$slug]['image_path'];
            self::assertFileExists($path, $slug);
            self::assertSame([1600, 900], array_slice((array)getimagesize($path), 0, 2), $slug . ':dimensions');
            $sha = (string)hash_file('sha256', $path);
            if (isset($shaOwners[$sha])) {
                $violations[] = 'sha256 ' . $shaOwners[$sha] . ' <> ' . $slug . ' = ' . $sha;
            }
            $shaOwners[$sha] = $slug;
            foreach (['whole', 'left', 'right', 'top', 'bottom'] as $panel) {
                $hashes[$slug][$panel] = $this->dHash($path, $panel);
            }
        }

        $count = count($slugs);
        for ($left = 0; $left < $count; ++$left) {
            for ($right = $left + 1; $right < $count; ++$right) {
                $a = $slugs[$left];
                $b = $slugs[$right];
                $wholeDistance = $this->hammingDistance($hashes[$a]['whole'], $hashes[$b]['whole']);
                if ($wholeDistance < 9) {
                    $violations[] = 'dhash whole ' . $a . ' <> ' . $b . ' = ' . $wholeDistance;
                }
                foreach (['left', 'right', 'top', 'bottom'] as $panelA) {
                    foreach (['left', 'right', 'top', 'bottom'] as $panelB) {
                        $panelDistance = $this->hammingDistance($hashes[$a][$panelA], $hashes[$b][$panelB]);
                        if ($panelDistance <= 5) {
                            $violations[] = 'dhash ' . $panelA . '/' . $panelB . ' '
                                . $a . ' <> ' . $b . ' = ' . $panelDistance;
                        }
                    }
                }
            }
        }
        self::assertSame([], $violations, "Deterministic visual-integrity audit:\n" . implode("\n", $violations));
    }

    public function testShuiReplacementRemovesKnownGelaoLeftPanelCollision(): void
    {
        $manifest = $this->manifest();
        $root = dirname(__DIR__, 7) . '/';
        $shui = $root . $manifest['ethnic-shui-dress-overview']['image_path'];
        $gelao = $root . $manifest['ethnic-gelao-dress-overview']['image_path'];

        self::assertGreaterThan(
            5,
            $this->hammingDistance($this->dHash($shui, 'left'), $this->dHash($gelao, 'left')),
            'The R2 Shui and Gelao overview covers reused a near-identical left panel.',
        );
    }

    private function dHash(string $path, string $panel): string
    {
        static $cache = [];
        $cacheKey = $path . '|' . $panel;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $source = imagecreatefromwebp($path);
        self::assertNotFalse($source, $path);
        $width = imagesx($source);
        $height = imagesy($source);
        [$sourceX, $sourceY, $sourceWidth, $sourceHeight] = match ($panel) {
            'whole' => [0, 0, $width, $height],
            'left' => [0, 0, intdiv($width, 2), $height],
            'right' => [intdiv($width, 2), 0, $width - intdiv($width, 2), $height],
            'top' => [0, 0, $width, intdiv($height, 2)],
            'bottom' => [0, intdiv($height, 2), $width, $height - intdiv($height, 2)],
            default => throw new \InvalidArgumentException('Unknown panel: ' . $panel),
        };
        $sample = imagecreatetruecolor(9, 8);
        imagecopyresampled($sample, $source, 0, 0, $sourceX, $sourceY, 9, 8, $sourceWidth, $sourceHeight);
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
        return $cache[$cacheKey] = $hex;
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
