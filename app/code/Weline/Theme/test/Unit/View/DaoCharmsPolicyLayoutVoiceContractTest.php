<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * DaoCharms design theme owns ritual-object policy voice + three-column shell;
 * body extracts into design Defaults + policy-document (plain, show_header=false).
 */
final class DaoCharmsPolicyLayoutVoiceContractTest extends TestCase
{
    public function testDaoCharmsPolicyLayoutsUseDesignDefaultsAndPlainWidget(): void
    {
        $base = dirname(__DIR__, 6) . '/design/Weline/daocharms/frontend';
        $defaultsPath = $base . '/includes/DaoCharmsPolicyDocumentDefaults.php';
        self::assertFileExists($defaultsPath);

        $pages = [
            'policy/privacy.phtml',
            'policy/cookie.phtml',
            'policy/refund.phtml',
            'policy/shipping.phtml',
            'policy/disclaimer.phtml',
            'policy/accessibility.phtml',
            'terms/default.phtml',
        ];

        foreach ($pages as $rel) {
            $path = $base . '/layouts/' . $rel;
            self::assertFileExists($path, $rel);
            $src = (string)file_get_contents($path);
            self::assertStringContainsString('DaoCharmsPolicyDocumentDefaults', $src, $rel);
            self::assertStringContainsString('renderRuntimeInline', $src, $rel);
            self::assertStringContainsString('policy-document', $src, $rel);
            self::assertStringContainsString('daocharms-policy-shell', $src, $rel);
            self::assertStringNotContainsString('<h2 id=', $src, 'fat h2 must move to Defaults: ' . $rel);
        }

        require_once $defaultsPath;
        self::assertTrue(\class_exists('DaoCharmsPolicyDocumentDefaults'));

        $shipping = \DaoCharmsPolicyDocumentDefaults::widgetParams('shipping');
        self::assertSame('plain', $shipping['variant'] ?? null);
        self::assertFalse((bool)($shipping['show_header'] ?? true));
        self::assertGreaterThanOrEqual(8, count($shipping['sections'] ?? []));

        $privacy = \DaoCharmsPolicyDocumentDefaults::forPage('privacy');
        self::assertStringContainsString('DaoCharms', (string)($privacy['lead'] ?? ''));
        self::assertGreaterThanOrEqual(14, count($privacy['sections'] ?? []));
        $contact = null;
        foreach ($privacy['sections'] as $section) {
            if (($section['id'] ?? '') === 'privacy-contact') {
                $contact = $section;
                break;
            }
        }
        self::assertNotNull($contact);
        self::assertStringContainsString('成都阿玛云科技有限公司', (string)($contact['body'] ?? ''));

        $terms = \DaoCharmsPolicyDocumentDefaults::forPage('terms');
        self::assertGreaterThanOrEqual(12, count($terms['sections'] ?? []));

        $widget = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/content/policy-document/default.phtml';
        $widgetSrc = (string)file_get_contents($widget);
        self::assertStringContainsString('show_header', $widgetSrc);
        self::assertStringContainsString('$showHeader', $widgetSrc);
    }
}
