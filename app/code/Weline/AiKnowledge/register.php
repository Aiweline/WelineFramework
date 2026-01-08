<?php

declare(strict_types=1);

/**
 * Weline AiKnowledge Module Registration
 * 
 * This module provides AI-enhanced documentation and API discovery
 * via the Model Context Protocol (MCP) for WelineFramework.
 * 
 * @package Weline_AiKnowledge
 * @author WelineFramework Team
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_AiKnowledge',
    __DIR__,
    '1.0.0',
    'AI Knowledge module providing MCP server, hybrid search, and multi-dimensional documentation collectors for WelineFramework.'
);
