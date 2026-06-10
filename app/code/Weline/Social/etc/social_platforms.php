<?php

declare(strict_types=1);

return [
    \Weline\Social\Platform\Fake\FakeBrowserProvider::class,
    \Weline\Social\Platform\Fediverse\MastodonProvider::class,
    \Weline\Social\Platform\Fediverse\MisskeyProvider::class,
    \Weline\Social\Platform\Fediverse\LemmyProvider::class,
    \Weline\Social\Platform\Blog\TumblrProvider::class,
    \Weline\Social\Platform\Blog\WordPressProvider::class,
    \Weline\Social\Platform\Blog\GhostProvider::class,
    \Weline\Social\Platform\Forum\DiscourseProvider::class,
    \Weline\Social\Platform\Social\BlueskyProvider::class,
    \Weline\Social\Platform\Messaging\TelegramProvider::class,
    \Weline\Social\Platform\Messaging\DiscordProvider::class,
    \Weline\Social\Platform\Messaging\LineProvider::class,
    \Weline\Social\Platform\Messaging\ViberProvider::class,
];

