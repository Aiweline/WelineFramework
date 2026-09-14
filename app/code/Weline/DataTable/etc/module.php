<?php

return [
    "name" => 'Weline_DataTable',
    "version" => '1.1.1',
    "requires" => [
        'Weline_Framework' => '*',
        'Weline_Taglib' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        'request_resetter.Weline_DataTable' => \Weline\DataTable\Api\Runtime\RequestResetter::class,
    ],
];
