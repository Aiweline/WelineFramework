<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/01/20

 */

namespace Weline\Ai\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

/**
 * 错误消息辅助类
 * 
 * 用于生成包含配置链接的错误消息，方便其他模块集成时直接跳转配置
 * 
 * @package Weline_Ai
 */
class ErrorMessageHelper
{
    /**
     * 生成包含配置链接的错误消息
     * 
     * @param string $message 错误消息
     * @param string|null $configType 配置类型：'provider'（供应商账户）、'apikey'（API密钥）、'model'（模型配置）
     * @param array $params 额外参数，如 provider_code, model_code 等
     * @return string 包含HTML链接的错误消息
     */
    public static function getErrorMessageWithConfigLink(
        string $message,
        ?string $configType = 'provider',
        array $params = []
    ): string {
        $configUrl = self::getConfigUrl($configType, $params);
        $linkText = self::getLinkText($configType);
        
        // 生成HTML链接，使用新窗口打开
        $htmlLink = sprintf(
            '<a href="%s" target="_blank" style="color: #007bff; text-decoration: underline;">%s</a>',
            htmlspecialchars($configUrl ?? '', ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($linkText ?? '', ENT_QUOTES, 'UTF-8')
        );
        
        return $message . ' ' . $htmlLink;
    }

    /**
     * 获取配置页面的URL
     * 
     * @param string|null $configType 配置类型
     * @param array $params 额外参数
     * @return string 配置页面URL
     */
    private static function getConfigUrl(?string $configType, array $params): string
    {
        /** @var Url $urlService */
        $urlService = ObjectManager::getInstance(Url::class);
        
        // 根据配置类型确定路由路径（不需要 /index，因为 index 是默认 action）
        $routePath = match ($configType) {
            'provider' => 'ai/backend/provider',
            'apikey' => 'ai/backend/api-key',
            'model' => 'ai/backend/model',
            default => 'ai/backend/provider',
        };
        
        // 使用框架的 URL 服务生成后台 URL（包含正确的后台密钥、货币、语言）
        $url = $urlService->getBackendUrl($routePath);
        
        // 如果有额外参数，添加到 URL
        $queryParams = [];
        if (!empty($params['provider_code'])) {
            $queryParams['provider_code'] = $params['provider_code'];
        }
        if (!empty($params['model_code'])) {
            $queryParams['model_code'] = $params['model_code'];
        }
        
        if (!empty($queryParams)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($queryParams);
        }
        
        return $url;
    }

    /**
     * 获取链接文本
     * 
     * @param string|null $configType 配置类型
     * @return string 链接文本
     */
    private static function getLinkText(?string $configType): string
    {
        switch ($configType) {
            case 'provider':
                return __('点击前往配置供应商账户');
            case 'apikey':
                return __('点击前往配置API密钥');
            case 'model':
                return __('点击前往配置模型');
            default:
                return __('点击前往配置');
        }
    }

    /**
     * 生成缺少API key的错误消息
     * 
     * @param string|null $providerCode 供应商代码
     * @return string 错误消息
     */
    public static function getMissingApiKeyMessage(?string $providerCode = null): string
    {
        $message = __('API密钥未配置或配置不完整');
        return self::getErrorMessageWithConfigLink($message, 'provider', ['provider_code' => $providerCode]);
    }

    /**
     * 生成缺少供应商账户的错误消息
     * 
     * @param string|null $providerCode 供应商代码
     * @return string 错误消息
     */
    public static function getMissingAccountMessage(?string $providerCode = null): string
    {
        $message = $providerCode 
            ? __('没有可用的%{provider}供应商账户，请先配置供应商账户', ['provider' => $providerCode])
            : __('没有可用的供应商账户，请先配置供应商账户');
        return self::getErrorMessageWithConfigLink($message, 'provider', ['provider_code' => $providerCode]);
    }

    /**
     * 生成账户信息不完整的错误消息
     * 
     * @param string|null $providerCode 供应商代码
     * @param string|null $missingField 缺少的字段
     * @return string 错误消息
     */
    public static function getIncompleteAccountMessage(?string $providerCode = null, ?string $missingField = null): string
    {
        $message = $missingField
            ? __('供应商账户信息不完整，缺少：%{field}', ['field' => $missingField])
            : __('供应商账户信息不完整，请检查配置');
        return self::getErrorMessageWithConfigLink($message, 'provider', ['provider_code' => $providerCode]);
    }
}

