<?php

return [
    "name" => 'Weline_Component',
    "version" => '1.0.1',
    "requires" => [
        'Weline_Admin' => '*',
        'Weline_Framework' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Component\Api\OffCanvasRendererInterface::class => \Weline\Component\Service\OffCanvasRenderer::class,
    ],
];
