<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Api;

/**
 * @DESC | 快速建站服务能力接口 - 各模块通过事件观察者注册自身服务能力时使用此数据结构
 */
interface QuickBuildServiceInterface
{
    public const CATEGORY_DOMAIN = 'domain';
    public const CATEGORY_DNS = 'dns';
    public const CATEGORY_CDN = 'cdn';
    public const CATEGORY_SSL = 'ssl';
    public const CATEGORY_TEMPLATE = 'template';
    public const CATEGORY_PROVISIONING = 'provisioning';

    /**
     * 获取服务所属模块标识
     */
    public function getModuleName(): string;

    /**
     * 获取服务分类
     */
    public function getCategory(): string;

    /**
     * 获取服务显示名称（已 __() 翻译）
     */
    public function getServiceName(): string;

    /**
     * 获取服务描述
     */
    public function getDescription(): string;

    /**
     * 获取后台管理入口 URL path
     */
    public function getAdminUrl(): string;

    /**
     * 获取图标 CSS 类名
     */
    public function getIcon(): string;

    /**
     * 获取排序权重（越小越靠前）
     */
    public function getSortOrder(): int;

    /**
     * 服务是否可用
     */
    public function isAvailable(): bool;

    /**
     * 转为数组（方便事件传递）
     *
     * @return array{module: string, category: string, name: string, description: string, admin_url: string, icon: string, order: int, available: bool}
     */
    public function toArray(): array;
}
