<?php

declare(strict_types=1);

namespace Weline\Review\Service;

use Weline\Review\Api\ReviewTypeProviderInterface;

final class ReviewTypeRegistry
{
    /** @var array<string,ReviewTypeProviderInterface> */
    private array $providers = [];

    public function __construct(ProductReviewTypeProvider $product)
    {
        $this->register($product);
    }

    public function register(ReviewTypeProviderInterface $provider): void
    {
        $code = strtolower(trim($provider->typeCode()));
        if ($code === '' || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $code) !== 1) {
            throw new \InvalidArgumentException((string)__('评论类型编码无效。'));
        }
        $this->providers[$code] = $provider;
    }

    public function get(string $typeCode): ReviewTypeProviderInterface
    {
        $typeCode = strtolower(trim($typeCode));
        if (!isset($this->providers[$typeCode])) {
            throw new \InvalidArgumentException((string)__('评论类型不存在或尚未注册：%{1}', [$typeCode]));
        }
        return $this->providers[$typeCode];
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->providers);
    }
}
