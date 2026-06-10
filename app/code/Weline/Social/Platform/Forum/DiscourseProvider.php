<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Forum;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class DiscourseProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'discourse',
        'title' => 'Discourse',
        'family' => 'forum',
        'region' => 'global',
        'auth_modes' => ['api_key'],
        'capabilities' => ['publish_text', 'publish_link'],
        'content_types' => ['text', 'link'],
        'required_config' => ['base_url', 'api_key', 'api_username'],
        'config_fields' => [
            ['key' => 'base_url', 'label' => 'Base URL', 'type' => 'url', 'required' => true],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
            ['key' => 'api_username', 'label' => 'API Username', 'type' => 'text', 'required' => true],
        ],
        'docs' => ['api' => 'https://docs.discourse.org/'],
        'status' => 'documented_adapter_pending',
    ];
}

