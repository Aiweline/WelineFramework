<?php

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Geo',
    __DIR__,
    '1.0.0',
    'Generative Engine Optimization module for AI discovery feeds and platform push adapters.',
    ['Weline_Framework', 'Weline_Backend', 'Weline_I18n', 'Weline_Seo']
);
