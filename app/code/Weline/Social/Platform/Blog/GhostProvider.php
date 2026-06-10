<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Blog;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class GhostProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'ghost',
        'title' => 'Ghost',
        'family' => 'blog',
        'region' => 'global',
        'auth_modes' => ['admin_jwt'],
        'capabilities' => ['publish_text', 'publish_image', 'publish_link'],
        'content_types' => ['text', 'image', 'link'],
        'required_config' => ['admin_api_url', 'admin_api_key'],
        'config_fields' => [
            ['key' => 'admin_api_url', 'label' => 'Admin API URL', 'type' => 'url', 'required' => true],
            ['key' => 'admin_api_key', 'label' => 'Admin API Key', 'type' => 'password', 'required' => true],
        ],
        'docs' => ['admin_api' => 'https://docs.ghost.org/admin-api/', 'posts' => 'https://docs.ghost.org/admin-api/posts/overview'],
        'status' => 'documented_adapter_pending',
    ];
}

