<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Tool;

use Weline\Ai\Api\ToolInterface;
use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Service\Lead\LeadService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 线索入库工具：把已核实、已打分的潜客交给 LeadService 做归一化、去重与持久化。
 *
 * 工具层不重复实现入库逻辑；重复线索由 LeadService 合并并返回 created=false。
 */
class SaveLeadTool implements ToolInterface
{
    public function getName(): string
    {
        return 'save_lead';
    }

    public function getDescription(): string
    {
        return (string)__('Save one verified buyer lead with evidence. Requires a public source URL. Returns created=true for a new lead, or created=false when the lead already exists and was merged.');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'payload' => [
                    'type' => 'object',
                    'description' => 'Lead fields extracted from the public page. Omit unknown fields instead of guessing.',
                    'properties' => [
                        'company_name' => ['type' => 'string', 'description' => 'Company / buyer name as written on the page.'],
                        'company_domain' => ['type' => 'string', 'description' => 'Bare domain, e.g. example.com (no scheme).'],
                        'contact_name' => ['type' => 'string', 'description' => 'Public contact person name if published.'],
                        'contact_role' => ['type' => 'string', 'description' => 'Public role/title, e.g. purchasing manager.'],
                        'email' => ['type' => 'string', 'description' => 'Public business email found on the page. Never guess email patterns.'],
                        'country_code' => ['type' => 'string', 'description' => 'ISO 3166-1 alpha-2 country code, e.g. DE.'],
                        'country_name' => ['type' => 'string', 'description' => 'Country name as written on the page.'],
                        'region' => ['type' => 'string', 'description' => 'State / province / region.'],
                        'city' => ['type' => 'string', 'description' => 'City.'],
                        'phone' => ['type' => 'string', 'description' => 'Public phone or WhatsApp number.'],
                        'source_url' => ['type' => 'string', 'description' => 'Public page URL that proves the demand.'],
                        'source_title' => ['type' => 'string', 'description' => 'Title of the source page.'],
                        'source_platform' => ['type' => 'string', 'description' => 'Platform code, e.g. web, forum, marketplace.'],
                        'source_excerpt' => ['type' => 'string', 'description' => 'Verbatim 1-2 sentences from the page showing buyer intent.'],
                        'ai_preference' => ['type' => 'string', 'description' => 'Short inference about what the buyer prefers.'],
                        'ai_business_direction' => ['type' => 'string', 'description' => 'Short inference about the buyer business direction.'],
                        'ai_score' => ['type' => 'number', 'description' => 'Qualification score 0-10 from the 5-axis rubric.'],
                        'ai_reason' => ['type' => 'string', 'description' => 'Why the lead is qualified, referencing the evidence.'],
                        'run_id' => ['type' => 'integer', 'description' => 'Optional acquisition run id this lead belongs to.'],
                        'axes' => [
                            'type' => 'array',
                            'description' => 'Five 0-2 scores: category, intent, B2B, quantity/timing, public channel.',
                            'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 2],
                            'minItems' => 5,
                            'maxItems' => 5,
                        ],
                    ],
                    'required' => ['source_url'],
                ],
                'axes' => [
                    'type' => 'array',
                    'description' => 'Optional top-level 5-axis scores (0-2 each); used when payload.axes is absent.',
                    'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 2],
                    'minItems' => 5,
                    'maxItems' => 5,
                ],
            ],
            'required' => ['payload'],
        ];
    }

    public function execute(array $args): mixed
    {
        $payload = $args['payload'] ?? null;
        if (!is_array($payload)) {
            // 兼容模型把字段平铺在顶层的情况
            $payload = $args;
            unset($payload['payload']);
        }

        $runId = (int)($payload['run_id'] ?? $args['run_id'] ?? 0);
        unset($payload['run_id']);

        if ($payload === []) {
            return ['error' => (string)__('payload 不能为空，请至少提供来源 URL 与证据摘录。')];
        }

        $axes = $payload['axes'] ?? $args['axes'] ?? null;
        unset($payload['axes']);
        if (is_array($axes) && count($axes) === 5) {
            $score = 0;
            foreach ($axes as $axis) {
                $score += max(0, min(2, (int)$axis));
            }
            $payload['ai_score'] = $score;
        }

        $score = $payload['ai_score'] ?? null;
        if ($score === null || $score === '' || !is_numeric($score)) {
            return ['error' => (string)__('缺少 5 轴评分（axes）或 ai_score，请先按 0–2 分打分。')];
        }
        if ((float)$score < 6.0) {
            return ['error' => (string)__('资格分低于 6，按规则不入库；请继续寻找更匹配的买家。')];
        }

        try {
            $saved = ObjectManager::getInstance(LeadService::class)->save($payload, $runId > 0 ? $runId : null);
        } catch (\Throwable $e) {
            return ['error' => (string)__('线索保存失败：%{1}', [$e->getMessage()])];
        }

        $lead = is_array($saved['lead'] ?? null) ? $saved['lead'] : (is_array($saved) ? $saved : []);
        $created = (bool)($saved['created'] ?? false);
        $leadId = (int)($lead[Lead::schema_fields_ID] ?? $lead['id'] ?? 0);

        return [
            'status' => $created ? 'created' : 'existing',
            'created' => $created,
            'lead_id' => $leadId,
            'company_name' => (string)($lead[Lead::schema_fields_COMPANY_NAME] ?? ''),
            'email' => (string)($lead[Lead::schema_fields_EMAIL] ?? ''),
            'dedupe_hash' => (string)($lead[Lead::schema_fields_DEDUPE_HASH] ?? ''),
            'seen_count' => (int)($lead[Lead::schema_fields_SEEN_COUNT] ?? 0),
            'hint' => $created
                ? (string)__('已入库，可继续寻找下一条线索；同一轮不要重复保存。')
                : (string)__('该线索已存在，已合并证据并累加命中次数，不要重复保存。'),
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
