<?php

declare(strict_types=1);

namespace Weline\I18n\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\I18n\Service\LanguageSupportRequestService;

final class LanguageSupportRequestQueryProvider implements QueryProviderInterface
{
    public function __construct(private readonly LanguageSupportRequestService $service)
    {
    }

    public function getProviderName(): string
    {
        return 'i18n_language_requests';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getLanguageSupportRequestForm' => $this->service->renderForm(),
            'submitLanguageSupportRequest' => $this->submitLanguageSupportRequest($params),
            default => throw new \InvalidArgumentException((string)__('语言申请查询器不支持的操作：%{1}', [$operation])),
        };
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    private function submitLanguageSupportRequest(array $params): array
    {
        try {
            return $this->service->submit($params);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            // Expected applicant-facing failures must stay async-friendly JSON,
            // not QueryBin 500 "Internal server error."
            return [
                'success' => false,
                'message' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : (string)__('提交失败，请稍后重试'),
            ];
        }
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => __('语言支持申请'),
            'description' => __('异步加载全球语言申请表，并提交经过人机验证的站点语言支持请求。'),
            'module' => 'Weline_I18n',
            'operations' => [
                [
                    'name' => 'getLanguageSupportRequestForm',
                    'description' => __('首次打开申请弹层时加载编译后的 w:form、全球语言目录与验证码资源，并签发一次性本地挑战。'),
                    'frontend' => true,
                    'external' => false,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [],
                    'returns' => ['type' => 'map'],
                ],
                [
                    'name' => 'submitLanguageSupportRequest',
                    'description' => __('提交当前网站的多语言支持申请；服务端重新解析站点并验证 CAPTCHA。'),
                    'frontend' => true,
                    'external' => false,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        ['name' => 'first_name', 'type' => 'string', 'required' => true, 'max_length' => 60],
                        ['name' => 'last_name', 'type' => 'string', 'required' => true, 'max_length' => 60],
                        ['name' => 'name', 'type' => 'string', 'required' => false, 'max_length' => 120],
                        ['name' => 'email', 'type' => 'string', 'required' => true, 'max_length' => 190],
                        ['name' => 'locales', 'type' => 'list', 'required' => true, 'max_items' => 20],
                        ['name' => 'captcha_provider', 'type' => 'string', 'required' => true, 'max_length' => 64],
                        ['name' => 'captcha_token', 'type' => 'string', 'required' => false, 'max_length' => 128],
                        ['name' => 'captcha_response', 'type' => 'string', 'required' => true, 'max_length' => 8192],
                        ['name' => 'captcha_action', 'type' => 'string', 'required' => false, 'max_length' => 100],
                    ],
                    'returns' => ['type' => 'map'],
                ],
            ],
        ];
    }
}
