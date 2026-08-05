<?php

declare(strict_types=1);

namespace Weline\Websites\Extends\Module\Weline_Framework\Query;

use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\I18n\Api\LanguageRequest\LanguageSupportRequestDirectoryInterface;
use Weline\I18n\Api\LanguageRequest\LanguageSupportRequestWorkflowInterface;
use Weline\Websites\Api\Localization\WebsiteLanguageAssignmentInterface;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * 网站编辑页消费 I18n 语言申请的唯一写入口。
 *
 * I18n 只发布 ready 目录和状态流转接口；站点语言的实际写入始终由
 * WebsiteLanguageAssignmentInterface 负责，保持默认语言和现有关联不变。
 */
final class WebsiteLanguageRequestQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly LanguageSupportRequestDirectoryInterface $directory,
        private readonly LanguageSupportRequestWorkflowInterface $workflow,
        private readonly WebsiteLanguageAssignmentInterface $assignment,
        private readonly Website $websites,
        private readonly WebsiteLanguage $websiteLanguages,
        private readonly BackendObjectAuthorizationGuardInterface $authorization,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly SessionFactory $sessions,
    ) {
    }

    public function getProviderName(): string
    {
        return 'website_language_requests';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        $userId = $this->backendUserId();
        $websiteId = $this->websiteId($params);
        $websiteCode = $this->websiteCode($websiteId);
        $scope = ScopeIdentity::website($websiteId, $websiteCode);

        return match ($operation) {
            'listReady' => $this->listReady($websiteId, $scope),
            'assign' => $this->assign($websiteId, $scope, $params, $userId),
            default => throw new \InvalidArgumentException(
                (string)__('网站语言申请查询器不支持的操作：%{1}', [$operation])
            ),
        };
    }

    public function getDescriptor(): array
    {
        $acl = [
            'kind' => 'source',
            'source_id' => 'Weline_Websites::website_edit',
        ];

        return [
            'provider' => $this->getProviderName(),
            'name' => __('网站语言申请分配'),
            'description' => __('读取当前网站已就绪的语言申请，并幂等加入站点语言。'),
            'module' => 'Weline_Websites',
            'operations' => [
                [
                    'name' => 'listReady',
                    'description' => __('列出当前网站尚未分配的 ready 语言申请。'),
                    'frontend' => true,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => $acl,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 2,
                    'params' => [
                        ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                    ],
                    'returns' => ['type' => 'map'],
                ],
                [
                    'name' => 'assign',
                    'description' => __('在同一事务中幂等加入站点语言并标记申请为 assigned。'),
                    'frontend' => true,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => $acl,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 4,
                    'params' => [
                        ['name' => 'website_id', 'type' => 'int', 'required' => true, 'min' => 0],
                        ['name' => 'locales', 'type' => 'list', 'required' => true, 'max_items' => 200],
                        ['name' => 'expected_grant_version', 'type' => 'int', 'required' => true, 'min' => 0],
                    ],
                    'returns' => ['type' => 'map'],
                ],
            ],
        ];
    }

    private function listReady(int $websiteId, ScopeIdentity $scope): array
    {
        $grant = $this->authorization->requireForQuery(ObjectAction::VIEW, $scope);
        $assigned = \array_fill_keys($this->assignedCodes($websiteId), true);
        $items = \array_values(\array_filter(
            $this->directory->readyForWebsite($websiteId),
            static fn(array $item): bool => !isset($assigned[(string)($item['locale_code'] ?? '')]),
        ));

        return [
            'success' => true,
            'website_id' => $websiteId,
            'grant_version' => $grant->matchedGrantVersion,
            'items' => $items,
            'total' => \count($items),
        ];
    }

    /** @param array<string,mixed> $params */
    private function assign(int $websiteId, ScopeIdentity $scope, array $params, int $userId): array
    {
        $this->authorization->requireSubmitForQuery(
            ObjectAction::UPDATE,
            $scope,
            (int)($params['expected_grant_version'] ?? -1),
        );

        $requested = $this->normalizeLocales($params['locales'] ?? []);
        if ($requested === []) {
            throw new \InvalidArgumentException((string)__('请选择要加入站点的语言'));
        }

        $ready = [];
        foreach ($this->directory->readyForWebsite($websiteId) as $item) {
            $locale = \trim((string)($item['locale_code'] ?? ''));
            if ($locale !== '') {
                $ready[$locale] = true;
            }
        }
        $locales = \array_values(\array_filter(
            $requested,
            static fn(string $locale): bool => isset($ready[$locale]),
        ));
        if ($locales === []) {
            return [
                'success' => true,
                'assigned' => 0,
                'message' => (string)__('所选语言已加入或尚未就绪'),
            ];
        }

        $updated = $this->transactions->runWrite(
            $this->websiteLanguages->getConnection(),
            function () use ($websiteId, $locales, $userId): int {
                $this->assignment->ensureAssigned($websiteId, $locales);
                return $this->workflow->markAssigned($websiteId, $locales, $userId);
            },
        );

        return [
            'success' => true,
            'assigned' => $updated,
            'locales' => $locales,
            'message' => (string)__('站点语言已加入'),
        ];
    }

    /** @param array<string,mixed> $params */
    private function websiteId(array $params): int
    {
        if (!\array_key_exists('website_id', $params)) {
            throw new \InvalidArgumentException((string)__('缺少网站 ID'));
        }
        $websiteId = (int)$params['website_id'];
        if ($websiteId < Website::ID_DEFAULT) {
            throw new \InvalidArgumentException((string)__('网站 ID 无效'));
        }
        return $websiteId;
    }

    private function websiteCode(int $websiteId): string
    {
        $row = (clone $this->websites)->clearData()->clearQuery()
            ->where(Website::schema_fields_ID, $websiteId)
            ->find()
            ->fetchArray();
        if (\is_array($row) && \array_is_list($row)) {
            $row = $row[0] ?? null;
        }
        if (!\is_array($row) || !\array_key_exists(Website::schema_fields_ID, $row)) {
            throw new \InvalidArgumentException((string)__('网站不存在'));
        }
        $code = \trim((string)($row[Website::schema_fields_CODE] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException((string)__('网站代码无效'));
        }
        return $code;
    }

    /** @return list<string> */
    private function assignedCodes(int $websiteId): array
    {
        $model = clone $this->websiteLanguages;
        return \array_values(\array_unique(\array_map(
            'strval',
            $model->getWebsiteLanguageCodes($websiteId),
        )));
    }

    /** @return list<string> */
    private function normalizeLocales(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $locales = [];
        foreach ($value as $candidate) {
            $locale = \str_replace('-', '_', \trim((string)$candidate));
            if (\preg_match('/\A[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?\z/D', $locale) === 1) {
                $locales[$locale] = true;
            }
        }
        return \array_keys($locales);
    }

    private function backendUserId(): int
    {
        $session = $this->sessions->createBackendSession();
        $session->start();
        $userId = $session->isLoggedIn() ? (int)($session->getUserId() ?? 0) : 0;
        if ($userId <= 0) {
            throw new \RuntimeException((string)__('请先登录后台'));
        }
        return $userId;
    }
}
