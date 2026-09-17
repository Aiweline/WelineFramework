<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * UPDATE 回写必须按字段合并，禁止 setData(array) 整表覆盖未变更列。
 */
final class AbstractModelUpdateMergeContractTest extends TestCase
{
    public function testCheckUpdateOrInsertMergesChangedFieldsWithoutArrayWipe(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Database/AbstractModel.php',
        );

        self::assertStringContainsString('getModelChangedData()', $source);
        self::assertStringContainsString('按字段合并回写', $source);
        self::assertStringContainsString('foreach ($data as $field => $value)', $source);
        self::assertStringContainsString('$this->setData((string)$field, $value)', $source);
        self::assertStringNotContainsString(
            "# 更新数据\n            \$this->setData(\$data);",
            $source,
        );
    }
}
