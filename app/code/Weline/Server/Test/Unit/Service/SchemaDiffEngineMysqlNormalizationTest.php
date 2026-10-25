<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Manager\ObjectManager;

/**
 * RSSE 误报 contract 修复验收（changanhanfu.com 2026-10-25 事故）。
 *
 * 4 组 MySQL/MariaDB 物理形态 vs 语义声明差异，在修复前 columnEquals 全部
 * 返回 false（误报 modify_column），导致 setup:upgrade 永远拒绝 commit：
 *   1) text NOT NULL DEFAULT '{}'   vs observed default NULL（TEXT 家族不存字面默认）
 *   2) boolean default 0            vs tinyint(1) default '0'
 *   3) json nullable                vs longtext default 'NULL'（字符串字面量）
 *   4) text default '["text"]'      vs observed default NULL
 */
final class SchemaDiffEngineMysqlNormalizationTest extends TestCase
{
    private SchemaDiffEngine $engine;

    /** @var \ReflectionMethod */
    private $columnEquals;

    protected function setUp(): void
    {
        $this->engine = ObjectManager::getInstance(SchemaDiffEngine::class);
        $this->columnEquals = new \ReflectionMethod($this->engine, 'columnEquals');
        $this->columnEquals->setAccessible(true);
    }

    private function col(string $type, bool $nullable, mixed $default, ?string $length = null): ColumnDefinition
    {
        return new ColumnDefinition(
            name: 'c',
            type: $type,
            length: $length,
            nullable: $nullable,
            primaryKey: false,
            autoIncrement: false,
            default: $default,
            comment: '',
            unique: false,
        );
    }

    private function equals(ColumnDefinition $declared, ColumnDefinition $actual): bool
    {
        return (bool) $this->columnEquals->invoke($this->engine, $declared, $actual, 'mariadb');
    }

    public function testTextSemanticDefaultVsNullObserved(): void
    {
        self::assertTrue($this->equals(
            $this->col('text', false, '{}'),
            $this->col('text', false, null),
        ), 'text 语义默认值 vs MariaDB NULL 物理默认应视为兼容');
    }

    public function testTextSemanticJsonDefaultVsNullObserved(): void
    {
        self::assertTrue($this->equals(
            $this->col('text', true, '["text"]'),
            $this->col('text', true, null),
        ), 'text 语义 JSON 默认值 vs NULL 观察值应视为兼容');
    }

    public function testBooleanDeclaredVsTinyintOneObserved(): void
    {
        self::assertTrue($this->equals(
            $this->col('boolean', false, '0'),
            $this->col('tinyint', false, '0', '1'),
        ), 'boolean 声明 vs tinyint(1) 物理列应视为兼容');
    }

    public function testTinyintDeclaredVsBooleanObserved(): void
    {
        self::assertTrue($this->equals(
            $this->col('tinyint', false, '0', '1'),
            $this->col('boolean', false, '0'),
        ), 'tinyint(1) 声明 vs boolean 物理列应视为兼容（对称）');
    }

    public function testJsonDeclaredVsLongtextObserved(): void
    {
        self::assertTrue($this->equals(
            $this->col('json', true, null),
            $this->col('longtext', true, 'NULL'),
        ), 'json 声明 vs longtext 物理列（含 NULL 字符串字面量默认）应视为兼容');
    }

    public function testRealTypeChangeStillRejected(): void
    {
        // 反例：真实类型变更不得放行
        self::assertFalse($this->equals(
            $this->col('text', false, null),
            $this->col('int', false, null),
        ), 'text vs int 是真实类型变更，必须继续报差异');
    }

    public function testRealDefaultChangeStillRejected(): void
    {
        // 反例：同为 text 且 observed 有真实默认值时不得豁免
        self::assertFalse($this->equals(
            $this->col('text', true, 'a'),
            $this->col('text', true, 'b'),
        ), 'text 两个不同字面默认值是真实差异');
    }

    public function testIntLengthChangeStillRejected(): void
    {
        // 反例：int 长度差异不在豁免范围
        self::assertFalse($this->equals(
            $this->col('int', false, null, '11'),
            $this->col('bigint', false, null, '20'),
        ), 'int vs bigint 是真实类型变更');
    }
}
