<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Metadata;

use Weline\Eav\Api\Entity\EntityDefinitionInterface;

/** 可选的共享选项身份投影，不加载展示翻译。 */
interface AttributeOptionIdentityCatalogInterface
{
    /**
     * 保留目录属性/选项顺序及 ID、code、原始值别名。
     * 本投影的 label 是原始值；展示消费者继续使用 catalog()。
     *
     * @param list<string> $attributeCodes
     * @return array<string, list<AttributeOptionMetadata>>
     */
    public function sharedOptionIdentities(EntityDefinitionInterface $entity, array $attributeCodes): array;
}
