<?php

declare(strict_types=1);

namespace Weline\Inquiry\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Inquiry\Service\FormVersionService;
use Weline\Inquiry\Service\InquiryFormCatalog;
use Weline\Inquiry\Service\InquiryTranslationAiService;
use Weline\Inquiry\Service\LocalizedFormResolver;
use Weline\Inquiry\Service\SubmissionService;

final class InquiryQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly InquiryFormCatalog $catalog,
        private readonly LocalizedFormResolver $resolver,
        private readonly FormVersionService $versions,
        private readonly SubmissionService $submissions,
        private readonly InquiryTranslationAiService $translationAi,
    ) {
    }

    public function getProviderName(): string
    {
        return 'inquiry';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'searchPublished' => $this->catalog->published((string)($params['search'] ?? '')),
            'schema' => $this->resolver->published((string)($params['code'] ?? ''), (string)($params['locale'] ?? '')),
            'submit' => $this->submissions->submit($params),
            'adminDraft' => $this->versions->draft((int)($params['form_id'] ?? 0)),
            'adminSaveDraft' => $this->versions->saveDraft($params),
            'adminPublish' => $this->versions->publish((int)($params['form_id'] ?? 0)),
            'adminAiTranslate' => $this->adminAiTranslate($params),
            default => throw new \InvalidArgumentException((string)__('Inquiry 查询器不支持：%{1}', $operation)),
        };
    }

    /** @param array<string, mixed> $params */
    private function adminAiTranslate(array $params): array
    {
        $translations = $params['translations'] ?? [];
        if (!is_array($translations)) {
            $translations = [];
        }
        $defaultLocale = trim((string)($params['default_locale'] ?? 'en_US'));
        $force = !empty($params['force']);
        $targets = $params['target_locales'] ?? null;
        if (!is_array($targets)) {
            $targets = null;
        }

        return $this->translationAi->fillFromDefaultLocale($translations, $defaultLocale, $force, $targets);
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'inquiry',
            'name' => __('询盘表单'),
            'description' => __('已发布表单查询、公共提交和受 ACL 保护的后台编辑'),
            'module' => 'Weline_Inquiry',
            'operations' => [
                ['name' => 'searchPublished', 'frontend' => true, 'mode' => 'read', 'params' => [['name' => 'search', 'type' => 'string', 'required' => false]]],
                ['name' => 'schema', 'frontend' => true, 'mode' => 'read', 'params' => [['name' => 'code', 'type' => 'string', 'required' => true], ['name' => 'locale', 'type' => 'string', 'required' => false]]],
                ['name' => 'submit', 'frontend' => true, 'mode' => 'write', 'params' => [
                    ['name' => 'code', 'type' => 'string', 'required' => true],
                    ['name' => 'values', 'type' => 'array', 'required' => true],
                    ['name' => 'idempotency_key', 'type' => 'string', 'required' => true],
                    ['name' => 'captcha_provider', 'type' => 'string', 'required' => false, 'max_length' => 64],
                    ['name' => 'captcha_token', 'type' => 'string', 'required' => false, 'max_length' => 128],
                    ['name' => 'captcha_response', 'type' => 'string', 'required' => false, 'max_length' => 4096],
                    ['name' => 'captcha_action', 'type' => 'string', 'required' => false, 'max_length' => 128],
                ]],
                ['name' => 'adminDraft', 'frontend' => true, 'auth' => 'backend', 'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Inquiry::manage'], 'mode' => 'read'],
                ['name' => 'adminSaveDraft', 'frontend' => true, 'auth' => 'backend', 'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Inquiry::manage'], 'mode' => 'write'],
                ['name' => 'adminPublish', 'frontend' => true, 'auth' => 'backend', 'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Inquiry::publish'], 'mode' => 'write'],
                ['name' => 'adminAiTranslate', 'frontend' => true, 'auth' => 'backend', 'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Inquiry::manage'], 'mode' => 'write', 'params' => [
                    ['name' => 'translations', 'type' => 'array', 'required' => true],
                    ['name' => 'default_locale', 'type' => 'string', 'required' => true],
                    ['name' => 'force', 'type' => 'bool', 'required' => false],
                    ['name' => 'target_locales', 'type' => 'array', 'required' => false],
                ]],
            ],
        ];
    }
}
