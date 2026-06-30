<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Model;

final class AbstractModelStateResetTestModel extends Model
{
    public const schema_table = 'codex_state_reset';
    public const schema_primary_key = 'subject_id';
    public const schema_fields_ID = 'subject_id';
    public const schema_fields_SUBJECT_TYPE = 'subject_type';
}

final class AbstractModelStateResetTest extends TestCase
{
    public function testClearDataDropsPreviousSaveIdentityState(): void
    {
        $model = new AbstractModelStateResetTestModel();
        $model->setData('subject_id', 582);
        $model->setInsertFlag(true);
        $model->setDeleteFlag(true);
        $model->setFindFieldsValue('subject_id');

        $this->setPrivate($model, 'force_check_flag', true);
        $this->setPrivate($model, 'force_check_fields', ['subject_id' => 'subject_id']);
        $this->setPrivate($model, 'remove_force_check_field', true);
        $this->setPrivate($model, 'unique_data', ['subject_id' => 582]);

        $model->clearData(false);

        self::assertSame([], $model->getData());
        self::assertFalse($model->getIsInsert());
        self::assertFalse($model->getIsDelete());
        self::assertSame('', $model->getFindFieldsValue());
        self::assertFalse($this->getPrivate($model, 'force_check_flag'));
        self::assertSame([], $this->getPrivate($model, 'force_check_fields'));
        self::assertFalse($this->getPrivate($model, 'remove_force_check_field'));
        self::assertSame([], $this->getPrivate($model, 'unique_data'));
    }

    private function setPrivate(AbstractModel $model, string $name, mixed $value): void
    {
        $property = new ReflectionProperty(AbstractModel::class, $name);
        $property->setAccessible(true);
        $property->setValue($model, $value);
    }

    private function getPrivate(AbstractModel $model, string $name): mixed
    {
        $property = new ReflectionProperty(AbstractModel::class, $name);
        $property->setAccessible(true);
        return $property->getValue($model);
    }
}
