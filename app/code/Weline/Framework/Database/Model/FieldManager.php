<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Model;

use Weline\Framework\Database\AbstractModel;

/**
 * 模型字段发现、缓存与绑定（与 AbstractModel 协同，后续可迁入逻辑）
 * @since 1.0.0
 */
final class FieldManager
{
    public function getModelFields(AbstractModel $model, bool $remove_primary_key = false, bool $remove_force_check_fields = false): array
    {
        return $model->getModelFields($remove_primary_key, $remove_force_check_fields);
    }

    public function bindModelFields(AbstractModel $model, array $fields, string $alias = ''): AbstractModel
    {
        return $model->bindModelFields($fields, $alias);
    }
}
