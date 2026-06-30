<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Blog;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class TumblrProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'tumblr',
        'title' => 'Tumblr',
        'family' => 'blog',
        'region' => 'global',
        'auth_modes' => ['oauth1', 'oauth2'],
        'capabilities' => ['publish_text', 'publish_image', 'publish_link'],
        'content_types' => ['text', 'image', 'link'],
        'required_config' => ['client_id', 'client_secret', 'blog_identifier'],
        'config_fields' => [
            ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
            ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
            ['key' => 'blog_identifier', 'label' => 'Blog Identifier', 'type' => 'text', 'required' => true],
        ],
        'docs' => ['api_v2' => 'https://www.tumblr.com/docs/en/api/v2', 'npf' => 'https://www.tumblr.com/docs/npf'],
        'status' => 'documented_adapter_pending',
    ];
}

