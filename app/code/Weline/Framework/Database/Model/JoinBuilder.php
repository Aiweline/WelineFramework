<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Model;

use Weline\Framework\Database\AbstractModel;

/**
 * JOIN 模型逻辑（与 AbstractModel 协同，后续可迁入 joinModel 逻辑）
 * @since 1.0.0
 */
final class JoinBuilder
{
    public function joinModel(
        AbstractModel $model,
        AbstractModel|string $joinModel,
        string $alias,
        string $condition,
        string $type,
        string $fields
    ): AbstractModel {
        return $model->joinModel($joinModel, $alias, $condition, $type, $fields);
    }
}
