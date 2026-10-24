<?php

declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_RssEDemo',
    __DIR__,
    '1.1.0',
    'RSSE Demo module for local end-to-end verification',
    []
);
