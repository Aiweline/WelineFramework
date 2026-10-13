<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\I18n\Api\Scope\PhraseScopeSource;
use Weline\I18n\Api\Scope\PhraseScopeValue;
use Weline\I18n\Api\Translation\TranslationResolverInterface;
use Weline\Theme\Helper\WidgetI18n;

/**
 * Policy/editor HTML bodies are keyed as sentence-level CSV rows, not whole blobs.
 */
final class WidgetI18nHtmlContractTest extends TestCase
{
    private ?object $originalResolver = null;

    private WidgetI18nHtmlMapResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        if (Context::hasCurrent()) {
            Context::leave();
        }
        Context::enter(new Context());
        RequestContext::init();
        RequestContext::setWelineUserLang('en_US');

        $instances = ObjectManager::getInstances();
        $this->originalResolver = $instances[TranslationResolverInterface::class] ?? null;
        $this->resolver = new WidgetI18nHtmlMapResolver([
            '1.1 本网站不对因以下原因导致的服务中断或数据丢失承担责任：'
                => '1.1 This website is not responsible for service interruption or data loss caused by the following reasons:',
            '（1）不可抗力因素，如自然灾害、战争、罢工、政府行为等；'
                => '(1) Force majeure such as natural disasters, war, strikes, or government actions;',
        ]);
        ObjectManager::setInstance(TranslationResolverInterface::class, $this->resolver);
    }

    protected function tearDown(): void
    {
        ObjectManager::removeInstance(TranslationResolverInterface::class);
        if ($this->originalResolver !== null) {
            ObjectManager::setInstance(TranslationResolverInterface::class, $this->originalResolver);
        }
        RequestContext::cleanup();
        if (Context::hasCurrent()) {
            Context::leave();
        }

        parent::tearDown();
    }

    public function testHtmlMethodExistsWithSegmentStrategy(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Helper/WidgetI18n.php');
        self::assertStringContainsString('public static function html(', $src);
        self::assertStringContainsString('preg_replace_callback', $src);
        self::assertStringContainsString('\\p{Han}', $src);
    }

    public function testHtmlTranslatesChineseTextNodesAndKeepsMarkup(): void
    {
        $html = '<p>1.1 本网站不对因以下原因导致的服务中断或数据丢失承担责任：</p>'
            . '<ul><li>（1）不可抗力因素，如自然灾害、战争、罢工、政府行为等；</li></ul>';
        $out = WidgetI18n::html($html);
        self::assertStringContainsString('<p>', $out);
        self::assertStringContainsString('<li>', $out);
        self::assertStringContainsString(
            '1.1 This website is not responsible for service interruption or data loss caused by the following reasons:',
            $out
        );
        self::assertStringContainsString(
            '(1) Force majeure such as natural disasters, war, strikes, or government actions;',
            $out
        );
        self::assertStringNotContainsString('本网站不对因以下原因', $out);
    }
}

final class WidgetI18nHtmlMapResolver implements TranslationResolverInterface
{
    /** @param array<string, string> $map */
    public function __construct(private array $map)
    {
    }

    public function translate(string $source, string $localeCode, array $preferredModules = []): string
    {
        return $this->map[$source] ?? $source;
    }

    public function reset(): void
    {
    }

    public function translateForScope(
        string $source,
        ScopeIdentity $identity,
        string $localeCode,
        array $preferredModules = [],
        ?array $localeFallbackChain = null,
    ): PhraseScopeValue {
        $text = $this->translate($source, $localeCode, $preferredModules);

        return new PhraseScopeValue(
            text: $text,
            source: PhraseScopeSource::fromDefault($localeCode),
            requestedScope: $identity,
            requestedLocale: $localeCode,
            fallbackStorageScopes: [],
            localeFallbackChain: $localeFallbackChain ?? [],
        );
    }
}
