<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Model;

use Weline\Framework\Database\AbstractModel;

/**
 * 模型保存逻辑（与 AbstractModel 协同，后续可迁入 save/checkUpdateOrInsert 逻辑）
 * @since 1.0.0
 */
final class SaveHandler
{
    public function save(AbstractModel $model, string|array|bool|AbstractModel $data = '', string|array $sequence = []): bool|int
    {
        return $model->save($data, $sequence);
    }
}
