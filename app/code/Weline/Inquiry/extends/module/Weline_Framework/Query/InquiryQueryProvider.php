<?php

declare(strict_types=1);

namespace Weline\Inquiry\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Inquiry\Service\FormVersionService;
use Weline\Inquiry\Service\InquiryFormCatalog;
use Weline\Inquiry\Service\LocalizedFormResolver;
use Weline\Inquiry\Service\SubmissionService;

final class InquiryQueryProvider implements QueryProviderInterface
{
    public function __construct(private readonly InquiryFormCatalog $catalog, private readonly LocalizedFormResolver $resolver, private readonly FormVersionService $versions, private readonly SubmissionService $submissions) {}
    public function getProviderName(): string { return 'inquiry'; }
    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'searchPublished' => $this->catalog->published((string)($params['search'] ?? '')),
            'schema' => $this->resolver->published((string)($params['code'] ?? ''), (string)($params['locale'] ?? '')),
            'submit' => $this->submissions->submit($params),
            'adminDraft' => $this->versions->draft((int)($params['form_id'] ?? 0)),
            'adminSaveDraft' => $this->versions->saveDraft($params),
            'adminPublish' => $this->versions->publish((int)($params['form_id'] ?? 0)),
            default => throw new \InvalidArgumentException((string)__('Inquiry 查询器不支持：%{1}', $operation)),
        };
    }
    public function getDescriptor(): array
    {
        return ['provider' => 'inquiry', 'name' => __('询盘表单'), 'description' => __('已发布表单查询、公共提交和受 ACL 保护的后台编辑'), 'module' => 'Weline_Inquiry', 'operations' => [
            ['name' => 'searchPublished', 'frontend' => true, 'mode' => 'read', 'params' => [['name' => 'search', 'type' => 'string', 'required' => false]]],
            ['name' => 'schema', 'frontend' => true, 'mode' => 'read', 'params' => [['name' => 'code', 'type' => 'string', 'required' => true], ['name' => 'locale', 'type' => 'string', 'required' => false]]],
            ['name' => 'submit', 'frontend' => true, 'mode' => 'write', 'params' => [['name' => 'code', 'type' => 'string', 'required' => true], ['name' => 'values', 'type' => 'array', 'required' => true], ['name' => 'idempotency_key', 'type' => 'string', 'required' => true]]],
            ['name' => 'adminDraft', 'auth' => 'backend', 'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Inquiry::manage'], 'mode' => 'read'],
            ['name' => 'adminSaveDraft', 'auth' => 'backend', 'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Inquiry::manage'], 'mode' => 'write'],
            ['name' => 'adminPublish', 'auth' => 'backend', 'backend_acl' => ['kind' => 'source', 'source_id' => 'Weline_Inquiry::publish'], 'mode' => 'write'],
        ]];
    }
}
