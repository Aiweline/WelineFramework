<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;
use Weline\Framework\Runtime\RequestContext;

final class ContextSnapshot
{
    /** @return array<string,mixed> */
    public function capture(?int $websiteId = null, ?string $websiteCode = null): array
    {
        $websiteId ??= WelineEnv::getWebsiteId();
        $websiteCode = trim($websiteCode ?? WelineEnv::getWebsiteCode());
        if ($websiteId === null || $websiteId < 0 || $websiteCode === '') {
            throw new AsyncEventValidationException(
                __('异步事件必须显式解析 website_id/website_code；缺失不能伪装为默认站点'),
            );
        }
        $userId = WelineEnv::get('user.id', null);
        $area = match (WelineEnv::getArea()) {
            'rest_backend' => 'backend',
            'rest_frontend' => 'frontend',
            default => WelineEnv::getArea(),
        };
        $userType = match ($area) {
            'backend' => 'admin',
            'frontend' => 'customer',
            default => 'system',
        };
        return [
            'website_id' => $websiteId,
            'website_code' => $websiteCode,
            'lang' => WelineEnv::getLang(),
            'currency' => WelineEnv::getCurrency(),
            'area' => $area,
            'timezone' => date_default_timezone_get(),
            'user' => [
                'type' => $userType,
                'id' => $userId === null ? null : (int)$userId,
            ],
        ];
    }

    public function validate(array $context): void
    {
        $required = ['website_id', 'website_code', 'lang', 'currency', 'area', 'timezone', 'user'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $context)) {
                throw new AsyncEventValidationException(__('异步事件上下文缺少字段：%{1}', [$key]));
            }
        }
        if (array_diff(array_keys($context), $required) !== []) {
            throw new AsyncEventValidationException(__('异步事件上下文包含非白名单字段'));
        }
        if (!is_int($context['website_id'])
            || $context['website_id'] < 0
            || !is_string($context['website_code'])
            || trim($context['website_code']) === ''
            || strlen($context['website_code']) > 64) {
            throw new AsyncEventValidationException(__('异步事件网站上下文无效'));
        }
        if (!in_array((string)$context['area'], ['frontend', 'backend', 'api', 'cli'], true)) {
            throw new AsyncEventValidationException(__('异步事件 area 上下文无效'));
        }
        foreach (['lang', 'currency', 'area', 'timezone'] as $scalarKey) {
            if (!is_string($context[$scalarKey]) || strlen($context[$scalarKey]) > 128) {
                throw new AsyncEventValidationException(__('异步事件 %{1} 上下文无效', [$scalarKey]));
            }
        }
        if (!in_array($context['timezone'], timezone_identifiers_list(), true)) {
            throw new AsyncEventValidationException(__('异步事件 timezone 上下文无效'));
        }
        $user = $context['user'];
        if (!is_array($user) || array_diff(array_keys($user), ['type', 'id']) !== []) {
            throw new AsyncEventValidationException(__('异步事件 user 上下文只允许 type/id 审计字段'));
        }
        if (!in_array((string)($user['type'] ?? ''), ['admin', 'customer', 'system'], true)
            || (($user['id'] ?? null) !== null && (!is_int($user['id']) || $user['id'] < 0))) {
            throw new AsyncEventValidationException(__('异步事件 user 审计上下文无效'));
        }
    }

    public function runWith(array $context, callable $callback): mixed
    {
        $this->validate($context);
        $env = WelineEnv::getInstance();
        $previous = $env->capture();
        $hadPreviousContext = (bool)($previous['initialized'] ?? false);
        $previousEnvShadow = [];
        foreach (RequestContext::all() as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'env.')) {
                $previousEnvShadow[$key] = $value;
            }
        }
        $previousTimezone = date_default_timezone_get();
        try {
            // Enter a clean system Context so audit metadata in context.user can
            // never become ambient admin/customer authority. Request input,
            // cookies, session, CSRF and arbitrary env shadows are all absent.
            $env->reset();
            Context::enter(new Context([
                'meta' => [
                    'type' => 'system',
                    'process_tag' => 'async-event',
                ],
                'input' => [
                    'query' => [],
                    'post' => [],
                    'cookie' => [],
                    'files' => [],
                    'headers' => [],
                    'server' => [],
                    'body' => '',
                ],
                'session' => [
                    'id' => '',
                    'user_id' => 0,
                    'authenticated' => false,
                    'csrf' => '',
                ],
            ]));
            WelineEnv::set('website_id', (int)$context['website_id'], 'async_event_restore');
            WelineEnv::set('website_code', (string)$context['website_code'], 'async_event_restore');
            WelineEnv::setLang((string)$context['lang']);
            WelineEnv::setCurrency((string)$context['currency']);
            WelineEnv::setArea((string)$context['area']);
            date_default_timezone_set((string)$context['timezone']);
            return $callback();
        } finally {
            date_default_timezone_set($previousTimezone);
            $env->reset();
            if ($hadPreviousContext) {
                $env->restore($previous);
            }
            foreach ($previousEnvShadow as $key => $value) {
                RequestContext::set($key, $value);
            }
        }
    }
}
