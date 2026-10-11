<?php

declare(strict_types=1);

namespace Weline\Ops\Extends\Module\Weline_Ops\Lane;

use Weline\Framework\Http\Url;
use Weline\Ops\Api\OpsLaneContributionInterface;
use Weline\Ops\Service\OpsLaneDeepLink;

/**
 * Ops 内置内容车道（任务登记 + 说明；P1 不自动跑 content_ops）。
 */
final class ContentLane implements OpsLaneContributionInterface
{
    public function __construct(
        private readonly Url $url,
    ) {
    }

    public function getLaneCode(): string
    {
        return 'content';
    }

    public function getTitle(): string
    {
        return (string)__('内容 / 博客日更');
    }

    public function getSortOrder(): int
    {
        return 40;
    }

    public function getStatus(): string
    {
        return 'ready';
    }

    public function getActions(int $websiteId): array
    {
        $actions = [];
        if (class_exists(\Weline\Blog\Controller\Backend\PostAdmin::class)) {
            $actions[] = [
                'code' => 'blog_posts',
                'label' => (string)__('博客文章'),
                'url' => OpsLaneDeepLink::backend($this->url, 'blog/backend/post-admin/index', $websiteId),
                'connected' => null,
            ];
        }

        return $actions;
    }

    public function getTaskTypes(): array
    {
        return [
            ['type' => 'content.product_optimize', 'label' => (string)__('产品优化（Cursor 技能）')],
            ['type' => 'content.blog_draft', 'label' => (string)__('博客草稿（P3 自动）')],
        ];
    }

    public function getConnectionHealth(int $websiteId): array
    {
        $blogReady = class_exists(\Weline\Blog\Model\Post::class);

        return [
            'connected' => $blogReady,
            'summary' => $blogReady
                ? (string)__('Blog 模块可用；日更发布须审批（P3）')
                : (string)__('Blog 模块未安装'),
        ];
    }
}
