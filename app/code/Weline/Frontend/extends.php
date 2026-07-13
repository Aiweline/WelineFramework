<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'extends.md',
    'extends' => [
        'HeadContextProvider' => [
            'path' => 'extends/module/Weline_Frontend/HeadContextProvider',
            'interface' => 'Weline\Framework\View\Head\HeadContextProviderInterface',
            'description' => 'Frontend head context provider for title input data.',
            'required' => false,
            'multiple' => true,
        ],
        'HeadPolicyProvider' => [
            'path' => 'extends/module/Weline_Frontend/HeadPolicyProvider',
            'interface' => 'Weline\Frontend\Interface\HeadPolicyProviderInterface',
            'description' => 'Frontend head policy provider for title formatting rules.',
            'required' => false,
            'multiple' => true,
        ],
    ],
];
