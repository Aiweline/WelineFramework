<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;
use PHPUnit\Framework\TestCase;

final class FrameworkBuilderHtmlContentValidationTest extends TestCase
{
    public function testSafeEditableEchoesDoNotRaisePhpTagWarning(): void
    {
        $builder = new FrameworkBuilder();
        $method = new \ReflectionMethod($builder, 'validateField');
        $method->setAccessible(true);

        $result = $method->invoke($builder, 'html_content', <<<'HTML'
<section class="pb-c-root">
    <h2><?= htmlspecialchars($contentTitle ?? 'Approval workflows', ENT_QUOTES, 'UTF-8') ?></h2>
    <p><?= nl2br(htmlspecialchars($contentDescription ?? 'Route approvals with confidence.', ENT_QUOTES, 'UTF-8')) ?></p>
</section>
HTML);

        self::assertIsArray($result);
        self::assertTrue($result['valid']);
        self::assertSame([], $result['warnings']);
    }

    public function testUnsafePhpBlocksStillRaisePhpTagWarning(): void
    {
        $builder = new FrameworkBuilder();
        $method = new \ReflectionMethod($builder, 'validateField');
        $method->setAccessible(true);

        $result = $method->invoke(
            $builder,
            'html_content',
            "<?php foreach (\$items as \$item): ?><p><?= htmlspecialchars(\$item['label'] ?? '', ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?>"
        );

        self::assertIsArray($result);
        self::assertFalse($result['valid']);
        self::assertNotSame([], $result['warnings']);
    }

    public function testPhpVariablesPlaceholderCommentIsReplacedAsExecutablePhp(): void
    {
        $builder = new FrameworkBuilder();
        $phtml = $builder->buildComponent('header', ['name' => 'Header'], [
            'php_variables' => '$ctaText = "Request a demo";',
            'extra_fields' => '',
            'css_extra' => '',
            'css_responsive' => '',
            'css_content' => '',
            'html_content' => '',
            'js_content' => '',
        ]);

        self::assertStringContainsString('$ctaText = "Request a demo";', $phtml);
        self::assertStringNotContainsString('/* $ctaText = "Request a demo"; */', $phtml);
        self::assertStringNotContainsString('{{PHP_VARIABLES}}', $phtml);
    }
}
