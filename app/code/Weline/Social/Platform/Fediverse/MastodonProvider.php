<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Fediverse;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class MastodonProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'mastodon',
        'title' => 'Mastodon',
        'family' => 'fediverse',
        'region' => 'global',
        'auth_modes' => ['oauth2'],
        'capabilities' => ['publish_text', 'publish_image', 'schedule'],
        'content_types' => ['text', 'image', 'link'],
        'required_config' => ['instance_url', 'client_id', 'client_secret'],
        'config_fields' => [
            ['key' => 'instance_url', 'label' => 'Instance URL', 'type' => 'url', 'required' => true],
            ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
            ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
        ],
        'docs' => [
            'statuses' => 'https://docs.joinmastodon.org/methods/statuses/',
            'oauth' => 'https://docs.joinmastodon.org/spec/oauth/',
        ],
        'status' => 'documented_adapter_pending',
    ];
}

