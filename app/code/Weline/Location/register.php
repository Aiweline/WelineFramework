<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Location',
    __DIR__,
    '1.0.0',
    'Location module for browser location and IP location lookup.',
    ['Weline_Framework', 'Weline_Theme']
);
