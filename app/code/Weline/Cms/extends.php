<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'doc/功能现状.md',
    'extends' => [
        'UriInterceptSkip' => [
            'path' => 'extends/module/Weline_Cms/UriInterceptSkip',
            'interface' => 'Weline\Cms\Api\Uri\CmsUriInterceptSkipInterface',
            'description' => 'Declare URI identifiers CMS route interceptor must not rewrite.',
            'required' => false,
            'multiple' => true,
        ],
        'PageKind' => [
            'path' => 'extends/module/Weline_Cms/PageKind',
            'interface' => 'Weline\Cms\Api\Kind\CmsPageKindInterface',
            'description' => 'Inject CMS page kinds (path_group + Theme layout binding) for modules such as Help.',
            'required' => false,
            'multiple' => true,
        ],
    ],
];
