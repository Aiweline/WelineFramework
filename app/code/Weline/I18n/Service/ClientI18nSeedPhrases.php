<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

/**
 * Client JS bag must include chrome + tip sources even when a page never calls __() on them.
 *
 * @return list<string>
 */
final class ClientI18nSeedPhrases
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            // Head chrome (was runtime-config i18n.close/confirm/…)
            '关闭',
            '确定',
            '取消',
            '请重试',
            '未找到可提交的表单。',
            // Scope wait tip + common Api presentation (also extracted from JS; seed is fail-closed)
            '请稍等片刻...',
            '源站暂时不可用',
            '服务暂时不可用',
            '请求被拒绝',
            '登录状态异常',
            '接口返回了网页',
            '接口响应格式异常',
            '协议错误',
            '网络异常',
            '商城范围已变更',
            '商城范围未就绪',
            '商城范围冲突',
            '站点已更新',
            '请求失败',
            '查看详情',
            '请求失败，请稍后重试。',
            '服务器繁忙或维护中，请稍后重试。',
            'CDN 连不上源站（常见 HTTP 521）。请稍后重试；若持续失败请检查源站与边缘配置。',
            '当前请求未被允许，可能被安全策略拦截。',
            '请刷新页面后重新登录再试。',
            '期望二进制接口响应，却收到了 HTML（登录页、错误页或网关页）。',
            '未能识别 Query Bin 数据包。可点开详情查看 HTTP 状态与响应头。',
            '与服务器的数据协议不匹配，请刷新后重试。',
            '无法连接服务器，请检查网络后重试。',
            '当前页面商城范围已过期，正在重新签章以继续。',
            '页面绑定信息缺失或已失效，正在重新签章以继续。',
            '检测到渠道或店铺切换，正在刷新页面。',
            '当前请求与页面商城范围不一致，正在刷新页面。',
            '主题已发布新版本。请重新加载页面以继续。',
        ];
    }
}
