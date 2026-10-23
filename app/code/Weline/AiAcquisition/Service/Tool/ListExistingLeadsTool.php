<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Tool;

use Weline\Ai\Api\ToolInterface;
use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Service\Lead\LeadService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 已有线索查询工具：保存前查重，避免重复写入与重复触达。
 *
 * 只读，不修改任何记录；最多返回 20 条摘要字段。
 */
class ListExistingLeadsTool implements ToolInterface
{
    /** 单次最多返回条数 */
    private const MAX_LIMIT = 20;

    public function getName(): string
    {
        return 'list_existing_leads';
    }

    public function getDescription(): string
    {
        return (string)__('Check whether a lead already exists before saving. Searches existing leads by company, email, domain or source URL keyword and returns a short summary.');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keyword' => [
                    'type' => 'string',
                    'description' => 'Company name, email, domain or source URL keyword to look up.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows to return, 1-20. Default 5.',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
            ],
            'required' => ['keyword'],
        ];
    }

    public function execute(array $args): mixed
    {
        $keyword = trim((string)($args['keyword'] ?? ''));
        if ($keyword === '') {
            return ['error' => (string)__('keyword 不能为空，请给出公司名、邮箱、域名或来源 URL 关键词。')];
        }

        $limit = (int)($args['limit'] ?? 5);
        if ($limit <= 0) {
            $limit = 5;
        }
        $limit = min($limit, self::MAX_LIMIT);

        try {
            $result = ObjectManager::getInstance(LeadService::class)->listing(['keyword' => $keyword], 1, $limit);
        } catch (\Throwable $e) {
            return ['error' => (string)__('查询已有线索失败：%{1}', [$e->getMessage()])];
        }

        $rows = is_array($result['items'] ?? null) ? $result['items'] : [];
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = [
                'id' => (int)($row[Lead::schema_fields_ID] ?? 0),
                'company_name' => (string)($row[Lead::schema_fields_COMPANY_NAME] ?? ''),
                'email' => (string)($row[Lead::schema_fields_EMAIL] ?? ''),
                'country_code' => (string)($row[Lead::schema_fields_COUNTRY_CODE] ?? ''),
                'status' => (string)($row[Lead::schema_fields_STATUS] ?? ''),
                'ai_score' => (float)($row[Lead::schema_fields_AI_SCORE] ?? 0),
                'source_url' => (string)($row[Lead::schema_fields_SOURCE_URL] ?? ''),
            ];
        }

        return [
            'keyword' => $keyword,
            'count' => count($items),
            'total' => (int)($result['total'] ?? count($items)),
            'items' => $items,
            'hint' => $items === []
                ? (string)__('没有匹配的已有线索，可以继续核实并保存。')
                : (string)__('已存在相同或相近线索，请复用并补充证据，不要重复保存。'),
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
