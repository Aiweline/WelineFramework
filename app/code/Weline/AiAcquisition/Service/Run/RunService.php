<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Run;

use Weline\AiAcquisition\Model\LeadRun;
use Weline\Framework\Manager\ObjectManager;
use Weline\AiAcquisition\Model\PromptProfile;
use Weline\AiAcquisition\Service\Model\ModelCatalogService;
use Weline\AiAcquisition\Service\Prompt\PromptLibrary;
use Weline\AiAcquisition\Service\Site\WebsiteProfileService;

/**
 * 获客运行服务：签发浏览器票据、维护运行状态与 token/成本台账。
 *
 * 票据有效期 2 小时；对外只返回 pending/running 且未过期的运行。
 * 成本统一保留 6 位小数，累计时使用当前值 + 增量。
 */
class RunService
{
    public const TICKET_TTL = 7200;

    public function __construct(
        private readonly LeadRun $runModel,
        private readonly ModelCatalogService $modelCatalog,
        private readonly PromptProfile $promptProfile,
        private readonly ?WebsiteProfileService $websiteProfileService = null,
    ) {
    }

    private function websiteProfiles(): WebsiteProfileService
    {
        return $this->websiteProfileService ?? ObjectManager::getInstance(WebsiteProfileService::class);
    }

    /**
     * 创建运行记录并签发票据。
     *
     * @param array<string,mixed> $data 支持 model_code/website_id/prompt_profile_id/context/
     *                                   created_by/target_count/max_iterations/prompt_body/meta
     * @return array{id:int,ticket:string,ticket_expires_at:int,model_code:string,status:string,currency:string}
     */
    public function create(array $data): array
    {
        $modelMode = strtolower(trim((string)($data['model_mode'] ?? 'ai_module')));
        if (!in_array($modelMode, ['ai_module', 'local_computer', 'browser_local'], true)) {
            throw new \InvalidArgumentException((string)__('模型运行方式不支持：%{1}', [$modelMode]));
        }

        $modelCode = '';
        $localEndpoint = trim((string)($data['local_endpoint'] ?? ''));
        $localModel = trim((string)($data['local_model'] ?? ''));
        $browserModel = trim((string)($data['browser_model'] ?? 'chrome-builtin'));

        if ($modelMode === 'ai_module') {
            $requestedCode = trim((string)($data['model_code'] ?? ''));
            $model = $this->modelCatalog->resolve($requestedCode === '' ? null : $requestedCode);
            if ($model === null) {
                throw new \InvalidArgumentException(
                    (string)__('没有可用的文本模型，请先在 AI 模块配置模型：%{1}', [$requestedCode])
                );
            }
            $modelCode = (string)$model['model_code'];
        } elseif ($modelMode === 'local_computer') {
            if ($localEndpoint === '' || !preg_match('#^https?://#i', $localEndpoint)) {
                throw new \InvalidArgumentException((string)__('请填写本地模型服务地址（http/https）。'));
            }
            if ($localModel === '') {
                throw new \InvalidArgumentException((string)__('请填写本地模型名，例如 qwen2.5:3b。'));
            }
            $modelCode = mb_substr('local:' . $localModel, 0, 100);
        } else {
            if ($browserModel === '') {
                $browserModel = 'chrome-builtin';
            }
            $modelCode = mb_substr('browser:' . $browserModel, 0, 100);
        }

        $websiteId = (int)($data['website_id'] ?? -1);
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('请先选择要获客的网站。'));
        }
        $siteProfile = $this->websiteProfiles()->profile($websiteId);
        if (($siteProfile['name'] ?? '') === '' && !($siteProfile['available'] ?? false)) {
            throw new \InvalidArgumentException((string)__('网站不存在或尚未配置可用的站点画像。'));
        }

        $profileId = max(0, (int)($data['prompt_profile_id'] ?? 0));
        $promptBody = trim((string)($data['prompt_body'] ?? ''));
        if ($profileId > 0) {
            $profile = clone $this->promptProfile;
            $profile->clearData()->reset()->load($profileId);
            if (!$profile->getId()) {
                throw new \InvalidArgumentException(
                    (string)__('提示词档案不存在：%{1}', [$profileId])
                );
            }
            $promptBody = trim((string)$profile->getData(PromptProfile::schema_fields_BODY));
        }

        if ($promptBody === '') {
            $promptBody = (string)(ObjectManager::getInstance(PromptLibrary::class)->defaultProfile()['body'] ?? '');
        }

        $siteContext = $this->websiteProfiles()->buildPromptContext($websiteId);
        if ($siteContext !== '') {
            $promptBody = $promptBody === '' ? $siteContext : $promptBody . "\n\n" . $siteContext;
        }

        $context = trim((string)($data['context'] ?? ''));
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $meta['website_id'] = $websiteId;
        $meta['website_name'] = (string)($siteProfile['name'] ?? '');
        $meta['model_mode'] = $modelMode;
        $meta['local_endpoint'] = $localEndpoint;
        $meta['local_model'] = $localModel;
        $meta['browser_model'] = $browserModel;
        if ($context !== '') {
            $meta['context'] = $context;
            $promptBody = $promptBody === '' ? $context : $promptBody . "\n\n" . $context;
        }

        $now = time();
        $ticket = bin2hex(random_bytes(32));
        $expiresAt = $now + self::TICKET_TTL;

        /** @var LeadRun $run */
        $run = clone $this->runModel;
        $run->clearData()->reset()->setData([
            LeadRun::schema_fields_WEBSITE_ID => $websiteId,
            LeadRun::schema_fields_MODEL_CODE => $modelCode,
            LeadRun::schema_fields_PROMPT_PROFILE_ID => $profileId,
            LeadRun::schema_fields_PROMPT_BODY => $promptBody === '' ? null : $promptBody,
            LeadRun::schema_fields_STATUS => LeadRun::STATUS_PENDING,
            LeadRun::schema_fields_TICKET => $ticket,
            LeadRun::schema_fields_TICKET_EXPIRES_AT => $expiresAt,
            LeadRun::schema_fields_TARGET_COUNT => max(1, min(500, (int)($data['target_count'] ?? 10))),
            LeadRun::schema_fields_MAX_ITERATIONS => max(1, min(50, (int)($data['max_iterations'] ?? 8))),
            LeadRun::schema_fields_CURRENCY => ModelCatalogService::CURRENCY,
            LeadRun::schema_fields_META_JSON => $meta === []
                ? null
                : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LeadRun::schema_fields_CREATED_BY => max(0, (int)($data['created_by'] ?? 0)),
            LeadRun::schema_fields_STARTED_AT => 0,
            LeadRun::schema_fields_FINISHED_AT => 0,
            LeadRun::schema_fields_CREATED_AT => $now,
            LeadRun::schema_fields_UPDATED_AT => $now,
        ])->save();

        $runId = (int)$run->getId();
        if ($runId <= 0) {
            throw new \RuntimeException((string)__('运行记录创建失败。'));
        }

        return [
            'id' => $runId,
            'ticket' => $ticket,
            'ticket_expires_at' => $expiresAt,
            'model_code' => $modelCode,
            'model_mode' => $modelMode,
            'status' => LeadRun::STATUS_PENDING,
            'currency' => ModelCatalogService::CURRENCY,
        ];
    }

    /**
     * 按票据取运行：空/超长/过期/非 pending、running 都返回 null。
     *
     * @return array<string,mixed>|null
     */
    public function findByTicket(string $ticket): ?array
    {
        $ticket = trim($ticket);
        if ($ticket === '' || strlen($ticket) > 64) {
            return null;
        }

        $run = clone $this->runModel;
        $run = $run->clearData()->reset()
            ->where(LeadRun::schema_fields_TICKET, $ticket)
            ->find()
            ->fetch();

        if (!$run || !$run->getId()) {
            return null;
        }

        $row = $run->getData();
        if ((int)($row[LeadRun::schema_fields_TICKET_EXPIRES_AT] ?? 0) <= time()) {
            return null;
        }
        $status = (string)($row[LeadRun::schema_fields_STATUS] ?? '');
        if (!in_array($status, [LeadRun::STATUS_PENDING, LeadRun::STATUS_RUNNING], true)) {
            return null;
        }

        return $this->normalizeRunRow($row);
    }

    public function markRunning(int $runId): bool
    {
        $run = $this->findModel($runId);
        if ($run === null) {
            return false;
        }

        $now = time();
        $fields = [
            LeadRun::schema_fields_STATUS => LeadRun::STATUS_RUNNING,
            LeadRun::schema_fields_UPDATED_AT => $now,
        ];
        if ((int)$run->getData(LeadRun::schema_fields_STARTED_AT) <= 0) {
            $fields[LeadRun::schema_fields_STARTED_AT] = $now;
        }
        $this->updateRun($runId, $fields);

        return true;
    }

    /**
     * 累加 token 与成本；用量为 0 时也正常写 updated_at。
     *
     * @return array{id:int,input_tokens:int,output_tokens:int,cost:float,currency:string}|null
     */
    public function addUsage(int $runId, int $inputTokens, int $outputTokens, float $cost): ?array
    {
        $run = $this->findModel($runId);
        if ($run === null) {
            return null;
        }

        $inputTotal = max(0, (int)$run->getData(LeadRun::schema_fields_INPUT_TOKENS)) + max(0, $inputTokens);
        $outputTotal = max(0, (int)$run->getData(LeadRun::schema_fields_OUTPUT_TOKENS)) + max(0, $outputTokens);
        $costTotal = round(max(0.0, (float)$run->getData(LeadRun::schema_fields_COST)) + $cost, 6);

        $run->setData([
            LeadRun::schema_fields_INPUT_TOKENS => $inputTotal,
            LeadRun::schema_fields_OUTPUT_TOKENS => $outputTotal,
            LeadRun::schema_fields_COST => $costTotal,
            LeadRun::schema_fields_UPDATED_AT => time(),
        ])->save();

        return [
            'id' => $runId,
            'input_tokens' => $inputTotal,
            'output_tokens' => $outputTotal,
            'cost' => $costTotal,
            'currency' => (string)($run->getData(LeadRun::schema_fields_CURRENCY) ?: ModelCatalogService::CURRENCY),
        ];
    }

    /**
     * 结束运行；重复调用幂等（已是终态时不再改写）。
     *
     * stats 中的计数与成本按最终值写入，未提供的字段保持不变。
     *
     * @param array<string,mixed> $stats
     */
    public function finish(int $runId, string $status, array $stats = []): bool
    {
        $status = trim($status);
        if (!in_array($status, [LeadRun::STATUS_COMPLETED, LeadRun::STATUS_FAILED, LeadRun::STATUS_CANCELLED], true)) {
            throw new \InvalidArgumentException(
                (string)__('finish 只接受终态，收到：%{1}', [$status])
            );
        }

        $run = $this->findModel($runId);
        if ($run === null) {
            return false;
        }

        $current = (string)$run->getData(LeadRun::schema_fields_STATUS);
        if (in_array($current, [LeadRun::STATUS_COMPLETED, LeadRun::STATUS_FAILED, LeadRun::STATUS_CANCELLED], true)) {
            return true;
        }

        $now = time();
        $fields = [
            LeadRun::schema_fields_STATUS => $status,
            LeadRun::schema_fields_FINISHED_AT => $now,
            LeadRun::schema_fields_UPDATED_AT => $now,
        ];

        foreach ([
            LeadRun::schema_fields_SEARCHES_COUNT,
            LeadRun::schema_fields_LEADS_FOUND,
            LeadRun::schema_fields_LEADS_SAVED,
            LeadRun::schema_fields_INPUT_TOKENS,
            LeadRun::schema_fields_OUTPUT_TOKENS,
            LeadRun::schema_fields_TARGET_COUNT,
            LeadRun::schema_fields_MAX_ITERATIONS,
        ] as $field) {
            if (array_key_exists($field, $stats)) {
                $fields[$field] = max(0, (int)$stats[$field]);
            }
        }
        if (array_key_exists(LeadRun::schema_fields_COST, $stats)) {
            $fields[LeadRun::schema_fields_COST] = round((float)$stats[LeadRun::schema_fields_COST], 6);
        }
        if (array_key_exists(LeadRun::schema_fields_ERROR, $stats)) {
            $error = trim((string)$stats[LeadRun::schema_fields_ERROR]);
            $fields[LeadRun::schema_fields_ERROR] = $error === '' ? null : $error;
        }
        if (isset($stats['meta']) && is_array($stats['meta'])) {
            $fields[LeadRun::schema_fields_META_JSON] = json_encode(
                $stats['meta'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        $this->updateRun($runId, $fields);

        return true;
    }

    /**
     * 取消运行；已完成/失败的运行不可取消。
     */
    public function cancel(int $runId): bool
    {
        $run = $this->findModel($runId);
        if ($run === null) {
            return false;
        }

        $current = (string)$run->getData(LeadRun::schema_fields_STATUS);
        if ($current === LeadRun::STATUS_CANCELLED) {
            return true;
        }
        if (in_array($current, [LeadRun::STATUS_COMPLETED, LeadRun::STATUS_FAILED], true)) {
            return false;
        }

        $now = time();
        $this->updateRun($runId, [
            LeadRun::schema_fields_STATUS => LeadRun::STATUS_CANCELLED,
            LeadRun::schema_fields_FINISHED_AT => $now,
            LeadRun::schema_fields_UPDATED_AT => $now,
        ]);

        return true;
    }

    /**
     * 运行列表：支持 status/model_code/website_id/keyword 过滤。
     *
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,pagination:array<string,mixed>,total:int}
     */
    public function listing(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(200, $pageSize));

        $model = clone $this->runModel;
        $model->clearData()->reset();
        $this->applyListingFilters($model, $filters);

        $items = $model->order(LeadRun::schema_fields_CREATED_AT, 'DESC')
            ->order(LeadRun::schema_fields_ID, 'DESC')
            ->pagination($page, $pageSize, $filters)
            ->select()
            ->fetchArray();

        $pagination = $model->getPaginationState();

        return [
            'items' => $items,
            'pagination' => $pagination,
            'total' => (int)($pagination['totalSize'] ?? 0),
        ];
    }

    /**
     * 运行详情（含台账字段）。
     *
     * @return array<string,mixed>|null
     */
    public function status(int $runId): ?array
    {
        $run = $this->findModel($runId);
        if ($run === null) {
            return null;
        }

        return $this->normalizeRunRow($run->getData());
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function applyListingFilters(LeadRun $query, array $filters): void
    {
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '' && isset(LeadRun::STATUSES[$status])) {
            $query->where(LeadRun::schema_fields_STATUS, $status);
        }

        $modelCode = trim((string)($filters['model_code'] ?? ''));
        if ($modelCode !== '') {
            $query->where(LeadRun::schema_fields_MODEL_CODE, $modelCode);
        }

        if (
            array_key_exists('website_id', $filters)
            && $filters['website_id'] !== null
            && $filters['website_id'] !== ''
        ) {
            $query->where(LeadRun::schema_fields_WEBSITE_ID, max(0, (int)$filters['website_id']));
        }

        $keyword = trim((string)($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $query->where(LeadRun::schema_fields_MODEL_CODE, $like, 'LIKE', 'OR')
                ->where(LeadRun::schema_fields_TICKET, $like, 'LIKE', 'OR')
                ->where(LeadRun::schema_fields_ERROR, $like, 'LIKE');
        }
    }

    private function findModel(int $runId): ?LeadRun
    {
        if ($runId <= 0) {
            return null;
        }

        $run = clone $this->runModel;
        $run->clearData()->reset()->load($runId);

        return $run->getId() ? $run : null;
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function updateRun(int $runId, array $fields): void
    {
        $run = clone $this->runModel;
        $run->clearData()->reset()
            ->where(LeadRun::schema_fields_ID, $runId)
            ->update($fields)
            ->fetch();
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRunRow(array $row): array
    {
        $status = (string)($row[LeadRun::schema_fields_STATUS] ?? '');

        return [
            'id' => (int)($row[LeadRun::schema_fields_ID] ?? 0),
            'website_id' => (int)($row[LeadRun::schema_fields_WEBSITE_ID] ?? 0),
            'status' => $status,
            'status_label' => (string)(LeadRun::STATUSES[$status] ?? $status),
            'model_code' => (string)($row[LeadRun::schema_fields_MODEL_CODE] ?? ''),
            'prompt_profile_id' => (int)($row[LeadRun::schema_fields_PROMPT_PROFILE_ID] ?? 0),
            'prompt_body' => (string)($row[LeadRun::schema_fields_PROMPT_BODY] ?? ''),
            'ticket_expires_at' => (int)($row[LeadRun::schema_fields_TICKET_EXPIRES_AT] ?? 0),
            'target_count' => (int)($row[LeadRun::schema_fields_TARGET_COUNT] ?? 0),
            'max_iterations' => (int)($row[LeadRun::schema_fields_MAX_ITERATIONS] ?? 0),
            'searches_count' => (int)($row[LeadRun::schema_fields_SEARCHES_COUNT] ?? 0),
            'leads_found' => (int)($row[LeadRun::schema_fields_LEADS_FOUND] ?? 0),
            'leads_saved' => (int)($row[LeadRun::schema_fields_LEADS_SAVED] ?? 0),
            'input_tokens' => (int)($row[LeadRun::schema_fields_INPUT_TOKENS] ?? 0),
            'output_tokens' => (int)($row[LeadRun::schema_fields_OUTPUT_TOKENS] ?? 0),
            'cost' => round((float)($row[LeadRun::schema_fields_COST] ?? 0), 6),
            'currency' => (string)($row[LeadRun::schema_fields_CURRENCY] ?? ModelCatalogService::CURRENCY),
            'error' => (string)($row[LeadRun::schema_fields_ERROR] ?? ''),
            'meta' => $this->decodeMeta((string)($row[LeadRun::schema_fields_META_JSON] ?? '')),
            'created_at' => (int)($row[LeadRun::schema_fields_CREATED_AT] ?? 0),
            'updated_at' => (int)($row[LeadRun::schema_fields_UPDATED_AT] ?? 0),
            'started_at' => (int)($row[LeadRun::schema_fields_STARTED_AT] ?? 0),
            'finished_at' => (int)($row[LeadRun::schema_fields_FINISHED_AT] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta(string $metaJson): array
    {
        if ($metaJson === '') {
            return [];
        }

        $decoded = json_decode($metaJson, true);

        return is_array($decoded) ? $decoded : [];
    }
}
