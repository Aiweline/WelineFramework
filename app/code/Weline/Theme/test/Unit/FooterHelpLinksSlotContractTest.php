<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;

/**
 * 页脚「帮助中心」扩展槽迁入 footer-container；列内默认硬链为空。
 */
final class FooterHelpLinksSlotContractTest extends TestCase
{
    public function testFooterContainerExposesHelpExtrasSlot(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/footer/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('<w:slot id="footer-help-links"', $src);
        self::assertStringContainsString(
            'accept="footer-my-account-link,footer-my-orders-link,footer-shipping-info-link,footer-returns-policy-link,footer-faq-link,footer-contact-service-link,layout-footer-help-links"',
            $src
        );
        self::assertStringContainsString("\$groupKey === 'help'", $src);
    }

    public function testPartialNoLongerHostsHelpSlot(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/footer/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringNotContainsString('<w:slot id="footer-help-links"', $src);
    }

    public function testDefaultHelperKeepsHelpEmpty(): void
    {
        $items = FooterDefaultLinksHelper::defaultLinkItems();
        foreach ($items as $item) {
            if (($item['group_key'] ?? '') !== 'help') {
                continue;
            }
            self::fail('help 分组不应有默认硬链分项');
        }
        $groups = FooterDefaultLinksHelper::defaultLinkGroups();
        $help = null;
        foreach ($groups as $group) {
            if (($group['key'] ?? '') === 'help') {
                $help = $group;
                break;
            }
        }
        self::assertNotNull($help);
        self::assertSame('帮助中心', $help['title'] ?? null);
        self::assertTrue((bool)($help['enabled'] ?? false));
        self::assertStringContainsString('footer-help-links', (string)file_get_contents(
            dirname(__DIR__, 2) . '/Helper/FooterDefaultLinksHelper.php'
        ));
        self::assertStringNotContainsString("['label' => '我的账户'", (string)file_get_contents(
            dirname(__DIR__, 2) . '/Helper/FooterDefaultLinksHelper.php'
        ));
    }
}
