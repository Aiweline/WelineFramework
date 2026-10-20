<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\Repository;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;
use Weline\I18n\Service\Repository\LocaleRepository;

/**
 * Ch4：LocaleRepository installedActive 预热后二次获取 0 SQL。
 */
final class LocaleRepositoryL1CacheTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleRepository::clearProcessCache();
    }

    protected function tearDown(): void
    {
        LocaleRepository::clearProcessCache();
    }

    public function testSecondCallForSameDisplayLocaleDoesNotQueryDb(): void
    {
        $locals = new LocaleRepositoryL1CacheLocals([
            [
                Locals::schema_fields_CODE => 'en_US',
                Locals::schema_fields_NAME => 'English',
                Locals::schema_fields_FLAG => 'us',
                Locals::schema_fields_IS_ACTIVE => 1,
                Locals::schema_fields_IS_INSTALL => 1,
            ],
            [
                Locals::schema_fields_CODE => 'zh_Hans_CN',
                Locals::schema_fields_NAME => '简体中文',
                Locals::schema_fields_FLAG => 'cn',
                Locals::schema_fields_IS_ACTIVE => 1,
                Locals::schema_fields_IS_INSTALL => 1,
            ],
        ]);
        $repository = new LocaleRepository($locals, new LocaleRepositoryL1CacheI18n());

        $first = $repository->installedActive('en_US');
        $second = $repository->installedActive('en_US');

        self::assertSame(1, $locals->fetchCount);
        self::assertCount(2, $first);
        self::assertSame($first, $second);
        self::assertSame('English', $first[0]->displayName);

        $repository->installedActive('zh_Hans_CN');
        self::assertSame(2, $locals->fetchCount, 'different display locale must load its own slice');

        LocaleRepository::clearProcessCache();
        $repository->installedActive('en_US');
        self::assertSame(3, $locals->fetchCount, 'clearProcessCache must force a reload');
    }
}

final class LocaleRepositoryL1CacheLocals extends Locals
{
    public int $fetchCount = 0;

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function __call($method, $args)
    {
        if ($method === 'fetchArray') {
            $this->fetchCount++;

            return $this->rows;
        }

        return $this;
    }
}

final class LocaleRepositoryL1CacheI18n extends I18n
{
    public function __construct()
    {
    }

    public function getLocals(string $lang_code = 'zh_Hans_CN'): array
    {
        return [
            'en_US' => 'English',
            'zh_Hans_CN' => '简体中文',
        ];
    }
}
