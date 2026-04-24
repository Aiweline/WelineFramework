<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteAntiHardcodeScanService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {@see AiSiteAntiHardcodeScanService} (T31 MVP).
 *
 * 每条规则至少 1 正 1 反 + rules() 元数据锁定 + scanPaths() 汇总。
 */
final class AiSiteAntiHardcodeScanServiceTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (\is_file($path)) {
                @\unlink($path);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Metadata lock
    // ------------------------------------------------------------------

    public function testRulesMetadataIsStable(): void
    {
        $service = new AiSiteAntiHardcodeScanService();
        $rules   = $service->rules();

        $ids = \array_column($rules, 'rule_id');
        self::assertSame(
            ['AHC001', 'AHC002', 'AHC003', 'AHC004', 'AHC005', 'AHC006', 'AHC007', 'AHC008'],
            $ids,
            'rule_id 顺序与集合必须稳定。'
        );

        $severityMap = [];
        foreach ($rules as $rule) {
            self::assertArrayHasKey('rule_id', $rule);
            self::assertArrayHasKey('severity', $rule);
            self::assertArrayHasKey('description', $rule);
            self::assertArrayHasKey('pattern_kind', $rule);
            self::assertContains($rule['severity'], ['error', 'warning', 'info']);
            $severityMap[$rule['rule_id']] = $rule['severity'];
        }

        self::assertSame(
            [
                'AHC001' => 'error',
                'AHC002' => 'error',
                'AHC003' => 'error',
                'AHC004' => 'warning',
                'AHC005' => 'warning',
                'AHC006' => 'error',
                'AHC007' => 'warning',
                'AHC008' => 'info',
            ],
            $severityMap
        );
    }

    // ------------------------------------------------------------------
    // AHC001 — phtml 中文文本节点
    // ------------------------------------------------------------------

    public function testAhc001FlagsHardcodedChineseInPhtml(): void
    {
        $path = $this->writeTmp('ahc001-bad.phtml', <<<'PHTML'
<div class="title">这里是中文硬编码</div>
PHTML);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertTrue(
            $this->hasViolation($violations, 'AHC001'),
            'phtml 裸中文应命中 AHC001'
        );
    }

    public function testAhc001SkipsWhenWrappedInTranslate(): void
    {
        $path = $this->writeTmp('ahc001-good.phtml', <<<'PHTML'
<div class="title"><?= __('这里是中文') ?></div>
PHTML);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertFalse(
            $this->hasViolation($violations, 'AHC001'),
            '__("...") 包裹的中文不应命中 AHC001'
        );
    }

    // ------------------------------------------------------------------
    // AHC002 — script 内中文字面量
    // ------------------------------------------------------------------

    public function testAhc002FlagsChineseLiteralInScriptBlock(): void
    {
        $path = $this->writeTmp('ahc002-bad.phtml', <<<'PHTML'
<div>hi</div>
<script>
var tip = "请先选择页面";
</script>
PHTML);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertTrue(
            $this->hasViolation($violations, 'AHC002'),
            'script 内中文字符串字面量应命中 AHC002'
        );
    }

    public function testAhc002AcceptsJsonEncodedTranslation(): void
    {
        $path = $this->writeTmp('ahc002-good.phtml', <<<'PHTML'
<script>
var tip = <?= json_encode((string)__('请先选择页面')) ?>;
</script>
PHTML);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertFalse(
            $this->hasViolation($violations, 'AHC002'),
            'json_encode((string)__()) 包裹的内容不应命中 AHC002'
        );
    }

    public function testAhc002IgnoresMatcherSourceMarker(): void
    {
        $path = $this->writeTmp('ahc002-matcher-source.phtml', <<<'PHTML'
<script>
// i18n: matcher-source
var stopWords = ['请填写', '仅说明方向'];
</script>
PHTML);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertFalse(
            $this->hasViolation($violations, 'AHC002'),
            'matcher-source 标注的规则词库不应命中 AHC002'
        );
    }

    public function testAhc002IgnoresMatchWithBackendMarker(): void
    {
        $path = $this->writeTmp('ahc002-match-with-backend.phtml', <<<'PHTML'
<script>
// i18n: match-with-backend
var waiting = text.indexOf('正在生成阶段一方案') >= 0;
</script>
PHTML);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertFalse(
            $this->hasViolation($violations, 'AHC002'),
            'match-with-backend 标注的匹配文案不应命中 AHC002'
        );
    }

    public function testAhc002IgnoresDocblockCommentChinese(): void
    {
        $path = $this->writeTmp('ahc002-docblock-comment.phtml', <<<'PHTML'
<script>
/**
 * 统一 stage1/stage2 的四类意图入口
 */
var status = "ok";
</script>
PHTML);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertFalse(
            $this->hasViolation($violations, 'AHC002'),
            'script 内 docblock 注释行不应命中 AHC002'
        );
    }

    // ------------------------------------------------------------------
    // AHC003 — window.alert/confirm/prompt
    // ------------------------------------------------------------------

    public function testAhc003FlagsWindowAlert(): void
    {
        $path = $this->writeTmp('ahc003-bad.js', <<<'JS'
function warn() {
    window.alert('oops');
}
JS);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertTrue($this->hasViolation($violations, 'AHC003'));
    }

    public function testAhc003IgnoresBackendToast(): void
    {
        $path = $this->writeTmp('ahc003-good.js', <<<'JS'
function warn() {
    BackendToast.show('ok');
}
JS);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertFalse($this->hasViolation($violations, 'AHC003'));
    }

    // ------------------------------------------------------------------
    // AHC004 — 裸 URL
    // ------------------------------------------------------------------

    public function testAhc004FlagsBareUrlInPhtml(): void
    {
        $path = $this->writeTmp('ahc004-bad.phtml', <<<'PHTML'
<a href="https://example.com/login">login</a>
PHTML);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertTrue($this->hasViolation($violations, 'AHC004'));
    }

    public function testAhc004AcceptsGetUrlHelper(): void
    {
        $path = $this->writeTmp('ahc004-good.phtml', <<<'PHTML'
<a href="<?= $block->getUrl('customer/account/login') ?>">login</a>
PHTML);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertFalse($this->hasViolation($violations, 'AHC004'));
    }

    // ------------------------------------------------------------------
    // AHC005 — 裸 hex 颜色
    // ------------------------------------------------------------------

    public function testAhc005FlagsInlineStyleHexColor(): void
    {
        $path = $this->writeTmp('ahc005-bad.phtml', <<<'PHTML'
<div style="color:#ff8800;">x</div>
PHTML);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertTrue($this->hasViolation($violations, 'AHC005'));
    }

    public function testAhc005AllowsVarFallback(): void
    {
        $path = $this->writeTmp('ahc005-good.phtml', <<<'PHTML'
<div style="color: var(--pb-ai-accent, #ff8800);">x</div>
PHTML);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertFalse($this->hasViolation($violations, 'AHC005'));
    }

    // ------------------------------------------------------------------
    // AHC006 — PHP throw literal
    // ------------------------------------------------------------------

    public function testAhc006FlagsLiteralException(): void
    {
        $path = $this->writeTmp('ahc006-bad.php', <<<'PHP'
<?php
throw new \RuntimeException('boom');
PHP);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertTrue($this->hasViolation($violations, 'AHC006'));
    }

    public function testAhc006AcceptsTranslatedException(): void
    {
        $path = $this->writeTmp('ahc006-good.php', <<<'PHP'
<?php
throw new \RuntimeException(__('boom'));
PHP);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertFalse($this->hasViolation($violations, 'AHC006'));
    }

    // ------------------------------------------------------------------
    // AHC007 — console 中文调试
    // ------------------------------------------------------------------

    public function testAhc007FlagsChineseConsoleDebug(): void
    {
        $path = $this->writeTmp('ahc007-bad.js', <<<'JS'
function demo() {
    console.log('调试信息', x);
}
JS);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertTrue($this->hasViolation($violations, 'AHC007'));
    }

    public function testAhc007IgnoresEnglishConsole(): void
    {
        $path = $this->writeTmp('ahc007-good.js', <<<'JS'
function demo() {
    console.log('debug info', x);
}
JS);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertFalse($this->hasViolation($violations, 'AHC007'));
    }

    // ------------------------------------------------------------------
    // AHC008 — phtml declare(strict_types=1)
    // ------------------------------------------------------------------

    public function testAhc008FlagsStrictTypesInPhtml(): void
    {
        $path = $this->writeTmp('ahc008-bad.phtml', <<<'PHTML'
<?php declare(strict_types=1); ?>
<div>hi</div>
PHTML);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertTrue($this->hasViolation($violations, 'AHC008'));
    }

    public function testAhc008IgnoresStrictTypesInPhpFile(): void
    {
        $path = $this->writeTmp('ahc008-good.php', <<<'PHP'
<?php
declare(strict_types=1);
class Foo {}
PHP);

        $violations = (new AiSiteAntiHardcodeScanService())->scanFile($path);

        self::assertFalse(
            $this->hasViolation($violations, 'AHC008'),
            '.php 文件中的 declare(strict_types=1) 是合规的，不应命中 AHC008'
        );
    }

    // ------------------------------------------------------------------
    // scanPaths — 汇总结构
    // ------------------------------------------------------------------

    public function testScanPathsAggregatesTotalsAndFiles(): void
    {
        $badPhtml = $this->writeTmp('bundle-bad.phtml', <<<'PHTML'
<div>硬编码标题</div>
<a href="https://example.com/raw">raw</a>
PHTML);

        $badJs = $this->writeTmp('bundle-bad.js', <<<'JS'
window.alert('stop');
JS);

        $goodPhtml = $this->writeTmp('bundle-good.phtml', <<<'PHTML'
<div><?= __('已国际化') ?></div>
PHTML);

        $summary = (new AiSiteAntiHardcodeScanService())->scanPaths([$badPhtml, $badJs, $goodPhtml]);

        self::assertArrayHasKey('files', $summary);
        self::assertArrayHasKey('totals', $summary);
        self::assertArrayHasKey('violations', $summary);

        self::assertGreaterThanOrEqual(1, $summary['totals']['AHC001'] ?? 0);
        self::assertGreaterThanOrEqual(1, $summary['totals']['AHC003'] ?? 0);
        self::assertGreaterThanOrEqual(1, $summary['totals']['AHC004'] ?? 0);

        self::assertArrayHasKey($badPhtml, $summary['files']);
        self::assertArrayHasKey($badJs, $summary['files']);
        self::assertArrayHasKey($goodPhtml, $summary['files']);
        self::assertNull($summary['files'][$goodPhtml]['skipped_reason']);
        self::assertSame(0, $summary['files'][$goodPhtml]['violations_count']);
    }

    // ------------------------------------------------------------------
    // Console command ALIASES 回归（T31 · Q7 方案 B）
    // ------------------------------------------------------------------

    public function testAntiHardcodeScanCommandDeclaresPlanAlias(): void
    {
        $commandClass = \GuoLaiRen\PageBuilder\Console\AiSite\AntiHardcodeScan::class;
        self::assertTrue(
            \defined($commandClass . '::ALIASES'),
            '命令类应声明 ALIASES 常量，便于框架注入别名至 commands.php'
        );

        /** @var list<string> $aliases */
        $aliases = $commandClass::ALIASES;
        self::assertIsArray($aliases);
        self::assertContains(
            'ai-site:anti-hardcode:scan',
            $aliases,
            '计划 §12.1B 命名 ai-site:anti-hardcode:scan 必须作为命令别名存在'
        );
    }

    // ------------------------------------------------------------------
    // Large file short-circuit
    // ------------------------------------------------------------------

    public function testLargeFileIsSkipped(): void
    {
        $path = $this->tmpPath('huge.phtml');
        // 构造 > 2MB 的文件内容（填充 ASCII，避免编码争议）。
        \file_put_contents($path, \str_repeat('A', 2 * 1024 * 1024 + 16));
        $this->tmpFiles[] = $path;

        $summary = (new AiSiteAntiHardcodeScanService())->scanPaths([$path]);

        self::assertSame('too-large', $summary['files'][$path]['skipped_reason']);
        self::assertSame(0, $summary['files'][$path]['violations_count']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param list<array{rule_id:string}> $violations
     */
    private function hasViolation(array $violations, string $ruleId): bool
    {
        foreach ($violations as $v) {
            if (($v['rule_id'] ?? '') === $ruleId) {
                return true;
            }
        }
        return false;
    }

    private function writeTmp(string $name, string $content): string
    {
        $path = $this->tmpPath($name);
        \file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return $path;
    }

    private function tmpPath(string $name): string
    {
        $dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'ahc-scan-test-' . \bin2hex(\random_bytes(4));
        if (!\is_dir($dir)) {
            \mkdir($dir, 0777, true);
        }

        return $dir . \DIRECTORY_SEPARATOR . $name;
    }
}
