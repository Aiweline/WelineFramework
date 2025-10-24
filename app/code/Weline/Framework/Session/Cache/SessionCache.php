<?php

namespace Weline\Framework\Session\Cache;

use Weline\Framework\Cache\CacheFactory;

class SessionCache extends CacheFactory
{
    public function __construct(string $identity = 'session_cache', string $tip = 'Session相关缓存', bool $permanently = true)
    {
        parent::__construct($identity, $tip, $permanently);
    }
}
