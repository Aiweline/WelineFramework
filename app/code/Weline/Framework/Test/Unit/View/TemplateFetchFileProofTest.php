<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\View\Template;

/**
 * 锁定 PA-2「源文件指纹证明」的失效语义：任何真实改动都必须让捷径失效，
 * 否则会拿着旧编译产物渲染。
 */
final class TemplateFetchFileProofTest extends TestCase
{
    private bool $contextEntered = false;
    private string $sourceFile = '';

    protected function setUp(): void
    {
        Context::enter(new Context());
        $this->contextEntered = true;
        RequestContext::setId('template-fetch-proof-' . getmypid() . '-' . $this->name());
        $this->sourceFile = \sys_get_temp_dir() . '/w_proof_' . \bin2hex(\random_bytes(6)) . '.phtml';
    }

    protected function tearDown(): void
    {
        if ($this->sourceFile !== '' && \is_file($this->sourceFile)) {
            @\unlink($this->sourceFile);
        }
        if ($this->contextEntered) {
            RequestContext::cleanup();
            Context::leave();
            $this->contextEntered = false;
        }
    }

    public function testProofSurvivesUnchangedSourceAndFailsOnEveryRealChange(): void
    {
        $template = Template::getInstance();
        $source = '<b>proof</b>';
        $frozen = \time() - 60;
        \file_put_contents($this->sourceFile, $source);
        \touch($this->sourceFile, $frozen);
        \clearstatcache(true, $this->sourceFile);

        $compiled = $template->getFetchFileFromSource('proof-source.phtml', $source, $this->sourceFile, 'proof-ctx');
        $this->remember($compiled);

        self::assertTrue($this->proven($compiled), '未改动的源码应当命中证明。');

        // 1) 长度变化
        \file_put_contents($this->sourceFile, $source . ' ');
        \clearstatcache(true, $this->sourceFile);
        self::assertFalse($this->proven($compiled), '字节数变化必须失效。');

        // 2) 同长度、mtime 被强行还原（rsync --times / tar / git checkout 的效果），
        //    只有 ctime 能揭穿它。
        \sleep(1);
        \file_put_contents($this->sourceFile, '<b>prooF</b>');
        \touch($this->sourceFile, $frozen);
        \clearstatcache(true, $this->sourceFile);
        self::assertSame($frozen, (int)\filemtime($this->sourceFile), '本用例前提：mtime 已被还原。');
        self::assertSame((int)\filesize($this->sourceFile), \strlen($source), '本用例前提：长度相同。');
        self::assertFalse($this->proven($compiled), 'mtime/长度相同但 ctime 变化，必须失效。');

        // 3) 当前秒内写入的源码不得走捷径（同一秒内还可能再改）
        \file_put_contents($this->sourceFile, $source);
        \clearstatcache(true, $this->sourceFile);
        self::assertFalse($this->proven($compiled), '当前秒内写入的源码不得走捷径。');

        // 4) 编译产物被删除（cache:clear）
        \touch($this->sourceFile, $frozen);
        \clearstatcache(true, $this->sourceFile);
        $this->remember($compiled);
        self::assertTrue($this->proven($compiled));
        \unlink($compiled);
        \clearstatcache(true, $compiled);
        self::assertFalse($this->proven($compiled), '编译产物不存在时必须失效。');
    }

    public function testProofRequiresAPriorSuccessfulCompile(): void
    {
        $template = Template::getInstance();
        $source = '<b>cold</b>';
        \file_put_contents($this->sourceFile, $source);
        \touch($this->sourceFile, \time() - 60);
        \clearstatcache(true, $this->sourceFile);
        $compiled = $template->getFetchFileFromSource('proof-cold.phtml', $source, $this->sourceFile, 'proof-ctx');

        // 未 remember 前不得命中（否则会跳过首次编译校验）
        self::assertFalse($this->proven($compiled), '没有任何编译证据时不得命中。');
    }

    /**
     * 端到端：证明必须由 getFetchFile 自己写下，并在同进程后续调用中复用；
     * 源码一改就必须重编译。
     *
     * 用非空 `$module_name` 绕过请求内 `view.fetch_file:` memo，这样每次调用都会
     * 真正走一遍冷路径判定；再用 `template.source_bytes_digest` 相位计数直接证明
     * 「第二次没有再做摘要」。
     */
    public function testGetFetchFileSkipsDigestOnceProvenAndRecompilesOnChange(): void
    {
        \file_put_contents($this->sourceFile, '<b>v1</b>');
        \touch($this->sourceFile, \time() - 60);
        \clearstatcache(true, $this->sourceFile);

        $template = Template::getInstance();
        $this->setTracing(true);

        $compiled = $template->getFetchFile($this->sourceFile, 'proof-bypass-request-memo');
        self::assertSame('<b>v1</b>', $template->ob_file($compiled));
        self::assertSame(1, $this->digestCalls(), '首次取模板必须算一次摘要。');
        self::assertTrue($this->proven($compiled), 'getFetchFile 首次编译后必须留下证明。');

        // 第二次：证明命中，不得再做摘要，也不得重写产物
        \touch($compiled, \time() - 30);
        \clearstatcache(true, $compiled);
        self::assertSame($compiled, $template->getFetchFile($this->sourceFile, 'proof-bypass-request-memo'));
        self::assertSame(1, $this->digestCalls(), '证明命中时不得再计算源码摘要。');
        \clearstatcache(true, $compiled);
        self::assertSame(\time() - 30, (int)\filemtime($compiled), '命中证明时不应重写编译产物。');

        // 源码变化 -> 证明失效 -> 重编译 -> 渲染新内容
        \file_put_contents($this->sourceFile, '<b>v2</b>');
        \clearstatcache(true, $this->sourceFile);
        $recompiled = $template->getFetchFile($this->sourceFile, 'proof-bypass-request-memo');
        self::assertSame('<b>v2</b>', $template->ob_file($recompiled));
        self::assertSame(2, $this->digestCalls(), '源码变化必须重新计算摘要。');
    }

    /**
     * 捷径不得漏掉慢路径的副作用。
     *
     * `compileSourceBytes()` 会把「编译产物 -> 源文件」写进 `compiledSourceOrigins`，
     * 渲染期 `obFileInner()` / `streamCompiledFile()` 再从这张表里取回
     * `activeSourceOrigin`，用它解析相对路径的兄弟模板 fetch。证明命中时如果不补写
     * 这条映射，`activeSourceOrigin` 会退化成空串，相对 fetch 直接 500
     * （实测报「模板文件不存在！：generated/complicate/_unscoped/view/child.phtml」）。
     *
     * 生产常驻 worker 里从第二个请求起每个模板都走捷径，所以这条必须锁死。
     */
    public function testProofHitStillRecordsSourceOriginForRelativeNestedFetch(): void
    {
        $dir = \sys_get_temp_dir() . '/w_origin_' . \bin2hex(\random_bytes(6));
        \mkdir($dir, 0775, true);
        $parent = $dir . '/parent.phtml';
        $child = $dir . '/child.phtml';
        $frozen = \time() - 120;
        \file_put_contents($child, '<i>child</i>');
        \file_put_contents($parent, '<b>parent</b>[' . '<?= $this->fetch("child.phtml") ?>' . ']');
        \touch($child, $frozen);
        \touch($parent, $frozen);
        \clearstatcache();

        try {
            // 请求 A：冷编译，慢路径自然会写下 origin。
            RequestContext::setId('origin-proof-a-' . \getmypid() . '-' . $this->name());
            $first = Template::getInstance();
            $compiled = $first->getFetchFile($parent, 'origin-proof-bypass-memo');
            self::assertSame($parent, $this->originOf($first, $compiled), '慢路径必须记录源文件来源。');
            self::assertSame('<b>parent</b>[<i>child</i>]', $first->ob_file($compiled));

            // 请求 B：新作用域 = 新实例，但进程内证明仍在 -> 走捷径。
            RequestContext::setId('origin-proof-b-' . \getmypid() . '-' . $this->name());
            $second = Template::getInstance();
            self::assertNotSame($first, $second, '本用例前提：新请求作用域必须给出新实例。');
            $compiledB = $second->getFetchFile($parent, 'origin-proof-bypass-memo');
            self::assertSame($compiled, $compiledB, '本用例前提：编译产物是固定路径。');
            self::assertSame(
                $parent,
                $this->originOf($second, $compiledB),
                '证明命中时也必须记录源文件来源，否则相对 fetch 会失效。'
            );
            self::assertSame(
                '<b>parent</b>[<i>child</i>]',
                $second->ob_file($compiledB),
                '证明命中后相对路径的兄弟模板仍必须解析成功。'
            );
        } finally {
            @\unlink($parent);
            @\unlink($child);
            @\rmdir($dir);
        }
    }

    private function originOf(Template $template, string $compiled): string
    {
        $property = new \ReflectionProperty(Template::class, 'compiledSourceOrigins');
        $property->setAccessible(true);
        $map = $property->getValue($template);

        return (string)($map[$compiled] ?? '');
    }

    private function proven(string $compiled): bool
    {
        return $this->method('fetchFileIdentityIsProven')(
            $compiled,
            (int)\filemtime($this->sourceFile),
            (int)\filesize($this->sourceFile),
            (int)\filectime($this->sourceFile),
            (int)\fileinode($this->sourceFile)
        );
    }

    private function remember(string $compiled): void
    {
        $this->method('rememberFetchFileIdentity')(
            $compiled,
            (int)\filemtime($this->sourceFile),
            (int)\filesize($this->sourceFile),
            (int)\filectime($this->sourceFile),
            (int)\fileinode($this->sourceFile)
        );
    }

    private function method(string $name): \Closure
    {
        $method = new \ReflectionMethod(Template::class, $name);
        $method->setAccessible(true);
        $template = Template::getInstance();

        return static fn(mixed ...$args) => $method->invoke($template, ...$args);
    }

    private function setTracing(bool $enabled): void
    {
        $state = (new \ReflectionMethod(RequestLifecycleTrace::class, 'state'))->invoke(null);
        $state->enabledCache = $enabled;
    }

    private function digestCalls(): int
    {
        return RequestLifecycleTrace::getAggregateSummary()['phases']['template.source_bytes_digest']['calls'] ?? 0;
    }
}
