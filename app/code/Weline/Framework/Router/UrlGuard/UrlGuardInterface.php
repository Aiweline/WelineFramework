<?php

declare(strict_types=1);

/**
 * URL Guard 接口（OCP 扩展点）
 *
 * 实现者负责判定一条具体 URL/请求是否合法。
 * 单一职责：每个 Guard 只解决一类问题（id 越界、参数白名单、路径长度等）。
 *
 * 工作流：
 * 1. UrlGuardEvaluator 遍历注册的所有 Guard；
 * 2. 对每个 Guard 先调 matches() 判定是否适用本次请求；
 * 3. 适用则调 evaluate()，得到 GuardDecision；
 * 4. 任意一个 Guard 返回 reject → Evaluator 拒绝整个请求并触发 overflow 事件。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Router\UrlGuard;

interface UrlGuardInterface
{
    /**
     * Guard 唯一名称（用于日志、CDN 规则透传与单元测试定位）。
     */
    public function getName(): string;

    /**
     * 当前 Guard 是否适用此请求 URI / 参数。
     *
     * @param string $uri 已规范化的请求 URI（不含 querystring）
     * @param array<string, mixed> $params 合并后的请求参数（GET + Body）
     * @param array<string, string|array<string, string>> $headers 标准化的请求头
     */
    public function matches(string $uri, array $params, array $headers = []): bool;

    /**
     * 评估请求是否越界。
     *
     * @param string $uri
     * @param array<string, mixed> $params
     * @param array<string, string|array<string, string>> $headers
     */
    public function evaluate(string $uri, array $params, array $headers = []): GuardDecision;
}
