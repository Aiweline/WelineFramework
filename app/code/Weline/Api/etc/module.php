<?php

return [
    "name" => 'Weline_Api',
    "version" => '1.0.0',
    "requires" => [
        'Weline_Acl' => '*',
        'Weline_Admin' => '*',
        'Weline_Backend' => '*',
        'Weline_Framework' => '*',
    ],
    "optional" => [
        'Weline_Customer' => '*',
    ],
    "provides" => [
        \Weline\Api\Api\Documentation\ApiDocumentationProviderInterface::class => \Weline\Api\Service\ApiDocService::class,
        \Weline\Framework\Service\Query\Auth\BinQueryAuthenticationMetadataProviderInterface::class => \Weline\Api\Api\BinQueryAuthenticationMetadataProvider::class,
        \Weline\Framework\Service\Query\Auth\BinQueryAuthenticatorInterface::class => \Weline\Api\Api\BinQueryAuthenticator::class,
    ],
];
