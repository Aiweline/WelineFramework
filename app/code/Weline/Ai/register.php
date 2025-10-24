<?php

declare(strict_types=1);

/**
 * Weline AI Module Registration
 * 
 * @package Weline_Ai
 * @author WelineFramework Team
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Ai',
    __DIR__,
    '1.0.0',
    'AI module for WelineFramework providing unified AI model management, assistant tools, API access control, multi-tenant isolation, and monitoring capabilities.'
);
