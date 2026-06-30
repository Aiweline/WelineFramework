<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Blog;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class WordPressProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'wordpress',
        'title' => 'WordPress',
        'family' => 'blog',
        'region' => 'global',
        'auth_modes' => ['application_password', 'oauth2'],
        'capabilities' => ['publish_text', 'publish_image', 'publish_link'],
        'content_types' => ['text', 'image', 'link'],
        'required_config' => ['site_url', 'username', 'application_password'],
        'config_fields' => [
            ['key' => 'site_url', 'label' => 'Site URL', 'type' => 'url', 'required' => true],
            ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true],
            ['key' => 'application_password', 'label' => 'Application Password', 'type' => 'password', 'required' => true],
        ],
        'docs' => [
            'rest_auth' => 'https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/',
            'wordpress_com' => 'https://developer.wordpress.com/docs/api/getting-started/',
        ],
        'status' => 'documented_adapter_pending',
    ];
}

