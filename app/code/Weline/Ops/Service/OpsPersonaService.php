<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

use Weline\Ai\Api\AiRuntimeInterface;
use Weline\Ai\Model\AiModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Model\OpsPersona;
use Weline\Ops\Model\OpsRunLog;

final class OpsPersonaService
{
    private const GENERATOR_AI = 'ops_persona_ai_v4';
    private const AI_ATTEMPTS = 2;

    /** 优先云端文本模型；禁止默认落到本机 Ollama / translategemma。 */
    private const PREFERRED_MODEL_CODES = [
        'deepseek-flash',
        'deepseek-chat',
        'deepseek-v4-flash',
        'gemini-flash-latest',
        'gemini-2.5-flash',
    ];

    /**
     * 步骤定义（中文源串；展示层再 __()）。
     *
     * @return list<array{key:string,label:string,icon:string}>
     */
    public static function progressSteps(): array
    {
        return [
            ['key' => 'site', 'label' => '站点身份', 'icon' => 'store'],
            ['key' => 'catalog', 'label' => '分类树', 'icon' => 'folder'],
            ['key' => 'products', 'label' => '商品抽样', 'icon' => 'cart'],
            ['key' => 'policy', 'label' => '政策履约', 'icon' => 'shield'],
            ['key' => 'market', 'label' => '语种市场', 'icon' => 'globe'],
            ['key' => 'compress', 'label' => '压缩证据', 'icon' => 'layers'],
            ['key' => 'ai', 'label' => 'AI 策略生成', 'icon' => 'robot'],
            ['key' => 'persist', 'label' => '写入记忆', 'icon' => 'check'],
        ];
    }

    public function __construct(
        private readonly OpsPersona $persona,
        private readonly OpsRunLog $runLog,
        private readonly OpsWebsiteContextReader $contextReader,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function getView(int $websiteId): ?array
    {
        $row = $this->persona->clear()
            ->where(OpsPersona::schema_fields_WEBSITE_ID, max(0, $websiteId))
            ->find()
            ->fetch();
        if (!$row->getId()) {
            return null;
        }

        $audience = $this->decodeJson((string)$row->getData(OpsPersona::schema_fields_AUDIENCE_JSON));
        $pillars = $this->decodeJson((string)$row->getData(OpsPersona::schema_fields_PILLARS_JSON));
        $tone = $this->decodeJson((string)$row->getData(OpsPersona::schema_fields_TONE_JSON));
        $raw = $this->decodeJson((string)$row->getData(OpsPersona::schema_fields_RAW_JSON));
        $summary = (string)$row->getData(OpsPersona::schema_fields_SUMMARY);
        $description = trim((string)($raw['context']['description'] ?? ''));
        $generator = (string)($raw['generator'] ?? '');
        $isAi = str_starts_with($generator, 'ops_persona_ai_');
        $isBlurbPaste = $description !== '' && $this->isNearDuplicate($summary, $description);
        $counts = is_array($raw['context']['evidence_counts'] ?? null)
            ? $raw['context']['evidence_counts']
            : [];

        return [
            'persona_id' => (int)$row->getId(),
            'website_id' => (int)$row->getData(OpsPersona::schema_fields_WEBSITE_ID),
            'title' => (string)$row->getData(OpsPersona::schema_fields_TITLE),
            'summary' => $summary,
            'audience' => $audience,
            'pillars' => $pillars,
            'tone' => $tone,
            'audience_json' => (string)$row->getData(OpsPersona::schema_fields_AUDIENCE_JSON),
            'pillars_json' => (string)$row->getData(OpsPersona::schema_fields_PILLARS_JSON),
            'tone_json' => (string)$row->getData(OpsPersona::schema_fields_TONE_JSON),
            'generator' => $generator,
            'model_code' => (string)($raw['model_code'] ?? ($raw['inferred']['model_code'] ?? '')),
            'is_ai' => $isAi,
            'is_blurb_paste' => $isBlurbPaste,
            'is_structured' => $isAi && !$isBlurbPaste && $this->looksStructured($audience, $pillars, $tone),
            'evidence_counts' => $counts,
            'evidence_gaps' => is_array($raw['context']['evidence_gaps'] ?? null)
                ? $raw['context']['evidence_gaps']
                : [],
            'claims_allowed' => array_values(array_filter(array_map(
                'strval',
                (array)($raw['inferred']['claims_allowed'] ?? [])
            ))),
            'claims_forbidden' => array_values(array_filter(array_map(
                'strval',
                (array)($raw['inferred']['claims_forbidden'] ?? [])
            ))),
            'updated_at' => (string)$row->getData(OpsPersona::schema_fields_UPDATED_AT),
        ];
    }

    /**
     * 必须走 AiRuntime 真实生成；禁止把网站简介原文写入画像；AI 失败则不落库。
     *
     * @return array{ok:bool,persona?:array<string,mixed>,message?:string}
     */
    public function generateFromContext(int $websiteId): array
    {
        return $this->generateFromContextStreaming($websiteId, null);
    }

    /**
     * @param null|callable(string,array<string,mixed>):void $emit SSE/进度回调：event + payload
     * @return array{ok:bool,persona?:array<string,mixed>,message?:string}
     */
    public function generateFromContextStreaming(int $websiteId, ?callable $emit): array
    {
        $emit = $emit ?? static function (string $event, array $data): void {
        };

        $emit('step_start', ['step' => 'site', 'message' => (string)__('开始读取站点身份与描述…')]);
        $ctx = $this->contextReader->read($websiteId);
        if (!($ctx['available'] ?? false)) {
            $msg = (string)($ctx['error'] ?? __('无法读取网站上下文'));
            $emit('step_failed', ['step' => 'site', 'message' => $msg]);

            return ['ok' => false, 'message' => $msg];
        }

        $name = trim((string)($ctx['name'] ?? ''));
        $description = trim((string)($ctx['description'] ?? ''));
        $title = $name !== '' ? $name : (string)__('未命名网站');
        $emit('step_info', [
            'step' => 'site',
            'message' => (string)__('站名：%{1}', [$title]),
        ]);
        if ($description === '') {
            $msg = (string)__('尚未填写网站描述，请先在网站管理补充描述后再刷新画像。');
            $emit('step_failed', ['step' => 'site', 'message' => $msg]);

            return ['ok' => false, 'message' => $msg];
        }
        $emit('step_info', [
            'step' => 'site',
            'message' => (string)__('已读取站描述 %{1} 字（低可信口述，禁止写入摘要）', [(string)mb_strlen($description)]),
        ]);
        $emit('step_success', ['step' => 'site', 'message' => (string)__('站点身份就绪')]);

        $counts = is_array($ctx['evidence_counts'] ?? null) ? $ctx['evidence_counts'] : [];
        $gaps = is_array($ctx['evidence_gaps'] ?? null) ? $ctx['evidence_gaps'] : [];

        $emit('step_start', ['step' => 'catalog', 'message' => (string)__('整合分类树…')]);
        $catCount = (int)($counts['categories'] ?? 0);
        if ($catCount > 0) {
            foreach (array_slice((array)($ctx['categories'] ?? []), 0, 6) as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $emit('step_info', [
                    'step' => 'catalog',
                    'message' => (string)__('分类：%{1}', [(string)($cat['name'] ?? '')]),
                ]);
            }
            $emit('step_success', [
                'step' => 'catalog',
                'message' => (string)__('已整合 %{1} 个顶级分类', [(string)$catCount]),
            ]);
        } else {
            $emit('step_skipped', [
                'step' => 'catalog',
                'message' => (string)__('分类树为空或不可用，已记入证据缺口'),
            ]);
        }

        $emit('step_start', ['step' => 'products', 'message' => (string)__('抽样已发布商品…')]);
        $productCount = (int)($counts['products'] ?? 0);
        if ($productCount > 0) {
            foreach (array_slice((array)($ctx['catalog_sample'] ?? []), 0, 5) as $sample) {
                if (!is_array($sample)) {
                    continue;
                }
                $emit('step_info', [
                    'step' => 'products',
                    'message' => (string)__('商品：%{1}', [(string)($sample['name'] ?? $sample['sku'] ?? '')]),
                ]);
            }
            $band = is_array($ctx['price_band'] ?? null) ? $ctx['price_band'] : null;
            if (is_array($band) && isset($band['min'], $band['max'])) {
                $emit('step_info', [
                    'step' => 'products',
                    'message' => (string)__('价格带抽样：%{1} ~ %{2}（%{3} 个有价）', [
                        (string)$band['min'],
                        (string)$band['max'],
                        (string)($band['count'] ?? 0),
                    ]),
                ]);
            }
            $emit('step_success', [
                'step' => 'products',
                'message' => (string)__('已抽样 %{1} 个商品', [(string)$productCount]),
            ]);
        } else {
            $emit('step_skipped', [
                'step' => 'products',
                'message' => (string)__('无已发布商品可抽样，已记入证据缺口'),
            ]);
        }

        $emit('step_start', ['step' => 'policy', 'message' => (string)__('整合政策与履约边界…')]);
        $policyCount = (int)($counts['policies'] ?? 0);
        if ($policyCount > 0) {
            foreach (array_slice((array)($ctx['policies'] ?? []), 0, 6) as $policy) {
                if (!is_array($policy)) {
                    continue;
                }
                $emit('step_info', [
                    'step' => 'policy',
                    'message' => (string)__('政策：%{1}', [(string)($policy['title'] ?? $policy['key'] ?? '')]),
                ]);
            }
            $faqCount = (int)($counts['faq_themes'] ?? 0);
            if ($faqCount > 0) {
                $emit('step_info', [
                    'step' => 'policy',
                    'message' => (string)__('FAQ 主题 %{1} 条', [(string)$faqCount]),
                ]);
            }
            $emit('step_success', [
                'step' => 'policy',
                'message' => (string)__('已整合 %{1} 条政策/履约信号', [(string)$policyCount]),
            ]);
        } else {
            $emit('step_skipped', [
                'step' => 'policy',
                'message' => (string)__('政策信号不足，已记入证据缺口'),
            ]);
        }

        $emit('step_start', ['step' => 'market', 'message' => (string)__('读取语种与市场配置…')]);
        $locales = is_array($ctx['locales'] ?? null) ? $ctx['locales'] : [];
        if ($locales !== []) {
            $emit('step_info', [
                'step' => 'market',
                'message' => (string)__('启用语种：%{1}', [implode(', ', $locales)]),
            ]);
            $emit('step_success', [
                'step' => 'market',
                'message' => (string)__('主语言 %{1}', [(string)($ctx['primary_locale'] ?? '')]),
            ]);
        } else {
            $emit('step_skipped', [
                'step' => 'market',
                'message' => (string)__('语种配置缺失，使用基线 zh/en'),
            ]);
        }

        $emit('step_start', ['step' => 'compress', 'message' => (string)__('压缩为结构化证据包…')]);
        $siteFacts = is_array($ctx['site_facts'] ?? null) ? $ctx['site_facts'] : [];
        if ($siteFacts === []) {
            $msg = (string)__('证据压缩失败，无法生成画像');
            $emit('step_failed', ['step' => 'compress', 'message' => $msg]);

            return ['ok' => false, 'message' => $msg];
        }
        if ($gaps !== []) {
            $emit('step_warning', [
                'step' => 'compress',
                'message' => (string)__('证据缺口：%{1}', [implode(', ', array_map('strval', $gaps))]),
            ]);
        }
        $emit('step_info', [
            'step' => 'compress',
            'message' => (string)__('证据计数：分类 %{1} / 商品 %{2} / 政策 %{3} / 语种 %{4}', [
                (string)($counts['categories'] ?? 0),
                (string)($counts['products'] ?? 0),
                (string)($counts['policies'] ?? 0),
                (string)($counts['locales'] ?? 0),
            ]),
        ]);
        $emit('step_success', ['step' => 'compress', 'message' => (string)__('证据包已就绪，送入 AI')]);

        $emit('step_start', ['step' => 'ai', 'message' => (string)__('调用云端模型生成运营策略画像…')]);
        $lastError = '';
        $inferred = null;
        for ($attempt = 1; $attempt <= self::AI_ATTEMPTS; $attempt++) {
            $emit('step_info', [
                'step' => 'ai',
                'message' => (string)__('AI 尝试 %{1}/%{2}', [(string)$attempt, (string)self::AI_ATTEMPTS]),
            ]);
            $ai = $this->inferViaAi($title, $siteFacts, $description, $attempt > 1);
            if (!($ai['ok'] ?? false)) {
                $lastError = (string)($ai['message'] ?? __('AI 画像生成失败'));
                $emit('step_warning', ['step' => 'ai', 'message' => $lastError]);
                continue;
            }
            /** @var array<string,mixed> $candidate */
            $candidate = (array)($ai['data'] ?? []);
            $validated = $this->validateAiPersona($candidate, $description, $siteFacts);
            if (!($validated['ok'] ?? false)) {
                $lastError = (string)($validated['message'] ?? __('AI 返回的画像不合格'));
                $emit('step_warning', ['step' => 'ai', 'message' => $lastError]);
                continue;
            }
            $inferred = $candidate;
            break;
        }

        if ($inferred === null) {
            $msg = $lastError !== ''
                ? $lastError
                : (string)__('AI 画像生成失败：未拿到可用模型输出（禁止回退为网站简介或启发式模板）。');
            $emit('step_failed', ['step' => 'ai', 'message' => $msg]);
            $this->logFailure($websiteId, $msg);

            return ['ok' => false, 'message' => $msg];
        }
        $modelUsed = (string)($inferred['model_code'] ?? '');
        $emit('step_success', [
            'step' => 'ai',
            'message' => (string)__('AI 画像合格（模型 %{1}）', [$modelUsed !== '' ? $modelUsed : 'n/a']),
        ]);

        $emit('step_start', ['step' => 'persist', 'message' => (string)__('写入 Ops 结构化记忆…')]);
        $summary = trim((string)($inferred['summary'] ?? ''));
        $audience = [
            'primary' => trim((string)($inferred['audience_primary'] ?? '')),
            'secondary' => trim((string)($inferred['audience_secondary'] ?? '')),
            'jobs' => array_values(array_filter(array_map('strval', (array)($inferred['jobs'] ?? [])))),
        ];
        $pillarsList = array_values(array_filter(array_map('strval', (array)($inferred['pillars'] ?? []))));
        $tone = [
            'voice' => trim((string)($inferred['tone'] ?? '')),
            'do' => array_values(array_filter(array_map('strval', (array)($inferred['tone_do'] ?? [])))),
            'dont' => array_values(array_filter(array_map('strval', (array)($inferred['tone_dont'] ?? [])))),
        ];

        $row = $this->persona->clear()
            ->where(OpsPersona::schema_fields_WEBSITE_ID, max(0, $websiteId))
            ->find()
            ->fetch();
        $row->setData(OpsPersona::schema_fields_WEBSITE_ID, max(0, $websiteId));
        $row->setData(OpsPersona::schema_fields_TITLE, $title);
        $row->setData(OpsPersona::schema_fields_SUMMARY, $summary);
        $row->setData(OpsPersona::schema_fields_AUDIENCE_JSON, $this->encode($audience));
        $row->setData(OpsPersona::schema_fields_PILLARS_JSON, $this->encode(['pillars' => $pillarsList]));
        $row->setData(OpsPersona::schema_fields_TONE_JSON, $this->encode($tone));
        $row->setData(OpsPersona::schema_fields_RAW_JSON, $this->encode([
            'context' => [
                'name' => $name,
                'description' => $description,
                'website_id' => max(0, $websiteId),
                'evidence_counts' => $counts,
                'evidence_gaps' => $gaps,
                'site_facts' => $siteFacts,
            ],
            'generator' => self::GENERATOR_AI,
            'model_code' => $modelUsed,
            'inferred' => $inferred,
        ]));
        $row->save();

        $log = $this->runLog->clear();
        $log->setData(OpsRunLog::schema_fields_WEBSITE_ID, max(0, $websiteId));
        $log->setData(OpsRunLog::schema_fields_KIND, OpsRunLog::KIND_PERSONA);
        $log->setData(OpsRunLog::schema_fields_STATUS, 'ok');
        $log->setData(OpsRunLog::schema_fields_SUMMARY, (string)__('AI 画像已写入结构化记忆'));
        $log->setData(OpsRunLog::schema_fields_BODY, $summary);
        $log->setData(OpsRunLog::schema_fields_EVIDENCE_JSON, $this->encode([
            'website_id' => $websiteId,
            'generator' => self::GENERATOR_AI,
            'model_code' => $modelUsed,
            'evidence_counts' => $counts,
        ]));
        $log->save();

        $emit('step_success', ['step' => 'persist', 'message' => (string)__('画像已写入结构化记忆')]);
        $emit('step_info', [
            'step' => 'persist',
            'message' => (string)__('摘要：%{1}', [mb_substr($summary, 0, 120)]),
        ]);

        return ['ok' => true, 'persona' => $this->getView($websiteId) ?? []];
    }

    /**
     * @param array<string,mixed> $siteFacts
     * @return array{ok:bool,data?:array<string,mixed>,message?:string}
     */
    private function inferViaAi(string $title, array $siteFacts, string $description, bool $strictRetry): array
    {
        $retryHint = $strictRetry
            ? "\nCRITICAL RETRY: Previous answer pasted the blurb, ignored category/policy evidence, or invented unsupported claims. Rewrite ops strategy JSON only from EVIDENCE.\n"
            : '';
        $evidenceJson = json_encode($siteFacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $blurb = mb_substr(trim($description), 0, 1200);

        $prompt = <<<PROMPT
你是独立站增长运营总监（DTC / 垂直电商），不是文案、不是 SEO 堆词器。
任务：基于「站点证据包」产出可派工的运营画像 JSON。
{$retryHint}
硬性规则：
1. 只依据 EVIDENCE 事实推理；缺失写入 assumptions，并降低措辞强度。
2. LOW_TRUST_BLURB 是营销口述，禁止复述/改写粘贴进 summary。
3. 政策与履约边界优先于营销话术：不能承诺证据不支持的配送/退换/功效。
4. 画像服务于选题、社媒、邮件、广告角度、客服口径——要可执行。
5. 字段全部用简体中文。只输出一个 JSON 对象，不要 markdown 代码围栏，不要解释。
6. summary 必须同时出现客群信号与品类/货架信号（不能只有形容词）。

JSON keys:
summary (string),
positioning (string),
audience_primary (string),
audience_secondary (string),
jobs (string array, max 3),
pillars (string array, exactly 3),
tone (string),
tone_do (string array, max 3),
tone_dont (string array, max 3),
claims_allowed (string array, max 3),
claims_forbidden (string array, max 3),
assumptions (string array, max 3).

站名: {$title}

# EVIDENCE（机器采集，可信度高）
{$evidenceJson}

# LOW_TRUST_BLURB（营销口述，禁止写入 summary）
{$blurb}
PROMPT;

        $modelCode = $this->resolvePersonaModelCode();
        if ($modelCode === null) {
            return [
                'ok' => false,
                'message' => (string)__('未找到可用的非 Ollama 文本模型，无法生成真实 AI 画像。请在 AI 供应商中启用 DeepSeek/Gemini 等云端模型。'),
            ];
        }

        try {
            /** @var AiRuntimeInterface $runtime */
            $runtime = ObjectManager::getInstance(AiRuntimeInterface::class);
            $raw = trim($runtime->generate(
                $prompt,
                $modelCode,
                null,
                'zh_Hans_CN',
                [
                    'purpose' => 'ops_persona',
                    'temperature' => 0.5,
                    'model_code' => $modelCode,
                ],
                null,
                true,
            ));
        } catch (\Throwable $e) {
            $msg = trim(strip_tags($e->getMessage()));

            return [
                'ok' => false,
                'message' => (string)__('AI 调用失败（模型 %{model}）：%{msg}', [
                    'model' => $modelCode,
                    'msg' => $msg !== '' ? $msg : get_class($e),
                ]),
            ];
        }

        if ($raw === '') {
            return ['ok' => false, 'message' => (string)__('AI 返回空内容，未生成画像。')];
        }
        if (preg_match('/\{.*\}/s', $raw, $m) === 1) {
            $raw = $m[0];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => (string)__('AI 返回无法解析为 JSON，未写入画像。')];
        }
        $decoded['generator'] = self::GENERATOR_AI;
        $decoded['model_code'] = $modelCode;
        $decoded['raw_model_text'] = mb_substr($raw, 0, 4000);

        return ['ok' => true, 'data' => $decoded];
    }

    /**
     * 选择可用于运营画像的真实云端文本模型（排除 ollama / translategemma）。
     */
    private function resolvePersonaModelCode(): ?string
    {
        try {
            /** @var AiModel $model */
            $model = ObjectManager::getInstance(AiModel::class);
            foreach (self::PREFERRED_MODEL_CODES as $code) {
                $row = $model->clear()
                    ->where(AiModel::schema_fields_MODEL_CODE, $code)
                    ->where(AiModel::schema_fields_STATUS, 'active')
                    ->where(AiModel::schema_fields_IS_ACTIVE, 1)
                    ->find()
                    ->fetch();
                if (!$row->getId()) {
                    continue;
                }
                $supplier = strtolower((string)$row->getData(AiModel::schema_fields_SUPPLIER));
                if ($this->isLocalOllamaSupplier($supplier, $code)) {
                    continue;
                }

                return $code;
            }

            foreach ($model->clear()
                ->where(AiModel::schema_fields_STATUS, 'active')
                ->where(AiModel::schema_fields_IS_ACTIVE, 1)
                ->where(AiModel::schema_fields_PRIMARY_MODALITY, AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT)
                ->select()
                ->fetch()
                ->getItems() as $row) {
                $code = (string)$row->getData(AiModel::schema_fields_MODEL_CODE);
                $supplier = strtolower((string)$row->getData(AiModel::schema_fields_SUPPLIER));
                if ($code === '' || $this->isLocalOllamaSupplier($supplier, $code)) {
                    continue;
                }

                return $code;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function isLocalOllamaSupplier(string $supplier, string $modelCode): bool
    {
        if (str_contains($supplier, 'ollama')) {
            return true;
        }
        if (str_contains(strtolower($modelCode), 'translategemma')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $inferred
     * @param array<string,mixed> $siteFacts
     * @return array{ok:bool,message?:string}
     */
    private function validateAiPersona(array $inferred, string $description, array $siteFacts = []): array
    {
        $summary = trim((string)($inferred['summary'] ?? ''));
        $primary = trim((string)($inferred['audience_primary'] ?? ''));
        $tone = trim((string)($inferred['tone'] ?? ''));
        $pillars = array_values(array_filter(array_map('strval', (array)($inferred['pillars'] ?? []))));

        if ($summary === '' || $primary === '' || $tone === '' || count($pillars) < 3) {
            return ['ok' => false, 'message' => (string)__('AI 画像字段不完整（需 summary / 受众 / 语气 / 至少3个内容支柱）。')];
        }
        if ($this->isNearDuplicate($summary, $description) || $this->containsLongDescriptionChunk($summary, $description)) {
            return ['ok' => false, 'message' => (string)__('AI 输出近似网站简介，已拒绝写入；将重试或请检查模型。')];
        }

        $categoryBlob = strtolower(implode(' ', array_map('strval', (array)($siteFacts['category_map'] ?? []))));
        $catalogBlob = strtolower(implode(' ', array_map('strval', (array)($siteFacts['catalog_titles'] ?? []))));
        $shelfSignal = trim($categoryBlob . ' ' . $catalogBlob);
        if ($shelfSignal !== '' && !$this->summaryHasShelfOrAudienceSignal($summary, $shelfSignal, $primary)) {
            return ['ok' => false, 'message' => (string)__('AI 摘要缺少品类/货架或受众信号，已拒绝。')];
        }

        $forbiddenClaims = ['全球包邮', '医疗功效', '治愈', '100%正品保证', '包治百病'];
        $claimText = $summary . ' ' . implode(' ', array_map('strval', (array)($inferred['claims_allowed'] ?? [])));
        foreach ($forbiddenClaims as $bad) {
            if (mb_strpos($claimText, $bad) !== false && ($siteFacts['policy_digest'] ?? []) === []) {
                // soft: only hard-fail when policy evidence empty AND claim appears in allowed
                if (mb_strpos(implode(' ', array_map('strval', (array)($inferred['claims_allowed'] ?? []))), $bad) !== false) {
                    return ['ok' => false, 'message' => (string)__('AI 承诺了证据不支持的表述（%{1}）', [$bad])];
                }
            }
        }

        return ['ok' => true];
    }

    private function summaryHasShelfOrAudienceSignal(string $summary, string $shelfSignal, string $primary): bool
    {
        $ns = mb_strtolower($summary);
        // audience mention or at least one overlapping token from shelf names (≥2 CJK/latin chars)
        if ($primary !== '' && mb_strpos($ns, mb_strtolower(mb_substr($primary, 0, 4))) !== false) {
            return true;
        }
        $tokens = preg_split('/[\s>\/,，、]+/u', $shelfSignal) ?: [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 2) {
                continue;
            }
            if (mb_strpos($ns, $token) !== false) {
                return true;
            }
        }

        // Fallback: summary looks operational (卖/客/类/转化) rather than pure brand fluff
        return (bool)preg_match('/卖|客群|买家|类目|品类|转化|支柱|场景/u', $summary);
    }

    private function containsLongDescriptionChunk(string $summary, string $description): bool
    {
        $ns = preg_replace('/\s+/u', '', $summary) ?? $summary;
        $nd = preg_replace('/\s+/u', '', $description) ?? $description;
        if ($ns === '' || $nd === '' || mb_strlen($nd) < 24) {
            return false;
        }
        $window = min(36, mb_strlen($nd));
        $len = mb_strlen($nd);
        for ($i = 0; $i <= $len - $window; $i += 12) {
            $chunk = mb_substr($nd, $i, $window);
            if ($chunk !== '' && mb_strpos($ns, $chunk) !== false) {
                return true;
            }
        }

        return false;
    }

    private function logFailure(int $websiteId, string $message): void
    {
        try {
            $log = $this->runLog->clear();
            $log->setData(OpsRunLog::schema_fields_WEBSITE_ID, max(0, $websiteId));
            $log->setData(OpsRunLog::schema_fields_KIND, OpsRunLog::KIND_PERSONA);
            $log->setData(OpsRunLog::schema_fields_STATUS, 'error');
            $log->setData(OpsRunLog::schema_fields_SUMMARY, (string)__('AI 画像生成失败'));
            $log->setData(OpsRunLog::schema_fields_BODY, $message);
            $log->setData(OpsRunLog::schema_fields_EVIDENCE_JSON, $this->encode([
                'website_id' => $websiteId,
                'generator' => self::GENERATOR_AI,
                'failed' => true,
            ]));
            $log->save();
        } catch (\Throwable) {
            // 失败日志不得阻断主错误返回
        }
    }

    private function isNearDuplicate(string $a, string $b): bool
    {
        $na = preg_replace('/\s+/u', '', $a) ?? $a;
        $nb = preg_replace('/\s+/u', '', $b) ?? $b;
        if ($na === '' || $nb === '') {
            return false;
        }
        if ($na === $nb) {
            return true;
        }
        similar_text($na, $nb, $pct);

        return $pct >= 72.0;
    }

    /**
     * @param array<string,mixed> $audience
     * @param array<string,mixed> $pillars
     * @param array<string,mixed> $tone
     */
    private function looksStructured(array $audience, array $pillars, array $tone): bool
    {
        $primary = trim((string)($audience['primary'] ?? ''));
        $pillarList = (array)($pillars['pillars'] ?? []);
        $voice = trim((string)($tone['voice'] ?? ''));

        return $primary !== '' && $voice !== '' && count($pillarList) >= 3;
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
