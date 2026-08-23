<?php

declare(strict_types=1);

namespace Weline\Review\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Product\Api\ProductIdentityResolverInterface;
use Weline\Review\Api\ReviewTypeProviderInterface;

final class ProductReviewTypeProvider implements ReviewTypeProviderInterface
{
    public function typeCode(): string
    {
        return 'product';
    }

    public function resolveEntity(string $externalEntityUuid): ?array
    {
        $externalEntityUuid = trim($externalEntityUuid);
        if ($externalEntityUuid === '' || !interface_exists(ProductIdentityResolverInterface::class)) {
            return null;
        }
        try {
            $resolver = ObjectManager::getInstance(RuntimeProviderResolver::class)->resolve(ProductIdentityResolverInterface::class);
            if (!$resolver instanceof ProductIdentityResolverInterface) {
                return null;
            }
            $identity = $resolver->resolveByOfferUuid($externalEntityUuid)
                ?? $resolver->resolveByProductUuid($externalEntityUuid);
            return $identity === null ? null : [
                'entity_id' => $identity->registryId,
                'entity_uuid' => $identity->globalProductUuid,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function fields(): array
    {
        return [
            ['key' => 'rating', 'type' => 'rating', 'label' => __('总体评分'), 'required' => true, 'min' => 1, 'max' => 5],
            ['key' => 'quality_rating', 'type' => 'rating', 'label' => __('质量评分'), 'required' => true, 'min' => 1, 'max' => 5],
            ['key' => 'delivery_rating', 'type' => 'rating', 'label' => __('交付评分'), 'required' => true, 'min' => 1, 'max' => 5],
            ['key' => 'service_rating', 'type' => 'rating', 'label' => __('服务评分'), 'required' => true, 'min' => 1, 'max' => 5],
            ['key' => 'title', 'type' => 'text', 'label' => __('标题（选填）'), 'required' => false, 'max_length' => 120, 'placeholder' => __('一句话概括您的体验')],
            ['key' => 'content', 'type' => 'textarea', 'label' => __('评论内容'), 'required' => true, 'min_length' => 10, 'max_length' => 2000, 'placeholder' => __('请至少填写 10 个字符，分享车型、交付或使用体验。')],
            ['key' => 'images', 'type' => 'image', 'label' => __('添加图片'), 'required' => false, 'accept' => 'image/jpeg,image/png,image/webp,image/gif', 'max_files' => 6, 'max_size' => 10485760],
            ['key' => 'videos', 'type' => 'video', 'label' => __('添加视频'), 'required' => false, 'accept' => 'video/mp4,video/webm,video/quicktime', 'max_files' => 2, 'max_size' => 52428800],
            ['key' => 'reviewer_name', 'type' => 'text', 'label' => __('您的称呼（游客选填）'), 'required' => false, 'max_length' => 120],
            ['key' => 'reviewer_email', 'type' => 'email', 'label' => __('邮箱（游客选填，不公开）'), 'required' => false, 'max_length' => 190],
            ['key' => 'is_anonymous', 'type' => 'checkbox', 'label' => __('匿名展示这条评论'), 'required' => false, 'default' => false],
        ];
    }

    public function normalizeValues(array $values, ?int $customerId): array
    {
        $ratings = [];
        foreach (['rating', 'quality_rating', 'delivery_rating', 'service_rating'] as $ratingKey) {
            $value = $values[$ratingKey] ?? null;
            if (!is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new \InvalidArgumentException((string)__('评分必须在 1 到 5 星之间。'));
            }
            $ratings[$ratingKey] = (int)$value;
            if ($ratings[$ratingKey] < 1 || $ratings[$ratingKey] > 5) {
                throw new \InvalidArgumentException((string)__('评分必须在 1 到 5 星之间。'));
            }
        }
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
            'rating' => $ratings['rating'],
            'title' => $title,
            'content' => $content,
            'reviewer_name' => $name !== '' ? $name : ($customerId === null ? (string)__('游客') : (string)__('已登录客户')),
            'reviewer_email' => $email !== '' ? $email : null,
            'is_anonymous' => filter_var($values['is_anonymous'] ?? false, FILTER_VALIDATE_BOOL),
            'extra' => [
                'quality_rating' => $ratings['quality_rating'],
                'delivery_rating' => $ratings['delivery_rating'],
                'service_rating' => $ratings['service_rating'],
            ],
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
