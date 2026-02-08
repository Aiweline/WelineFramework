<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Model;

use Weline\Framework\Database\AbstractModel;

/**
 * 分页 HTML 构建（与 AbstractModel 协同，后续可迁入 getPagination 逻辑）
 * @since 1.0.0
 */
final class PaginationBuilder
{
    public function getPagination(AbstractModel $model, string $pagination_style, string $url_path): string
    {
        return $model->getPagination($pagination_style, $url_path);
    }

    public function getPaginationData(AbstractModel $model, string $url_path, string $pagination_style): array
    {
        return $model->getPaginationData($url_path, $pagination_style);
    }
}
