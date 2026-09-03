<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Blog',
    'version' => '1.0.10',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Websites' => '*',
        'Weline_Theme' => '*',
        'Weline_DataTable' => '*',
        'Weline_I18n' => '*',
        'Weline_FileManager' => '*',
    ],
    'optional' => [
        'Weline_Cms' => '*',
        'Weline_Search' => '*',
        'Weline_Seo' => '*',
        'Weline_Catalog' => '*',
        'Weline_I18n' => '*',
        'Weline_Review' => '*',
    ],
    'provides' => [],
];
