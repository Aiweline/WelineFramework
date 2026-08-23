<?php

declare(strict_types=1);

namespace Weline\I18n\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Session\SessionFactory;
use Weline\I18n\Api\LanguageRequest\LanguageSupportRequestDirectoryInterface;
use Weline\I18n\Api\LanguageRequest\LanguageSupportRequestWorkflowInterface;

final class LanguageSupportRequestAdminQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly LanguageSupportRequestDirectoryInterface $directory,
        private readonly LanguageSupportRequestWorkflowInterface $workflow,
        private readonly SessionFactory $sessions,
    ) {
    }

    public function getProviderName(): string
    {
        return 'i18n_language_requests_admin';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        $userId = $this->backendUserId();
        return match ($operation) {
            'list' => $this->directory->adminList($params),
            'review' => [
                'success' => true,
                'updated' => $this->workflow->review(
                    \is_array($params['item_ids'] ?? null) ? $params['item_ids'] : [],
                    (string)($params['status'] ?? ''),
                    $userId,
                    (string)($params['note'] ?? ''),
                ),
                'message' => (string)__('语言申请状态已更新'),
            ],
            'recalculateReady' => [
                'success' => true,
                'updated' => $this->workflow->recalculateReady(
                    \is_array($params['locales'] ?? null) ? $params['locales'] : null
                ),
                'message' => (string)__('语言就绪状态已同步'),
            ],
            default => throw new \InvalidArgumentException((string)__('语言申请后台查询器不支持的操作：%{1}', [$operation])),
        };
    }

    public function getDescriptor(): array
    {
        $acl = [
            'kind' => 'source',
            'source_id' => 'Weline_I18n::i18n_language_requests',
        ];
        return [
            'provider' => $this->getProviderName(),
            'name' => __('语言申请后台管理'),
            'description' => __('筛选、审核、重新打开语言申请，并按运行时语言包同步 ready 状态。'),
            'module' => 'Weline_I18n',
            'operations' => [
                [
                    'name' => 'list',
                    'frontend' => true,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => $acl,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        ['name' => 'status', 'type' => 'string', 'required' => false, 'max_length' => 16],
                        ['name' => 'website_id', 'type' => 'int', 'required' => false, 'min' => 0],
                        ['name' => 'country_code', 'type' => 'string', 'required' => false, 'max_length' => 3],
                        ['name' => 'locale_code', 'type' => 'string', 'required' => false, 'max_length' => 32],
                        ['name' => 'date_from', 'type' => 'string', 'required' => false, 'max_length' => 10],
                        ['name' => 'date_to', 'type' => 'string', 'required' => false, 'max_length' => 10],
                    ],
                    'returns' => ['type' => 'map'],
                ],
                [
                    'name' => 'review',
                    'frontend' => true,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => $acl,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        ['name' => 'item_ids', 'type' => 'list', 'required' => true, 'max_items' => 200],
                        ['name' => 'status', 'type' => 'string', 'required' => true, 'max_length' => 16],
                        ['name' => 'note', 'type' => 'string', 'required' => false, 'max_length' => 1000],
                    ],
                    'returns' => ['type' => 'map'],
                ],
                [
                    'name' => 'recalculateReady',
                    'frontend' => true,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => $acl,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        ['name' => 'locales', 'type' => 'list', 'required' => false, 'max_items' => 200],
                    ],
                    'returns' => ['type' => 'map'],
                ],
            ],
        ];
    }

    private function backendUserId(): int
    {
        $fromWorker = $this->backendUserIdFromWorkerContext();
        if ($fromWorker !== null) {
            return $fromWorker;
        }

        $session = $this->sessions->createBackendSession();
        if (!$session->isStarted()) {
            $session->start();
        }
        $userId = $session->isLoggedIn() ? (int)($session->getUserId() ?? 0) : 0;
        if ($userId <= 0) {
            $this->denyBackendLogin();
        }

        return $userId;
    }

    /**
     * QueryBin already restored and ACL-checked the attested backend binding.
     * Re-starting a PHP Session from the API cookie would create a different
     * empty identity and fail closed as "please log in".
     */
    private function backendUserIdFromWorkerContext(): ?int
    {
        if (!RequestContext::has(FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY)) {
            return null;
        }

        $execution = RequestContext::get(FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY);
        if (!$execution instanceof FrontendWorkerExecutionContext
            || $execution->area !== FrontendWorkerExecutionContext::AREA_BACKEND
            || $execution->backendBinding === null
            || $execution->backendBinding->backendUserId <= 0) {
            $this->denyBackendLogin();
        }

        return $execution->backendBinding->backendUserId;
    }

    private function denyBackendLogin(): never
    {
        throw new FrontendQueryException(
            'auth_error',
            (string)__('请先登录后台'),
            401,
        );
    }
}
