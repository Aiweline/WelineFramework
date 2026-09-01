<?php

declare(strict_types=1);

namespace Weline\Blog\Extends\Module\Weline_Review\ReviewTypeProvider;

use Weline\Blog\Service\BlogContentResolver;
use Weline\Blog\Service\BlogScopeResolver;
use Weline\Review\Api\ReviewTypeProviderInterface;

final class BlogReviewTypeProvider implements ReviewTypeProviderInterface
{
    private const UUID_PREFIX = 'blog:post:';

    public function __construct(
        private readonly BlogContentResolver $resolver,
        private readonly BlogScopeResolver $scope,
    ) {
    }

    public function typeCode(): string
    {
        return 'blog';
    }

    public function resolveEntity(string $externalEntityUuid): ?array
    {
        $externalEntityUuid = trim($externalEntityUuid);
        if ($externalEntityUuid === '') {
            return null;
        }

        if (str_starts_with($externalEntityUuid, self::UUID_PREFIX)) {
            $postId = (int)substr($externalEntityUuid, strlen(self::UUID_PREFIX));
            if ($postId <= 0) {
                return null;
            }

            return [
                'entity_id' => $postId,
                'entity_uuid' => self::UUID_PREFIX . $postId,
            ];
        }

        $slug = strtolower(ltrim($externalEntityUuid, '/'));
        if (str_starts_with($slug, 'blog/')) {
            $slug = substr($slug, 5);
        }
        if ($slug === '') {
            return null;
        }

        try {
            $article = $this->resolver->resolveBySlug(
                $this->scope->websiteId(),
                $this->scope->locale(),
                $slug,
                $this->scope->baseUrl(),
            );
        } catch (\Throwable) {
            return null;
        }
        if ($article === null) {
            return null;
        }

        $postId = $article->entityId();
        if ($postId <= 0) {
            return null;
        }

        return [
            'entity_id' => $postId,
            'entity_uuid' => self::UUID_PREFIX . $postId,
        ];
    }

    public function fields(): array
    {
        return [
            ['key' => 'title', 'type' => 'text', 'label' => __('标题（选填）'), 'required' => false, 'max_length' => 120, 'placeholder' => __('一句话概括您的观点')],
            ['key' => 'content', 'type' => 'textarea', 'label' => __('评论内容'), 'required' => true, 'min_length' => 10, 'max_length' => 2000, 'placeholder' => __('请至少填写 10 个字符，分享您的阅读感受。')],
            ['key' => 'images', 'type' => 'image', 'label' => __('添加图片'), 'required' => false, 'accept' => 'image/jpeg,image/png,image/webp,image/gif', 'max_files' => 6, 'max_size' => 10485760],
            ['key' => 'videos', 'type' => 'video', 'label' => __('添加视频'), 'required' => false, 'accept' => 'video/mp4,video/webm,video/quicktime', 'max_files' => 2, 'max_size' => 52428800],
            ['key' => 'reviewer_name', 'type' => 'text', 'label' => __('您的称呼（游客选填）'), 'required' => false, 'max_length' => 120],
            ['key' => 'reviewer_email', 'type' => 'email', 'label' => __('邮箱（游客选填，不公开）'), 'required' => false, 'max_length' => 190],
            ['key' => 'is_anonymous', 'type' => 'checkbox', 'label' => __('匿名展示这条评论'), 'required' => false, 'default' => false],
        ];
    }

    public function normalizeValues(array $values, ?int $customerId): array
    {
        $title = $this->plainText((string)($values['title'] ?? ''));
        $content = $this->plainText((string)($values['content'] ?? ''));
        $name = $this->plainText((string)($values['reviewer_name'] ?? ''));
        $email = strtolower(trim((string)($values['reviewer_email'] ?? '')));
        if ($this->length($title) > 120) {
            throw new \InvalidArgumentException((string)__('评论标题不能超过 120 个字符。'));
        }
        $contentLength = $this->length($content);
        if ($contentLength < 10 || $contentLength > 2000) {
            throw new \InvalidArgumentException((string)__('评论内容需要 10 到 2000 个字符。'));
        }
        if ($this->length($name) > 120) {
            throw new \InvalidArgumentException((string)__('称呼不能超过 120 个字符。'));
        }
        if ($email !== '' && (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new \InvalidArgumentException((string)__('邮箱格式不正确。'));
        }

        return [
            'rating' => 5,
            'title' => $title,
            'content' => $content,
            'reviewer_name' => $name !== '' ? $name : ($customerId === null ? (string)__('游客') : (string)__('已登录客户')),
            'reviewer_email' => $email !== '' ? $email : null,
            'is_anonymous' => filter_var($values['is_anonymous'] ?? false, FILTER_VALIDATE_BOOL),
            'extra' => [],
        ];
    }

    private function plainText(string $value): string
    {
        $value = trim(strip_tags($value));

        return (string)(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
