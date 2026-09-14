<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Contract: 货源平台列表须人性化，禁止原始 JSON / 英文探活码 / 私造色板 CSS。
 */
final class ChannelIndexTemplateContractTest extends TestCase
{
    public function testChannelIndexUsesThemeComponentsAndHumanLabels(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Backend/Channel/index.phtml';
        self::assertFileExists($path);
        $tpl = (string)file_get_contents($path);

        self::assertStringContainsString('w-table', $tpl);
        self::assertStringContainsString('w-badge', $tpl);
        self::assertStringContainsString('w-button', $tpl);
        self::assertStringContainsString('data-dropship-admin="channels"', $tpl);

        self::assertStringContainsString('已连接', $tpl);
        self::assertStringContainsString('缺少凭证', $tpl);
        self::assertStringContainsString('目录', $tpl);
        self::assertStringContainsString('履约', $tpl);
        self::assertStringContainsString('运费', $tpl);
        self::assertStringContainsString('回调', $tpl);
        self::assertStringContainsString('选品浏览', $tpl);
        self::assertStringContainsString('选品国家筛选', $tpl);
        self::assertStringContainsString('订单回调', $tpl);
        self::assertStringContainsString('商品回调', $tpl);
        self::assertStringContainsString('库存回调', $tpl);
        self::assertStringContainsString('物流回调', $tpl);
        self::assertStringContainsString('补单回调', $tpl);
        self::assertStringContainsString('私有订单回调', $tpl);
        self::assertStringContainsString('纠纷回调', $tpl);
        self::assertStringContainsString('仓映射', $tpl);
        self::assertStringContainsString('title=', $tpl);
        self::assertStringContainsString('配置凭证', $tpl);
        self::assertStringContainsString("__('配置')", $tpl);
        self::assertStringNotContainsString('!$ok && $credentialsEmbed', $tpl);
        self::assertStringNotContainsString('!$ok && $credentialsUrl', $tpl);
        self::assertStringContainsString('credentials_url', $tpl);
        self::assertStringContainsString('credentials_embed', $tpl);
        self::assertStringContainsString('dialog.open', $tpl);
        self::assertStringContainsString('w-dialog', $tpl);
        self::assertStringContainsString('w:config:embed', $tpl);
        self::assertStringContainsString('credEmbedGroup', $tpl);
        self::assertStringContainsString('w:scope', $tpl);
        self::assertStringContainsString('作用范围', $tpl);
        self::assertStringContainsString('dropship-cred-scope', $tpl);
        self::assertStringContainsString('selected_scope', $tpl);
        self::assertStringContainsString('回调地址', $tpl);
        self::assertStringContainsString('dropship-webhook-url', $tpl);
        self::assertStringContainsString('webhook_url', $tpl);
        self::assertStringContainsString('测试连接', $tpl);
        self::assertStringContainsString('dropship-cred-probe', $tpl);
        self::assertStringContainsString('data-probe-url', $tpl);

        self::assertStringNotContainsString('text-transform:uppercase', $tpl);
        self::assertStringNotContainsString('rgba(25,135,84', $tpl);
        self::assertStringNotContainsString('rgba(220,53,69', $tpl);
        self::assertStringNotContainsString('万能支付注入', $tpl);
        self::assertStringNotContainsString('json_encode($p[\'capabilities\']', $tpl);
    }

    public function testCredentialsActionUsesProviderDeepLinkNotShellConfig(): void
    {
        $root = dirname(__DIR__, 3);
        $tpl = (string)file_get_contents($root . '/view/templates/Backend/Channel/index.phtml');
        $ctrl = (string)file_get_contents($root . '/Controller/Backend/Channel.php');
        $builder = (string)file_get_contents($root . '/Service/DropshipChannelConfigDeepLinkBuilder.php');

        // Primary CTA: in-page w-dialog + config:embed; deep link secondary; shell config only top CTA.
        self::assertStringContainsString('dialog.open', $tpl);
        self::assertStringContainsString('dropship-cred-dialog', $tpl);
        self::assertStringContainsString('w:config:embed', $tpl);
        self::assertStringContainsString('data-testid="dropship-config-credentials"', $tpl);
        self::assertStringContainsString('dropship/backend/config', $tpl);
        self::assertStringContainsString('dropship-open-config', $tpl);
        self::assertStringContainsString('在统一配置中心打开', $tpl);
        self::assertStringContainsString('w:scope', $tpl);
        self::assertStringContainsString('cred_dialog', $tpl);

        self::assertStringContainsString('DropshipChannelConfigDeepLinkBuilder', $ctrl);
        self::assertStringContainsString('SystemConfigTargetScopeService', $ctrl);
        self::assertStringContainsString('config_center', $ctrl);
        self::assertStringContainsString('credentials_url', $ctrl);
        self::assertStringContainsString('credentials_embed', $ctrl);
        self::assertStringContainsString('selected_scope', $ctrl);
        self::assertStringContainsString('webhook_url', $ctrl);
        self::assertStringContainsString('endpoint_code', $ctrl);
        self::assertStringContainsString('function probe', $ctrl);
        self::assertStringContainsString('probeConnection', $ctrl);
        self::assertStringContainsString("getUrlBuilder()->getBackendUrl('dropship/backend/channel/probe')", $ctrl);
        self::assertStringNotContainsString('$this->getBackendUrl(', $ctrl);
        self::assertStringContainsString("'group'", $ctrl);

        self::assertStringContainsString('weline_systemconfig/backend/config', $builder);
        self::assertStringContainsString('guide_key', $builder);
    }
}
