<?php

declare(strict_types=1);

/**
 * 影子编译执行器：同一份源码编译两次（旧链产物由调用方传入），比对字节。
 *
 * 为什么由调用方传旧产物：v3 不允许反向依赖 `Taglib`（否则等于把旧编译器当基类继承），
 * 只有 `compileSourceBytes` 那一处知道旧产物，应由它调用本类。
 *
 * 本类**不写回、不替换**任何东西：只返回报告，必要时把差异落到
 * `var/compile/shadow/<hash>.diff`，供 S3「差异白名单」逐条签核。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Shadow;

use Weline\Framework\View\Compiler\Ir\Emitter;
use Weline\Framework\View\Compiler\Ir\Lower;
use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Parser\Parser;
use Weline\Framework\View\Compiler\Tag\Builtin\Interpolation;
use Weline\Framework\View\Compiler\Tag\BuiltinRegistry;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\TagNameMatcherInterface;

final class ShadowRunner
{
    public function __construct(
        private readonly Registry $registry,
        private readonly TagNameMatcherInterface $matcher,
        private readonly ?string $diffDir = null,
    ) {
    }

    /**
     * 默认注册表 = 已通过 S1 的内置标签 + 模块标签（生产用这个入口，避免调用方各拼一份）。
     */
    public static function withBuiltins(
        \Weline\Framework\View\Compiler\Tag\Adapter\CompiledTagRegistry $modules,
        \Weline\Framework\View\Compiler\Tag\TagNameMatcherInterface $matcher,
        ?string $diffDir = null,
    ): self {
        $registry = new Registry([...$modules->build()->all(), ...BuiltinRegistry::descriptors()]);

        return new self($registry, $matcher, $diffDir);
    }

    /**
     * @param \Weline\Framework\View\Template|null $template `static` 等编译期取源标签需要它；
     *        不传时这些标签会显式抛错（影子侧会 fail-open，不污染旧产物）。
     */
    public function compare(string $source, string $fileName, string $legacyCompiled, ?\Weline\Framework\View\Template $template = null): ShadowReport
    {
        $startedAt = \microtime(true);

        // 旧 Taglib::compile() 在 PROD 下先删 HTML 注释；影子必须在解析前做同一步，
        // 否则整页会从第一条注释开始报假差异。
        if (\defined('PROD') && \PROD) {
            $source = self::removeHtmlComments($source);
        }

        $phpTransformer = null;
        if ($template !== null) {
            $taglib = $template->getTaglib();
            if (\method_exists($taglib, 'expandInlineTagsForCompiler')) {
                $phpTransformer = static function (string $raw) use ($taglib, $template, $fileName): string {
                    $bound = $template;
                    return $taglib->expandInlineTagsForCompiler($raw, $bound, $fileName);
                };
            }
        }

        $isDForm = \str_contains($source, 'w:d-form');
        if ($isDForm && \class_exists(\Weline\DataTable\Helper\UiAssets::class)) {
            \Weline\DataTable\Helper\UiAssets::resetRequestState();
        }

        $slotClass = 'Weline\\Theme\\Taglib\\Slot';
        $slotKey = 'theme.taglib.registered_slots.v1';
        $slotState = \class_exists($slotClass) ? $slotClass::getRegisteredSlots() : null;
        $hadSlotState = \class_exists(\Weline\Framework\Runtime\RequestContext::class)
            && \Weline\Framework\Runtime\RequestContext::has($slotKey);

        $localClass = 'Weline\\I18n\\Taglib\\Local';
        $localState = [];
        $localRef = null;
        if (\class_exists($localClass)) {
            $localRef = new \ReflectionClass($localClass);
            foreach (['ids' => [], 'bulkModalRendered' => false, 'runtimeRecordId' => ''] as $property => $default) {
                $prop = $localRef->getProperty($property);
                $prop->setAccessible(true);
                $localState[$property] = $prop->getValue();
                $prop->setValue(null, $default);
            }
        }

        $parser = new Parser(new Lexer(), $this->matcher, $phpTransformer);
        $emitter = new Emitter();
        $lower = new Lower($this->registry, $emitter, new Interpolation(), $template);
        try {
            $compile = static fn (): string => $emitter->emit($lower->lower($parser->parse($source)));
            $v3 = $template !== null
                ? $template->withActiveSourceOrigin($fileName, $compile)
                : $compile();
        } finally {
            // 影子编译会触发 w:slot 的 registerSlot()，不能污染当前请求的运行期 slot 表。
            if ($slotState !== null) {
                if ($hadSlotState) {
                    \Weline\Framework\Runtime\RequestContext::set($slotKey, $slotState);
                } else {
                    $slotClass::clearRegisteredSlots();
                }
            }
            if ($localRef !== null) {
                foreach ($localState as $property => $value) {
                    $prop = $localRef->getProperty($property);
                    $prop->setAccessible(true);
                    $prop->setValue(null, $value);
                }
            }
        }


        if ($isDForm) {
            $v3 = self::stripPrefetchBlocks($v3, "\n");
        }

        // 与旧 compile() 相同的产物级后处理（词条预取）。
        if (\class_exists(\Weline\Framework\View\Taglib\TemplateTranslationWords::class)) {
            $v3 = \Weline\Framework\View\Taglib\TemplateTranslationWords::withRuntimePrefetch($v3);
        }

        // 旧 AttributeCodeCompiler 在缺 id 时会生成随机 `ms_xxxxxx`；runtime children
        // 变量名又带旧 AST 行号。两者都不是语义差异，按 S3 白名单归一化后再比。
        $legacyComparable = self::normalizeVolatile($legacyCompiled);
        $v3Comparable = self::normalizeVolatile($v3);
        $equal = $v3Comparable === $legacyComparable;
        $firstDiffAt = $equal ? -1 : $this->firstDiffOffset($legacyComparable, $v3Comparable);

        if (!$equal) {
            $this->writeDiff($fileName, $legacyCompiled, $v3, $firstDiffAt);
        }

        return new ShadowReport(
            fileName: $fileName,
            equal: $equal,
            legacyLength: \strlen($legacyCompiled),
            v3Length: \strlen($v3),
            firstDiffAt: $firstDiffAt,
            legacyExcerpt: \substr($legacyCompiled, \max(0, $firstDiffAt - 60), 400),
            v3Excerpt: \substr($v3, \max(0, $firstDiffAt - 60), 400),
            durationMs: (\microtime(true) - $startedAt) * 1000,
        );
    }

    /**
     * 精确剥离 prefetch 块，不能用 `<?php if (.*?prefetch...)` 宽正则：
     * 那会从 `if ($this->getHeader())` 一路吃到后面的 prefetch 块，删掉真实内容。
     */
    private static function stripPrefetchBlocks(string $compiled, string $replacement = ''): string
    {
        $marker = "<?php if (\\method_exists(\\Weline\\Framework\\Phrase\\Parser::class, 'prefetchTemplateWords')) {";
        $offset = 0;
        while (($position = \strpos($compiled, $marker, $offset)) !== false) {
            $end = \strpos($compiled, '} ?>', $position + \strlen($marker));
            if ($end === false) {
                break;
            }
            $compiled = \substr($compiled, 0, $position) . $replacement . \substr($compiled, $end + 4);
            $offset = $position;
        }

        return $compiled;
    }

    /**
     * 影子比对前归一化「非语义」易变标识：
     * - `ms_xxxxxx`：旧 AttributeCodeCompiler 缺 id 时随机生成；
     * - `$__children_<n>`：旧 AST 行号口径差异导致的 runtime 捕获变量名。
     *
     * 只影响影子报告，不改产物、不改 S1 的原始字节比较。
     */
    private static function normalizeVolatile(string $compiled): string
    {
        $compiled = (string)\preg_replace('/ms_[0-9a-f]{6}/', 'ms_<id>', $compiled);
        $compiled = (string)\preg_replace('/mm_[0-9a-f]{6,}/', 'mm_<id>', $compiled);
        $compiled = (string)\preg_replace('/data-node-uid="[^"]*"/', 'data-node-uid="<id>"', $compiled);
        // csrf 的 token 是编译期随机值；只归一化 hidden input 的 value。
        $compiled = (string)\preg_replace(
            "/(<input type='hidden' name='[^']+' value=')[^']*(')/",
            '$1<token>$2',
            $compiled,
        );

        $compiled = (string)\preg_replace('/\$__children_\d+/', '$__children_<id>', $compiled);
        // prefetch 前缀/子模板内联前缀是产物级词条缓存，不影响 HTML；影子按 S3 白名单剥离。
        $compiled = self::stripPrefetchBlocks($compiled);

        return \trim($compiled);
    }

    /**
     * 与旧 `Taglib::removeHtmlComments()` 逐字节同逻辑：删 `<!-- ... -->`，
     * 未闭合的注释从该处截断（旧实现 `break` 后不再输出剩余字节）。
     */
    private static function removeHtmlComments(string $content): string
    {
        $result = '';
        $length = \strlen($content);
        $i = 0;
        while ($i < $length) {
            if ($content[$i] === '<' && \substr($content, $i, 4) === '<!--') {
                $end = \strpos($content, '-->', $i + 4);
                if ($end === false) {
                    break;
                }
                $i = $end + 3;
                continue;
            }
            $result .= $content[$i];
            $i++;
        }

        return $result;
    }

    private function firstDiffOffset(string $a, string $b): int
    {
        $max = \min(\strlen($a), \strlen($b));
        for ($i = 0; $i < $max; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $i;
            }
        }

        return $max;
    }

    private function writeDiff(string $fileName, string $legacy, string $v3, int $firstDiffAt): void
    {
        if ($this->diffDir === null || $this->diffDir === '') {
            return;
        }
        if (!\is_dir($this->diffDir)) {
            @\mkdir($this->diffDir, 0755, true);
        }
        $hash = \substr(\hash('sha256', $fileName . '|' . $legacy), 0, 16);
        $payload = "file: {$fileName}\nfirst_diff_at: {$firstDiffAt}\n"
            . "---- legacy ----\n{$legacy}\n---- v3 ----\n{$v3}\n";
        @\file_put_contents(\rtrim($this->diffDir, '/\\') . DIRECTORY_SEPARATOR . $hash . '.diff', $payload);
    }
}
