<?php

return [
    "name" => 'Weline_Eav',
    "version" => '1.1.1',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Framework\Setup\Stage\EavSchemaProviderInterface::class => \Weline\Eav\Api\SchemaProvider::class,
        \Weline\Eav\Api\Attribute\EntityAttributeStoreInterface::class => \Weline\Eav\Service\EntityAttributeStore::class,
        \Weline\Eav\Api\Attribute\Option\AttributeOptionStoreInterface::class => \Weline\Eav\Service\AttributeOptionStore::class,
        \Weline\Eav\Api\Attribute\Type\AttributeTypeRegistryInterface::class => \Weline\Eav\Service\AttributeTypeRegistry::class,
        \Weline\Eav\Api\Options\EavOptionsQueryInterface::class => \Weline\Eav\Service\EavOptionsQuery::class,
    ],
];
