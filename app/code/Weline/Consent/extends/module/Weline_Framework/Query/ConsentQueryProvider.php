<?php

declare(strict_types=1);

namespace Weline\Consent\Extends\Module\Weline_Framework\Query;

use Weline\Consent\Api\ConsentVisitorIdentityInterface;
use Weline\Consent\Service\ConsentService;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class ConsentQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly ConsentService $consentService,
        private readonly ConsentVisitorIdentityInterface $visitorIdentity,
    ) {
    }

    public function getProviderName(): string
    {
        return 'consent';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'accept' => $this->accept($params),
            'status' => $this->status($params),
            'withdraw' => $this->withdraw($params),
            default => throw new \InvalidArgumentException(
                (string)__('Consent 查询器不支持的操作：%{1}', [$operation])
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'consent',
            'name' => __('Consent'),
            'description' => __('前台 Cookie/同意横幅 accept / withdraw / status'),
            'module' => 'Weline_Consent',
            'operations' => [
                [
                    'name' => 'accept',
                    'description' => __('同意指定类别'),
                    'frontend' => true,
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'categories', 'type' => 'list', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                ],
                [
                    'name' => 'status',
                    'description' => __('读取同意状态'),
                    'frontend' => true,
                    'mode' => 'read',
                    'params' => [],
                    'returns' => ['type' => 'array'],
                ],
                [
                    'name' => 'withdraw',
                    'description' => __('撤回某一类别同意'),
                    'frontend' => true,
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'category', 'type' => 'string', 'required' => false],
                    ],
                    'returns' => ['type' => 'array'],
                ],
            ],
        ];
    }

    private function accept(array $params): array
    {
        $this->visitorIdentity->assertNoClientOverride($params);
        $websiteId = $this->websiteId();
        $visitor = $this->visitorIdentity->resolveOrIssue();
        $codes = $params['categories'] ?? ['analytics', 'marketing'];
        if (!\is_array($codes) || $codes === []) {
            $codes = ['analytics', 'marketing'];
        }
        foreach ($codes as $code) {
            $this->consentService->grant($websiteId, $visitor, (string)$code);
        }
        $this->consentService->grant($websiteId, $visitor, 'necessary');

        return [
            'success' => true,
            'show_banner' => $this->consentService->shouldShowBanner($websiteId, $visitor),
        ];
    }

    private function status(array $params): array
    {
        $this->visitorIdentity->assertNoClientOverride($params);
        $websiteId = $this->websiteId();
        $visitor = $this->visitorIdentity->resolveOrIssue();
        $granted = [];
        foreach ($this->consentService->categories() as $cat) {
            $granted[$cat['code']] = $this->consentService->isGranted($websiteId, $visitor, $cat['code']);
        }

        return [
            'website_id' => $websiteId,
            'show_banner' => $this->consentService->shouldShowBanner($websiteId, $visitor),
            'categories' => $this->consentService->categories(),
            'granted' => $granted,
            'recording_enabled' => $this->consentService->isRecordingEnabled(),
        ];
    }

    private function withdraw(array $params): array
    {
        $this->visitorIdentity->assertNoClientOverride($params);
        $websiteId = $this->websiteId();
        $visitor = $this->visitorIdentity->resolveOrIssue();
        $code = (string)($params['category'] ?? 'analytics');
        $this->consentService->withdraw($websiteId, $visitor, $code);

        return [
            'success' => true,
            'show_banner' => $this->consentService->shouldShowBanner($websiteId, $visitor),
        ];
    }

    private function websiteId(): int
    {
        $id = RequestContext::getWelineWebsiteId();
        return $id >= 0 ? $id : 0;
    }

}
