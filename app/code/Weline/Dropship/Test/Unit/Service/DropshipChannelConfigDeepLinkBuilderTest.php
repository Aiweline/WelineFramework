<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Service\DropshipChannelConfigDeepLinkBuilder;
use Weline\Framework\Http\UrlInterface;

final class DropshipChannelConfigDeepLinkBuilderTest extends TestCase
{
    public function testBuildRequiresModuleAndReturnsSystemConfigUrl(): void
    {
        $url = $this->createMock(UrlInterface::class);
        $url->expects(self::once())
            ->method('getBackendUrl')
            ->with(
                'weline_systemconfig/backend/config',
                self::callback(static function (array $params): bool {
                    return ($params['module'] ?? '') === 'Weline_CjDropshipping'
                        && ($params['area'] ?? '') === 'backend'
                        && ($params['guide_key'] ?? '') === 'dropship/channel/cj/email'
                        && ($params['guide_locate'] ?? '') === 'dropship/channel/cj/email';
                }),
                false
            )
            ->willReturn('https://example.test/admin/weline_systemconfig/backend/config?module=Weline_CjDropshipping');

        $builder = new DropshipChannelConfigDeepLinkBuilder($url);
        $built = $builder->build([
            'module' => 'Weline_CjDropshipping',
            'area' => 'backend',
            'guide_key' => 'dropship/channel/cj/email',
            'guide_title' => 'CJ 凭证',
        ]);

        self::assertStringContainsString('weline_systemconfig/backend/config', $built);
        self::assertStringContainsString('Weline_CjDropshipping', $built);
    }

    public function testBuildEmptyWithoutModule(): void
    {
        $url = $this->createMock(UrlInterface::class);
        $url->expects(self::never())->method('getBackendUrl');
        $builder = new DropshipChannelConfigDeepLinkBuilder($url);
        self::assertSame('', $builder->build(['guide_key' => 'x']));
    }
}
