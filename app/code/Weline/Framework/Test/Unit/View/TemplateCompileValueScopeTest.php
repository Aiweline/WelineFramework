<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Taglib\Parser\PhpExtractor;
use Weline\Framework\View\Template;

/**
 * 编译期占位符只能来自本次编译，且只有「值」能当 block 属性。
 * 线上 Cdn 域名页曾把后台 head 局部模板的 if/else/endif 编号搬进 block 属性，
 * 编出 `'title' => (endif)`，落盘后整页永久 500。
 */
final class TemplateCompileValueScopeTest extends TestCase
{
    private bool $contextEntered = false;

    protected function tearDown(): void
    {
        if ($this->contextEntered) {
            RequestContext::cleanup();
            Context::leave();
            $this->contextEntered = false;
        }
    }

    public function testPlaceholderWithoutActiveCompileStaysLiteral(): void
    {
        $taglib = $this->bootTaglib();

        $export = new \ReflectionMethod(Taglib::class, 'exportBlockAttributeValue');
        $export->setAccessible(true);

        // 占位符映射只在一次编译内有效；编译外没有可用的 extractor，不能凭空还原。
        self::assertSame("'__PHP_0__'", $export->invoke($taglib, '__PHP_0__'));
        self::assertSame("'__PHP_1__'", $export->invoke($taglib, '__PHP_1__'));
        self::assertSame("'__PHP_3__'", $export->invoke($taglib, '__PHP_3__'));
    }

    public function testStatementPlaceholderNeverBecomesAttributeValue(): void
    {
        $taglib = $this->bootTaglib();

        $stale = new PhpExtractor();
        $stale->extract('<?php if ($afterCssJsHtml !== null): ?><?= $afterCssJsHtml ?><?php else: ?><?php endif; ?>');

        $export = new \ReflectionMethod(Taglib::class, 'exportBlockAttributeValue');
        $export->setAccessible(true);

        // 映射被污染时：语句不能当值（否则编出 (endif)），值仍按表达式输出。
        self::assertSame('null', $export->invoke($taglib, '__PHP_0__', $stale));
        self::assertSame('($afterCssJsHtml)', $export->invoke($taglib, '__PHP_1__', $stale));
        self::assertSame('null', $export->invoke($taglib, '__PHP_3__', $stale));
    }

    public function testBlockLangAttributeStillCompilesToEchoExpression(): void
    {
        $taglib = $this->bootTaglib();
        $template = Template::getInstance();
        $source = "<block class='Weline\\Component\\Block\\OffCanvas'\n"
            . "       template='Weline_Component::off-canvas.phtml'\n"
            . "       title='@lang(添加域名)' />";

        $compiled = (string)$taglib->compile($template, $source, 'value-scope-block.phtml');

        self::assertStringContainsString(
            "'title' => (\\Weline\\I18n\\Service\\InlineEdit\\InlinePhraseMarker::emit('添加域名', true))",
            $compiled
        );
        self::assertStringNotContainsString('(endif)', $compiled);
        $this->assertCompiledPhpParses($compiled);
    }

    public function testCdnDomainPageCompilesWithoutForeignStatementAttributes(): void
    {
        $taglib = $this->bootTaglib();
        $template = Template::getInstance();
        $sourcePath = \BP . 'app/code/Weline/Cdn/view/templates/backend/Domain/index.phtml';
        $source = (string)\file_get_contents($sourcePath);

        $compiled = (string)$taglib->compile($template, $source, $sourcePath);

        self::assertStringNotContainsString('=> (endif)', $compiled);
        self::assertStringNotContainsString('=> (else:)', $compiled);
        self::assertStringNotContainsString('=> (if (', $compiled);
        $this->assertCompiledPhpParses($compiled);
    }

    public function testInvalidCompiledPhpIsRejectedInsteadOfPublished(): void
    {
        $this->bootContext();
        $template = Template::getInstance();

        $this->expectException(\Weline\Framework\App\Exception::class);
        $this->expectExceptionMessage('模板编译结果不是合法 PHP');

        $template->getFetchFileFromSource(
            'broken-value-scope.phtml',
            '<?php endif; ?>',
            \sys_get_temp_dir() . '/broken-value-scope.phtml'
        );
    }

    private function bootTaglib(): Taglib
    {
        $this->bootContext();

        return ObjectManager::getInstance(Taglib::class);
    }

    private function bootContext(): void
    {
        if (!$this->contextEntered) {
            Context::enter(new Context());
            $this->contextEntered = true;
        }
        RequestContext::setId('template-value-scope-' . \getmypid() . '-' . $this->name());
    }

    private function assertCompiledPhpParses(string $compiled): void
    {
        try {
            \token_get_all($compiled, \TOKEN_PARSE);
        } catch (\ParseError $error) {
            self::fail('compiled template is not valid PHP: ' . $error->getMessage());
        }
        $this->addToAssertionCount(1);
    }
}
