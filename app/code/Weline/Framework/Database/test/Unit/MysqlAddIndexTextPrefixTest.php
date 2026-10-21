<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Mysql\Connector;
use Weline\Framework\Database\DbManager\ConfigProvider;

final class MysqlAddIndexTextPrefixTest extends TestCase
{
    public function testCompositeTextIndexUses191Prefix(): void
    {
        $sql = $this->connector()->buildAddIndexSql('`weline`.`w_i18n_locale_dictionary`', [
            'name' => 'idx_locale_word',
            'columns' => ['locale_code', 'word'],
            'type' => 'INDEX',
            'columnTypes' => [
                'locale_code' => 'varchar',
                'word' => 'text',
            ],
        ]);

        self::assertSame(
            'ALTER TABLE `weline`.`w_i18n_locale_dictionary` ADD INDEX `idx_locale_word` (`locale_code`,`word`(191))',
            $sql,
        );
    }

    public function testExplicitPrefixAndFulltextStayUntouched(): void
    {
        $connector = $this->connector();
        $explicit = $connector->buildAddIndexSql('dictionary', [
            'name' => 'idx_word_short',
            'columns' => ['word(64)'],
            'type' => 'INDEX',
            'columnTypes' => ['word' => 'text'],
        ]);
        $fulltext = $connector->buildAddIndexSql('dictionary', [
            'name' => 'ft_word',
            'columns' => ['word'],
            'type' => 'FULLTEXT',
            'columnTypes' => ['word' => 'text'],
        ]);

        self::assertStringContainsString('`word`(64)', $explicit);
        self::assertStringNotContainsString('`word`(191)', $explicit);
        self::assertStringContainsString('ADD FULLTEXT INDEX `ft_word` (`word`)', $fulltext);
        self::assertStringNotContainsString('(191)', $fulltext);
    }

    private function connector(): Connector
    {
        return new Connector(new ConfigProvider([
            'type' => 'mysql',
            'hostname' => '127.0.0.1',
            'database' => 'weline',
            'username' => 'weline',
            'password' => 'weline',
            'hostport' => '3306',
            'prefix' => 'w_',
            'charset' => 'utf8mb4',
            'collate' => 'utf8mb4_unicode_ci',
        ]));
    }
}
