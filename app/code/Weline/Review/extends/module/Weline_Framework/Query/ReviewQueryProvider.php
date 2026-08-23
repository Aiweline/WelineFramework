<?php

declare(strict_types=1);

namespace Weline\Review\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Review\Service\ReviewService;

final class ReviewQueryProvider implements QueryProviderInterface
{
    public function __construct(private readonly ReviewService $reviews)
    {
    }

    public function getProviderName(): string
    {
        return 'review';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        try {
            return match ($operation) {
                'form', 'schema' => $this->reviews->form($this->type($params), $this->entity($params)),
                'list' => $this->reviews->list($this->type($params), $this->entity($params), (int)($params['page'] ?? 1), (int)($params['page_size'] ?? 10)),
                'upload' => $this->upload($params),
                'submit' => $this->submit($params),
                default => throw new \InvalidArgumentException((string)__('评论接口不支持操作：%{1}', [$operation])),
            };
        } catch (\InvalidArgumentException $exception) {
            return ['success' => false, 'message' => $exception->getMessage()];
        } catch (\Throwable) {
            return ['success' => false, 'message' => __('评论服务暂时不可用，请稍后再试。')];
        }
    }

    public function getDescriptor(): array
    {
        $entityParams = [
            ['name' => 'type_code', 'type' => 'string', 'required' => false, 'max_length' => 64],
            ['name' => 'entity_uuid', 'type' => 'string', 'required' => false, 'max_length' => 36],
            ['name' => 'global_offer_uuid', 'type' => 'string', 'required' => false, 'max_length' => 36],
        ];
        return [
            'provider' => 'review',
            'name' => __('通用评论'),
            'description' => __('按评论类型读取动态表单、提交评论并上传图文视频媒体。'),
            'module' => 'Weline_Review',
            'operations' => [
                ['name' => 'form', 'frontend' => true, 'mode' => 'read', 'params' => $entityParams],
                ['name' => 'schema', 'frontend' => true, 'mode' => 'read', 'params' => $entityParams],
                ['name' => 'list', 'frontend' => true, 'mode' => 'read', 'params' => array_merge($entityParams, [
                    ['name' => 'page', 'type' => 'int', 'required' => false, 'min' => 1],
                    ['name' => 'page_size', 'type' => 'int', 'required' => false, 'min' => 1, 'max' => 20],
                ])],
                ['name' => 'upload', 'frontend' => true, 'mode' => 'write', 'params' => array_merge($entityParams, [
                    ['name' => 'media_kind', 'type' => 'string', 'required' => true, 'max_length' => 16],
                    ['name' => 'upload_base64', 'type' => 'array', 'required' => true],
                ])],
                ['name' => 'submit', 'frontend' => true, 'mode' => 'write', 'params' => array_merge($entityParams, [
                    ['name' => 'values', 'type' => 'array', 'required' => true],
                    ['name' => 'media_tokens', 'type' => 'array', 'required' => false],
                ])],
            ],
        ];
    }

    private function type(array $params): string
    {
        return strtolower(trim((string)($params['type_code'] ?? 'product'))) ?: 'product';
    }

    private function entity(array $params): string
    {
        return trim((string)($params['entity_uuid'] ?? $params['global_offer_uuid'] ?? ''));
    }

    private function upload(array $params): array
    {
        $uploads = $params['upload_base64'] ?? [];
        $upload = is_array($uploads) && isset($uploads[0]) && is_array($uploads[0]) ? $uploads[0] : [];
        return $this->reviews->upload($this->type($params), $this->entity($params), (string)($params['media_kind'] ?? ''), $upload);
    }

    private function submit(array $params): array
    {
        return $this->reviews->create(
            $this->type($params),
            $this->entity($params),
            is_array($params['values'] ?? null) ? $params['values'] : [],
            is_array($params['media_tokens'] ?? null) ? $params['media_tokens'] : [],
        );
    }
}
