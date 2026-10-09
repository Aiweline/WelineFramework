<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;
use Weline\Theme\Service\ThemePlaceableRegistry;

/**
 * Architecture: nested mini-cart footer-extras (coupon/留言) must bake into
 * renderResolved slot callbacks under native mini-cart-icon — including when a
 * matching saved native node already exists (prior early-return left empty shells).
 */
final class MiniCartFooterExtrasNativeOwnerBakeContractTest extends TestCase
{
    public function testMatchedSavedNativeOwnerStillParentsFooterExtrasOrphans(): void
    {
        $registry = new class extends ThemePlaceableRegistry {
            public function __construct() {}

            public function find(
                string $module,
                string $type,
                string $code,
                ?\Weline\Theme\Model\WelineTheme $theme = null,
                string $area = 'frontend',
            ): ?ThemeComponentDefinition {
                if ($code !== 'mini-cart-icon') {
                    return null;
                }

                return new ThemeComponentDefinition(
                    module: $module,
                    type: $type,
                    code: $code,
                    name: $code,
                    renderMode: ThemeRenderable::MODE_TEMPLATE_CONTENT,
                    templateContent: '<aside><w:slot id="footer-extras" multiple="true"></w:slot></aside>',
                    slots: ['footer-extras'],
                );
            }
        };

        $owner = [
            'node_uid' => 'saved-mini-cart',
            'widget_module' => 'Weline_Theme',
            'widget_type' => 'header',
            'widget_code' => 'mini-cart-icon',
            'slot_id' => 'user-area',
            'source' => 'template_inline',
            // Empty template_ref so discover matches this saved native owner (non-empty
            // mismatch would skip match and recreate — not the regression under test).
            'config' => [],
        ];
        $coupon = [
            'node_uid' => 'coupon',
            'widget_module' => 'Weline_Marketing',
            'widget_type' => 'content',
            'widget_code' => 'mini-cart-coupon',
            'slot_id' => 'footer-extras',
            'source' => 'default_injection',
            'config' => [],
        ];
        $notice = [
            'node_uid' => 'notice',
            'widget_module' => 'Weline_Order',
            'widget_type' => 'form',
            'widget_code' => 'order-notice',
            'slot_id' => 'footer-extras',
            'source' => 'default_injection',
            'config' => [],
        ];

        $source = '<w:slot id="user-area"><w:widget module="Weline_Theme" type="header" name="mini-cart-icon" /></w:slot>';
        $compiler = new LayoutRelationCompiler($registry);
        $nodes = $compiler->discoverNativeOwners($source, [
            'saved-mini-cart' => $owner,
            'coupon' => $coupon,
            'notice' => $notice,
        ]);

        self::assertSame('saved-mini-cart', (string)($nodes['coupon']['parent_uid'] ?? ''));
        self::assertSame('saved-mini-cart', (string)($nodes['notice']['parent_uid'] ?? ''));

        $again = $compiler->discoverNativeOwners($source, $nodes);
        self::assertSame('saved-mini-cart', (string)($again['coupon']['parent_uid'] ?? ''));
        self::assertSame('saved-mini-cart', (string)($again['notice']['parent_uid'] ?? ''));

        $compiled = $compiler->compile($source, [
            'saved-mini-cart' => $owner,
            'coupon' => $coupon,
            'notice' => $notice,
        ]);
        self::assertStringContainsString("'widget_code' => 'mini-cart-coupon'", $compiled);
        self::assertStringContainsString("'widget_code' => 'order-notice'", $compiled);
        self::assertStringContainsString("'footer-extras'", $compiled);
        self::assertGreaterThanOrEqual(3, substr_count($compiled, 'renderResolved'));
    }

    public function testChildrenParentedElsewhereCloneUnderThisNativeOwner(): void
    {
        $registry = new class extends ThemePlaceableRegistry {
            public function __construct() {}

            public function find(
                string $module,
                string $type,
                string $code,
                ?\Weline\Theme\Model\WelineTheme $theme = null,
                string $area = 'frontend',
            ): ?ThemeComponentDefinition {
                if ($code !== 'mini-cart-icon') {
                    return null;
                }

                return new ThemeComponentDefinition(
                    module: $module,
                    type: $type,
                    code: $code,
                    name: $code,
                    renderMode: ThemeRenderable::MODE_TEMPLATE_CONTENT,
                    templateContent: '<aside><w:slot id="footer-extras" multiple="true"></w:slot></aside>',
                    slots: ['footer-extras'],
                );
            }
        };

        $coupon = [
            'node_uid' => 'coupon',
            'widget_module' => 'Weline_Marketing',
            'widget_type' => 'content',
            'widget_code' => 'mini-cart-coupon',
            'slot_id' => 'footer-extras',
            'source' => 'default_injection',
            'parent_uid' => 'other-header-mini-cart',
            'config' => [],
        ];
        $source = '<w:slot id="user-area"><w:widget module="Weline_Theme" type="header" name="mini-cart-icon" /></w:slot>';
        $compiler = new LayoutRelationCompiler($registry);
        $nodes = $compiler->discoverNativeOwners($source, ['coupon' => $coupon]);

        $ownerUid = '';
        $cloneUid = '';
        foreach ($nodes as $uid => $node) {
            if (($node['widget_code'] ?? '') === 'mini-cart-icon') {
                $ownerUid = (string)$uid;
            }
            if (($node['widget_code'] ?? '') === 'mini-cart-coupon' && (string)($node['parent_uid'] ?? '') !== 'other-header-mini-cart') {
                $cloneUid = (string)$uid;
            }
        }
        self::assertNotSame('', $ownerUid);
        self::assertNotSame('', $cloneUid);
        self::assertNotSame('coupon', $cloneUid);
        self::assertSame($ownerUid, (string)($nodes[$cloneUid]['parent_uid'] ?? ''));
        self::assertSame('other-header-mini-cart', (string)($nodes['coupon']['parent_uid'] ?? ''));

        $compiled = $compiler->compile($source, ['coupon' => $coupon]);
        self::assertStringContainsString("'widget_code' => 'mini-cart-coupon'", $compiled);
        self::assertStringContainsString("'footer-extras'", $compiled);
    }
}
