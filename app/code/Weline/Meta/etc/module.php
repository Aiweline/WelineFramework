<?php

return [
    "name" => 'Weline_Meta',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
        'Weline_I18n' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Meta\Api\MetadataRepositoryInterface::class => \Weline\Meta\Service\Repository\MetadataRepository::class,
        \Weline\Meta\Api\MetaConfigRepositoryInterface::class => \Weline\Meta\Service\Repository\MetaConfigRepository::class,
        \Weline\Meta\Api\ParamDefinitionNormalizerInterface::class => \Weline\Meta\Service\ParamDefinitionNormalizer::class,
        'request_resetter.Weline_Meta' => \Weline\Meta\Api\Runtime\RequestResetter::class,
    ],
];
