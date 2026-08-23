<?php

return [
    "name" => 'Weline_Cms',
    "version" => '1.1.1',
    "requires" => [
        'Weline_Backend' => '*',
        'Weline_BackendActivity' => '*',
        'Weline_Theme' => '>=2.1.0',
        'Weline_Websites' => '*',
    ],
    "optional" => [
        'Weline_Seo' => '*',
        'Weline_Trash' => '*',
        'Weline_TranslationService' => '*',
    ],
    "provides" => [],
];
