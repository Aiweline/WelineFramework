<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Fediverse;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class MisskeyProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'misskey',
        'title' => 'Misskey',
        'family' => 'fediverse',
        'region' => 'global',
        'auth_modes' => ['token'],
        'capabilities' => ['publish_text', 'publish_image'],
        'content_types' => ['text', 'image', 'link'],
        'required_config' => ['instance_url', 'access_token'],
        'config_fields' => [
            ['key' => 'instance_url', 'label' => 'Instance URL', 'type' => 'url', 'required' => true],
            ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
        ],
        'docs' => ['api' => 'https://misskey.io/api-doc'],
        'status' => 'documented_adapter_pending',
    ];
}

