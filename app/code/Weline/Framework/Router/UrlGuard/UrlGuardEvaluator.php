<?php

declare(strict_types=1);

/**
 * URL Guard 执行器
 *
 * 负责对一次请求迭代所有已注册 Guard 并返回最终决策。
 * 单一职责：不负责具体规则、不负责事件分发；事件由 Observer 触发。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Router\UrlGuard;

class UrlGuardEvaluator
{
    public function __construct(private UrlGuardRegistry $registry)
    {
    }

    /**
     * 对请求进行 Guard 评估。
     *
     * 任意一个 Guard 决策 reject 即立刻返回；其它 Guard 不再继续，
     * 由调用方处理（拒绝响应 + 触发 overflow 事件）。
     *
     * @param array<string, mixed> $params
     * @param array<string, string|array<string, string>> $headers
     */
    public function evaluate(string $uri, array $params, array $headers = []): GuardDecision
    {
        foreach ($this->registry->all() as $guard) {
            if (!$guard->matches($uri, $params, $headers)) {
                continue;
            }

            $decision = $guard->evaluate($uri, $params, $headers);
            if ($decision->isReject()) {
                return $decision;
            }
        }

        return GuardDecision::pass('__none__');
    }
}
