<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Model\EavAttribute\Set\LocalDescription;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\TaglibLocalDescriptionNormalizer;

final class TaglibLocalDescriptionNormalizerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            require \dirname(__DIR__, 7) . '/app/bootstrap.php';
        }
    }

    public function testRemapsParentIdAliasAndStripsUnknownColumns(): void
    {
        $model = ObjectManager::getInstance(LocalDescription::class);
        $rows = TaglibLocalDescriptionNormalizer::prepareRows($model, [[
            'local_code' => 'zh_Hans_CN',
            'set_id' => '4',
            'name' => '默认属性集',
            'local' => ['code' => 'zh_Hans_CN'],
        ]]);

        self::assertCount(1, $rows);
        self::assertSame('4', $rows[0]['id'] ?? null);
        self::assertSame('zh_Hans_CN', $rows[0]['local_code'] ?? null);
        self::assertSame('默认属性集', $rows[0]['name'] ?? null);
        self::assertArrayNotHasKey('set_id', $rows[0]);
        self::assertArrayNotHasKey('local', $rows[0]);
    }

    public function testUsesFallbackIdWhenMissing(): void
    {
        $model = ObjectManager::getInstance(LocalDescription::class);
        $rows = TaglibLocalDescriptionNormalizer::prepareRows($model, [[
            'local_code' => 'zh_Hans_CN',
            'name' => '默认属性集',
        ]], '4');

        self::assertSame('4', $rows[0]['id'] ?? null);
    }
}
