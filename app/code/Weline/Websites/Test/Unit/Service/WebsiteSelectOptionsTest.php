<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\WebsiteSelectOptions;

final class WebsiteSelectOptionsTest extends TestCase
{
    public function testFromRowsKeepsZeroAndDedupes(): void
    {
        $options = WebsiteSelectOptions::fromRows([
            ['website_id' => 0, 'name' => 'Default', 'code' => 'default'],
            ['id' => 1, 'name' => 'Shop', 'code' => 'shop'],
            ['website_id' => 1, 'name' => 'Dup', 'code' => 'dup'],
            ['website_id' => -1, 'name' => 'Bad', 'code' => 'bad'],
            'skip',
        ]);

        self::assertSame(
            [
                ['value' => '0', 'label' => 'Default', 'meta' => 'default', 'code' => 'default'],
                ['value' => '1', 'label' => 'Shop', 'meta' => 'shop', 'code' => 'shop'],
            ],
            $options
        );
    }

    public function testFromRowsPrefersDomainInMetaAndKeepsUrl(): void
    {
        $options = WebsiteSelectOptions::fromRows([
            [
                'website_id' => 2,
                'name' => 'Demo',
                'code' => 'demo',
                'url' => 'https://demo.weline.test:9555/',
            ],
        ]);

        self::assertSame(
            [
                [
                    'value' => '2',
                    'label' => 'Demo',
                    'meta' => 'demo.weline.test',
                    'url' => 'https://demo.weline.test:9555',
                    'domain' => 'demo.weline.test',
                    'code' => 'demo',
                ],
            ],
            $options
        );
    }

    public function testResolveDisplay(): void
    {
        $options = [
            ['value' => '2', 'label' => 'Alpha', 'meta' => 'a'],
        ];
        self::assertSame('Alpha', WebsiteSelectOptions::resolveDisplay($options, '2'));
        self::assertSame('#9', WebsiteSelectOptions::resolveDisplay($options, '9'));
        self::assertSame('', WebsiteSelectOptions::resolveDisplay($options, ''));
    }
}
