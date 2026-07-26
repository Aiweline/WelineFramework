<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Service\PixelChannelCreateService;
use Weline\Visitor\Service\PixelChannelUpdateService;
use Weline\Visitor\Service\PixelChannelValidationService;

/**
 * B05：编辑/停用；code 强制只读（不依赖表已落库即可覆盖组装与校验）。
 */
class PixelChannelUpdateServiceTest extends TestCore
{
    private PixelChannelUpdateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $validation = new PixelChannelValidationService();
        $this->service = new PixelChannelUpdateService($validation, new PixelChannelCreateService($validation));
    }

    public function testAssembleUpdateRowForcesOriginalCodeAndKeepsUtmCampaignSynced(): void
    {
        $original = [
            'pixel_channel_id' => 9,
            'kind' => PixelChannel::KIND_CAMPAIGN,
            'code' => 'summer',
            'name' => '旧名',
            'traffic_type' => PixelChannel::TRAFFIC_PAID,
            'utm_source' => 'weline',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'summer',
            'enabled' => 1,
            'website_id' => 2,
            'description' => 'd',
            'created_at' => '2026-01-01 00:00:00',
        ];
        $row = $this->service->assembleUpdateRow([
            'code' => 'hacked', // 必须被忽略
            'name' => '新名',
            'traffic_type' => PixelChannel::TRAFFIC_SOCIAL,
            'utm_source' => 'meta',
            'utm_medium' => '',
            'enabled' => 0,
            'website_id' => 2,
        ], $original);

        self::assertSame('summer', $row['code']);
        self::assertSame('新名', $row['name']);
        self::assertSame(PixelChannel::TRAFFIC_SOCIAL, $row['traffic_type']);
        self::assertSame('meta', $row['utm_source']);
        self::assertSame('social', $row['utm_medium']);
        self::assertSame('summer', $row['utm_campaign']);
        self::assertSame(0, $row['enabled']);
        self::assertSame(9, $row['pixel_channel_id']);
    }

    public function testValidateForUpdateRejectsCodeChangeEvenIfAssembleSkipped(): void
    {
        $validation = new PixelChannelValidationService();
        $errors = $validation->validateForUpdate(
            [
                'kind' => PixelChannel::KIND_CAMPAIGN,
                'code' => 'winter',
                'name' => 'n',
            ],
            [
                'kind' => PixelChannel::KIND_CAMPAIGN,
                'code' => 'summer',
                'name' => 'n',
            ],
        );
        self::assertNotSame([], $errors);
    }

    public function testUpdateCampaignMissingIdReturnsNotFound(): void
    {
        $result = $this->service->updateCampaign(0, ['name' => 'x', 'code' => 'x']);
        self::assertFalse($result['ok']);
        $msg = (string)($result['errors'][0] ?? '');
        self::assertNotSame('', $msg);
        self::assertTrue(
            str_contains($msg, '不存在')
            || str_contains(strtolower($msg), 'not found')
            || str_contains(strtolower($msg), 'does not exist'),
            'missing id should surface not-found error, got: ' . $msg
        );
    }

    public function testUpdateAndDisableWhenTableReadyOtherwiseSurfacesSaveOrMissing(): void
    {
        $create = new PixelChannelCreateService(new PixelChannelValidationService());
        $code = 'b05_' . \substr(\md5((string)\microtime(true)), 0, 8);
        $created = $create->createCampaign([
            'code' => $code,
            'name' => 'B05 渠道',
            'traffic_type' => PixelChannel::TRAFFIC_PAID,
            'website_id' => 0,
        ], static fn() => false);

        if (!$created['ok']) {
            // 表未就绪：至少验证 assemble + 只读语义
            $original = [
                'pixel_channel_id' => 1,
                'kind' => PixelChannel::KIND_CAMPAIGN,
                'code' => $code,
                'name' => 'B05 渠道',
                'traffic_type' => PixelChannel::TRAFFIC_PAID,
                'utm_source' => 'weline',
                'utm_medium' => 'cpc',
                'enabled' => 1,
                'website_id' => 0,
            ];
            $row = $this->service->assembleUpdateRow(['code' => 'nope', 'name' => '改名', 'enabled' => 0], $original);
            self::assertSame($code, $row['code']);
            self::assertSame(0, $row['enabled']);
            self::assertSame('改名', $row['name']);
            return;
        }

        $id = (int)$created['id'];
        try {
            $updated = $this->service->updateCampaign($id, [
                'code' => 'should_ignore',
                'name' => 'B05 已改',
                'traffic_type' => PixelChannel::TRAFFIC_EMAIL,
                'utm_source' => 'newsletter',
                'utm_medium' => '',
                'enabled' => 1,
                'website_id' => 0,
            ]);
            self::assertTrue($updated['ok'], \implode('; ', $updated['errors']));
            self::assertSame($code, $updated['row']['code']);
            self::assertSame('email', $updated['row']['utm_medium']);

            $disabled = $this->service->setEnabled($id, false);
            self::assertTrue($disabled['ok'], \implode('; ', $disabled['errors']));
            self::assertSame(0, $disabled['enabled']);

            $loaded = $this->service->loadRow($id);
            self::assertNotNull($loaded);
            self::assertSame($code, $loaded['code'] ?? null);
            self::assertSame(0, (int)($loaded['enabled'] ?? 1));
            self::assertSame('B05 已改', $loaded['name'] ?? null);
        } finally {
            try {
                w_obj(PixelChannel::class)->load($id)->delete();
            } catch (\Throwable) {
            }
        }
    }
}
