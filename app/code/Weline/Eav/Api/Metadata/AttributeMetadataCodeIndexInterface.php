<?php
declare(strict_types=1);

namespace Weline\Eav\Api\Metadata;

/** 从已解析的只读目录获取属性引用；不再读取范围、语言或数据库。 */
interface AttributeMetadataCodeIndexInterface
{
    /**
     * 保留目录中的挂载顺序和重复属性；调用方继续决定显示和选项去重规则。
     *
     * @param list<AttributeSetMetadata> $sets
     * @return list<AttributeMetadata>
     */
    public function attributesByCode(array $sets, string $attributeCode): array;
}
