<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\TwoFactorAuth\Model\UserTwoFactor;

final class UserTwoFactorGetByUserIdTest extends TestCase
{
    public function testGetByUserIdReturnsNullWhenFetchOnlyProvidesAnEmptyModel(): void
    {
        $model = new class([]) extends UserTwoFactor {
            public function where(array|string $field, mixed $value = null, string $con = '=', string $logic = 'AND', string $array_where_logic_type = 'and'): static
            {
                return $this;
            }

            public function find(string $find_fields = ''): static
            {
                return $this;
            }

            public function fetch(string $model_class = ''): static
            {
                $this->clearData(false);
                return $this;
            }
        };

        self::assertNull($model->getByUserId(3));
    }

    public function testGetByUserIdReturnsModelWhenFetchedRecordContainsPrimaryKey(): void
    {
        $model = new class([]) extends UserTwoFactor {
            public function where(array|string $field, mixed $value = null, string $con = '=', string $logic = 'AND', string $array_where_logic_type = 'and'): static
            {
                return $this;
            }

            public function find(string $find_fields = ''): static
            {
                return $this;
            }

            public function fetch(string $model_class = ''): static
            {
                $this->setData(self::schema_fields_ID, 3);
                $this->setData(self::schema_fields_USER_ID, 9);
                return $this;
            }
        };

        $record = $model->getByUserId(9);

        self::assertInstanceOf(UserTwoFactor::class, $record);
        self::assertSame(3, $record->getData(UserTwoFactor::schema_fields_ID));
        self::assertSame(9, $record->getData(UserTwoFactor::schema_fields_USER_ID));
    }
}
