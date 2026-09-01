<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);

/**
 * Contract + bootstrap-backed checks (app/code Theme overrides stale vendor/weline/module-theme).
 */
final class AppearanceTokenGroupMetaTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = BP . ltrim(str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $relative), \DIRECTORY_SEPARATOR);
        $content = \is_file($path) ? \file_get_contents($path) : false;
        self::assertNotFalse($content, $relative);

        return (string)$content;
    }

    public function testHelperAndParserSourceWireMetaGroupKeys(): void
    {
        $helper = $this->read('app/code/Weline/Theme/Helper/AppearanceTokenGroupMeta.php');
        $parser = $this->read('app/code/Weline/Theme/Helper/CssVariableParser.php');
        $catalog = $this->read('app/code/Weline/Theme/Service/Disk/ThemeTokenCatalogService.php');
        $appearance = $this->read('app/code/Weline/Theme/view/statics/js/theme-disk-appearance.js');

        self::assertStringContainsString('DictionaryEvents::register', $helper);
        self::assertStringContainsString('appearance.token_group.', $helper);
        self::assertStringContainsString('MetaTranslation::getTranslatedValue', $helper);
        self::assertStringContainsString('parseSectionHeading', $helper);
        self::assertStringContainsString('AppearanceTokenGroupMeta::parseSectionHeading', $parser);
        self::assertStringContainsString("'category_id'", $parser);
        self::assertStringContainsString('AppearanceTokenGroupMeta::registerGroupsFromTokens', $catalog);
        self::assertStringContainsString('AppearanceTokenGroupMeta::enrichTokensWithLabels', $catalog);
        self::assertStringContainsString('resolveAppearanceTokenGroup', $appearance);
        self::assertStringContainsString('category_label', $appearance);
        self::assertStringContainsString('tokenMeta', $appearance);
    }

    public function testBootstrapParserAttachesCategoryIdFromSectionComments(): void
    {
        $runner = \sys_get_temp_dir() . '/weline-appearance-group-runner-' . \uniqid('', true) . '.php';
        $bootstrap = BP . 'app/bootstrap.php';
        $code = <<<'PHP'
<?php
require $argv[1];
use Weline\Theme\Helper\AppearanceTokenGroupMeta;
use Weline\Theme\Helper\CssVariableParser;
$temp = sys_get_temp_dir() . '/weline-appearance-group-' . uniqid('', true) . '.css';
$css = ":root {\n"
    . "    /* ========== 品牌色 #brand ========== */\n"
    . "    --color-primary: #ff9900;\n"
    . "    /* ========== 文本色 ========== */\n"
    . "    --color-text-primary: #111111;\n"
    . "}\n";
file_put_contents($temp, $css);
try {
    $variables = CssVariableParser::parseFile($temp);
    $withId = AppearanceTokenGroupMeta::parseSectionHeading('品牌色 #brand');
    echo json_encode([
        'count' => count($variables),
        'c0' => $variables[0]['category'] ?? null,
        'id0' => $variables[0]['category_id'] ?? null,
        'c1' => $variables[1]['category'] ?? null,
        'id1' => $variables[1]['category_id'] ?? null,
        'parse_label' => $withId['label'],
        'parse_id' => $withId['id'],
        'hash_text' => AppearanceTokenGroupMeta::hashGroupId('文本色'),
        'meta' => AppearanceTokenGroupMeta::metaKey('frontend', 'brand'),
        'word' => AppearanceTokenGroupMeta::dictionaryWord('backend', 'brand'),
    ], JSON_UNESCAPED_UNICODE);
} finally {
    @unlink($temp);
}
PHP;
        \file_put_contents($runner, $code);
        try {
            $cmd = [\PHP_BINARY, $runner, $bootstrap];
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = \proc_open($cmd, $descriptors, $pipes, BP);
            self::assertIsResource($proc);
            $stdout = \stream_get_contents($pipes[1]);
            $stderr = \stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                \fclose($pipe);
            }
            $exit = \proc_close($proc);
            self::assertSame(0, $exit, $stderr . "\n" . $stdout);
            $data = \json_decode((string)$stdout, true);
            self::assertIsArray($data);
            self::assertSame(2, $data['count']);
            self::assertSame('品牌色', $data['c0']);
            self::assertSame('brand', $data['id0']);
            self::assertSame('文本色', $data['c1']);
            self::assertSame($data['hash_text'], $data['id1']);
            self::assertSame('品牌色', $data['parse_label']);
            self::assertSame('brand', $data['parse_id']);
            self::assertSame('theme.frontend.appearance.token_group.brand.name', $data['meta']);
            self::assertSame('@meta::theme.backend.appearance.token_group.brand.name', $data['word']);
        } finally {
            @\unlink($runner);
        }
    }
}