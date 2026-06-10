<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Fediverse;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class LemmyProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'lemmy',
        'title' => 'Lemmy',
        'family' => 'fediverse',
        'region' => 'global',
        'auth_modes' => ['token'],
        'capabilities' => ['publish_link', 'publish_text'],
        'content_types' => ['text', 'link'],
        'required_config' => ['instance_url', 'access_token'],
        'config_fields' => [
            ['key' => 'instance_url', 'label' => 'Instance URL', 'type' => 'url', 'required' => true],
            ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
        ],
        'docs' => ['api' => 'https://join-lemmy.org/docs/en/contributors/04-api.html'],
        'status' => 'documented_adapter_pending',
    ];
}

