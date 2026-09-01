<?php

declare(strict_types=1);

namespace Weline\Eav\Service\LocalTranslation;

use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Model\EavAttribute\Group;
use Weline\Eav\Model\EavAttribute\Group\LocalDescription as GroupLocalDescription;
use Weline\Eav\Model\EavAttribute\LocalDescription as AttributeLocalDescription;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Option\LocalDescription as OptionLocalDescription;
use Weline\Eav\Model\EavAttribute\Set;
use Weline\Eav\Model\EavAttribute\Set\LocalDescription as SetLocalDescription;
use Weline\Eav\Model\EavEntity;
use Weline\Eav\Model\EavEntity\LocalDescription as EntityLocalDescription;

final class EavLocalTranslationRegistry
{
    /**
     * @return array{model:class-string,field:string,id_field:string}
     */
    public static function resolve(string $nodeType): array
    {
        return match ($nodeType) {
            'entity' => [
                'model' => EntityLocalDescription::class,
                'field' => EavEntity::schema_fields_name,
                'id_field' => EntityLocalDescription::schema_fields_ID,
            ],
            'set' => [
                'model' => SetLocalDescription::class,
                'field' => Set::schema_fields_name,
                'id_field' => SetLocalDescription::schema_fields_ID,
            ],
            'group' => [
                'model' => GroupLocalDescription::class,
                'field' => Group::schema_fields_name,
                'id_field' => GroupLocalDescription::schema_fields_ID,
            ],
            'attribute' => [
                'model' => AttributeLocalDescription::class,
                'field' => EavAttribute::schema_fields_name,
                'id_field' => AttributeLocalDescription::schema_fields_ID,
            ],
            'option' => [
                'model' => OptionLocalDescription::class,
                'field' => Option::schema_fields_value,
                'id_field' => OptionLocalDescription::schema_fields_ID,
            ],
            default => throw new \InvalidArgumentException(__('未知 EAV 节点类型: %1', $nodeType)),
        };
    }

    /**
     * @return list<string>
     */
    public static function translatableNodeTypes(): array
    {
        return ['entity', 'set', 'group', 'attribute', 'option'];
    }
}
