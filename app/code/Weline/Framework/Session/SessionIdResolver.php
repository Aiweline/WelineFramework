<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;

/**
 * 会话 id 的唯一解析口。
 *
 * 顺序：当前请求 Context 的 session.id → 入站 Cookie 头里的 WELINE_SESSID → 入站 cookie 袋。
 * 超全局只留在 WelineEnv 门面里，本类不直接读进程级输入表。
 */
final class SessionIdResolver
{
    public const COOKIE_NAME = 'WELINE_SESSID';

    public static function fromCurrentRequest(): string
    {
        $fromContext = Context::getCurrent()?->sessionId() ?? '';
        if ($fromContext !== '') {
            return $fromContext;
        }

        $cookieHeader = WelineEnv::inboundCookieHeader();

        if ($cookieHeader !== ''
            && \preg_match('/(?:^|;\s*)' . self::COOKIE_NAME . '=([^;]+)/', $cookieHeader, $matches)
        ) {
            return \trim((string)\urldecode($matches[1]));
        }

        return \trim(WelineEnv::inboundCookie(self::COOKIE_NAME));
    }
}
