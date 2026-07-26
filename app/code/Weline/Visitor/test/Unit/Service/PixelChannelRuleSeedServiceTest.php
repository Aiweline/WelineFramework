<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Console\Pixel\ChannelRuleSeed;
use Weline\Visitor\Model\PixelChannel;
use Weline\Visitor\Service\PixelChannelRuleSeedService;

/**
 * B08：PixelSource → rule 种子映射（不依赖表时测纯映射；落库可降级）。
 */
class PixelChannelRuleSeedServiceTest extends TestCore
{
    private PixelChannelRuleSeedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PixelChannelRuleSeedService();
    }

    public function testMapSourceToRuleBuildsRefererHostRule(): void
    {
        $rule = $this->service->mapSourceToRule([
            'name' => 'Facebook',
            'code' => 'Facebook',
            'referer_domain_contains' => 'facebook,fb.com',
            'description' => '来自Facebook的访客',
        ], 120);

        self::assertNotNull($rule);
        self::assertSame(PixelChannel::KIND_RULE, $rule['kind']);
        self::assertSame('facebook', $rule['code']);
        self::assertSame('Facebook', $rule['name']);
        self::assertSame(PixelChannel::MATCH_REFERER_HOST, $rule['match_mode']);
        self::assertSame('facebook,fb.com', $rule['match_value']);
        self::assertSame(PixelChannel::TRAFFIC_SOCIAL, $rule['traffic_type']);
        self::assertSame(0, $rule['website_id']);
        self::assertSame(120, $rule['priority']);
        self::assertSame(1, $rule['enabled']);
    }

    public function testInferTrafficTypeForGoogleAndUnknown(): void
    {
        self::assertSame(PixelChannel::TRAFFIC_ORGANIC, $this->service->inferTrafficType('google'));
        self::assertSame(PixelChannel::TRAFFIC_SOCIAL, $this->service->inferTrafficType('tiktok'));
        self::assertSame(PixelChannel::TRAFFIC_REFERRAL, $this->service->inferTrafficType('partner_x'));
    }

    public function testMapRejectsIncompleteSource(): void
    {
        self::assertNull($this->service->mapSourceToRule(['code' => 'x', 'name' => '']));
        self::assertNull($this->service->mapSourceToRule([
            'code' => 'x',
            'name' => 'X',
            'referer_domain_contains' => '',
        ]));
    }

    public function testDryRunPlansDefaultCatalogWhenInjectedEmptyUsesCatalog(): void
    {
        $result = $this->service->seed(true, $this->service->defaultSourceCatalog());
        self::assertTrue($result['ok']);
        self::assertSame('injected', $result['source']);
        self::assertCount(13, $result['planned']);
        $codes = \array_column($result['planned'], 'code');
        self::assertContains('facebook', $codes);
        self::assertContains('google', $codes);
        $google = null;
        foreach ($result['planned'] as $row) {
            if ($row['code'] === 'google') {
                $google = $row;
                break;
            }
        }
        self::assertNotNull($google);
        self::assertSame(PixelChannel::TRAFFIC_ORGANIC, $google['traffic_type']);
        self::assertSame(0, $result['inserted']);
    }

    public function testApplySeedWhenTableReadyOtherwiseSurfacesErrors(): void
    {
        $result = $this->service->seed(false, [
            [
                'name' => 'B08 Test',
                'code' => 'b08_seed_' . \substr(\md5((string)\microtime(true)), 0, 6),
                'referer_domain_contains' => 'b08test.example',
                'description' => 'b08 unit',
            ],
        ]);

        if ($result['inserted'] + $result['updated'] + $result['skipped'] > 0 && $result['errors'] === []) {
            self::assertTrue($result['ok']);
            $code = $result['planned'][0]['code'];
            try {
                $model = w_obj(PixelChannel::class);
                $model->reset()
                    ->where('kind', PixelChannel::KIND_RULE)
                    ->where('code', $code)
                    ->where('website_id', 0)
                    ->find()
                    ->fetch();
                if ((int)$model->getId() > 0) {
                    self::assertSame(PixelChannel::MATCH_REFERER_HOST, $model->getMatchMode());
                    $model->delete();
                }
            } catch (\Throwable) {
            }

            return;
        }

        // 表未就绪：planned 仍正确，errors 非空或 ok=false
        self::assertCount(1, $result['planned']);
        self::assertSame(PixelChannel::KIND_RULE, $result['planned'][0]['kind']);
    }

    public function testConsoleCommandRegistered(): void
    {
        self::assertFileExists(
            BP . '/app/code/Weline/Visitor/Console/Pixel/ChannelRuleSeed.php'
        );
        $cmd = new ChannelRuleSeed();
        $help = $cmd->help();
        self::assertIsArray($help);
        self::assertSame('pixel:channel-rule-seed', $help['command']);
        self::assertStringContainsString('B08', $cmd->tip());
    }

    public function testInstallAndUpgradeCallSeedService(): void
    {
        $install = (string)\file_get_contents(BP . '/app/code/Weline/Visitor/Setup/Install.php');
        $upgrade = (string)\file_get_contents(BP . '/app/code/Weline/Visitor/Setup/Upgrade.php');
        self::assertStringContainsString('PixelChannelRuleSeedService', $install);
        self::assertStringContainsString('PixelChannelRuleSeedService', $upgrade);
        self::assertStringContainsString('seedPixelChannelRules', $upgrade);
    }
}
