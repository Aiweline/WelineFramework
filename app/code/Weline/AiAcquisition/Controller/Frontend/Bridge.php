<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Controller\Frontend;

use Weline\AiAcquisition\Model\BridgeEvent;
use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Model\LeadRun;
use Weline\AiAcquisition\Service\Bridge\BridgeService;
use Weline\AiAcquisition\Service\Lead\LeadService;
use Weline\AiAcquisition\Service\Model\ModelCatalogService;
use Weline\AiAcquisition\Service\Model\ModelGatewayService;
use Weline\AiAcquisition\Service\Prompt\PromptLibrary;
use Weline\AiAcquisition\Service\Run\RunService;
use Weline\AiAcquisition\Service\Scan\ScanRegistry;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Websites\Model\Website;

use function __;

/**
 * 浏览器桥前端 REST 接口（票据鉴权，不需要后台会话）。
 *
 * 路由：/ai_acquisition/frontend/bridge/{action}
 * 所有 POST 只接受 application/json；错误统一返回 {success:false,message}；
 * 票据只从 body/query 读取，任何响应与日志都不回显票据。
 */
class Bridge extends FrontendController
{
    /** SSE 单连接最长秒数 */
    private const STREAM_MAX_SECONDS = 25;
    /** SSE 拉取间隔毫秒 */
    private const STREAM_PULL_INTERVAL_MS = 500;
    /** SSE 心跳间隔秒 */
    private const STREAM_HEARTBEAT_SECONDS = 15;
    /** JSON 请求体上限 */
    private const JSON_MAX_BYTES = 262144;
    /** 单次 step 的消息条数上限 */
    private const MAX_MESSAGES = 60;
    /** 单条事件负载上限 */
    private const EVENT_PAYLOAD_MAX_BYTES = 8192;
    private const SCAN_BATCH_LIMIT = 50;

    /**
     * 运行上下文：运行记录、提示词、模型、报价、站点。
     *
     * GET /ai_acquisition/frontend/bridge/context?ticket=
     */
    public function getContext(): string
    {
        return $this->guard(function (): array {
            $run = $this->bridge()->validateTicket($this->queryTicket());
            $runId = (int)($run[LeadRun::schema_fields_ID] ?? 0);
            if ($runId <= 0) {
                throw new \RuntimeException(__('运行记录不存在'), 404);
            }
            $this->bridge()->heartbeat($runId, ['event' => 'context']);

            $meta = \is_array($run['meta'] ?? null) ? $run['meta'] : [];
            $modelMode = (string)($meta['model_mode'] ?? 'ai_module');
            $modelCode = (string)($run[LeadRun::schema_fields_MODEL_CODE] ?? '');
            $maxIterations = \max(1, (int)($run[LeadRun::schema_fields_MAX_ITERATIONS] ?? 8));
            $model = null;
            $quote = [];
            if ($modelMode === 'ai_module') {
                $model = $this->catalog()->resolve($modelCode !== '' ? $modelCode : null);
                $quote = $model !== null
                    ? $this->catalog()->estimateRun((string)($model['model_code'] ?? $modelCode), $maxIterations)
                    : [];
            } else {
                $model = [
                    'model_code' => $modelCode,
                    'name' => (string)($meta['local_model'] ?? $meta['browser_model'] ?? $modelCode),
                    'supplier' => $modelMode === 'local_computer' ? 'local' : 'browser',
                ];
                $quote = [
                    'input_price' => 0,
                    'output_price' => 0,
                    'currency' => 'CNY',
                    'priced' => false,
                    'total_cost' => 0,
                ];
            }
            $prompt = (string)($run[LeadRun::schema_fields_PROMPT_BODY] ?? '');
            if ($prompt === '') {
                $prompt = (string)(ObjectManager::getInstance(PromptLibrary::class)->defaultProfile()['body'] ?? '');
            }

            return [
                'ok' => true,
                'run' => [
                    'id' => $runId,
                    'status' => (string)($run[LeadRun::schema_fields_STATUS] ?? ''),
                    'model_code' => $modelCode,
                    'target_count' => (int)($run[LeadRun::schema_fields_TARGET_COUNT] ?? 0),
                    'max_iterations' => $maxIterations,
                    'leads_saved' => (int)($run[LeadRun::schema_fields_LEADS_SAVED] ?? 0),
                    'cost' => (float)($run[LeadRun::schema_fields_COST] ?? 0),
                    'currency' => (string)($run[LeadRun::schema_fields_CURRENCY] ?? 'CNY'),
                    'ticket_expires_at' => (int)($run[LeadRun::schema_fields_TICKET_EXPIRES_AT] ?? 0),
                ],
                'prompt' => $prompt,
                'model' => $model,
                'model_mode' => $modelMode,
                'local_endpoint' => (string)($meta['local_endpoint'] ?? ''),
                'local_model' => (string)($meta['local_model'] ?? ''),
                'browser_model' => (string)($meta['browser_model'] ?? ''),

                'quote' => $quote,
                'website' => $this->websiteContext((int)($run[LeadRun::schema_fields_WEBSITE_ID] ?? 0)),
                'query_slots' => $this->querySlots($meta),
                // 已扫描页面/已获客域名：扩展与工具据此跳过重复页面，避免反复搜同一批结果。
                'scan' => $this->scanRegistry()->exclusionContext((int)($run[LeadRun::schema_fields_WEBSITE_ID] ?? 0)),
                'last_event_id' => $this->bridge()->lastSeen($runId),
            ];
        });
    }

    /**
     * SSE 事件流：每 500ms 拉取一次，最多 25s，15s 心跳。
     *
     * GET /ai_acquisition/frontend/bridge/stream?ticket=&last_event_id=
     */
    public function getStream(): void
    {
        $sse = (new SseWriter())
            ->setRetryInterval(3000)
            ->setHeartbeatInterval(self::STREAM_HEARTBEAT_SECONDS)
            ->setCorsOrigin(null);

        try {
            $run = $this->bridge()->validateTicket($this->queryTicket());
        } catch (\Throwable $exception) {
            $sse->start();
            $sse->sendControlEvent('error', [
                'success' => false,
                'message' => $exception->getMessage(),
            ]);
            $sse->close();
            return;
        }

        $runId = (int)($run[LeadRun::schema_fields_ID] ?? 0);
        if ($runId <= 0) {
            $sse->start();
            $sse->sendControlEvent('error', ['success' => false, 'message' => __('运行记录不存在')]);
            $sse->close();
            return;
        }

        $requested = (int)$this->request->getGet('last_event_id', 0);
        $cursor = $requested > 0 ? $requested : ($this->bridge()->lastSeen($runId) ?? 0);

        $sse->start();
        $sse->sendControlEvent('bridge', [
            'type' => 'connected',
            'run_id' => $runId,
            'last_event_id' => $cursor,
        ]);

        $deadline = \microtime(true) + self::STREAM_MAX_SECONDS;
        try {
            while (\microtime(true) < $deadline && $sse->isAlive()) {
                foreach ($this->bridge()->pull($runId, $cursor, 20) as $event) {
                    $sse->sendEvent('bridge', $event, (int)$event['id']);
                    $cursor = \max($cursor, (int)$event['id']);
                }
                $sse->maybeHeartbeat();
                if (!$sse->isAlive()) {
                    break;
                }
                SchedulerSystem::yieldDelay(self::STREAM_PULL_INTERVAL_MS);
            }
            $sse->sendControlEvent('done', [
                'run_id' => $runId,
                'last_event_id' => $cursor,
            ]);
        } catch (\Throwable) {
            $sse->sendControlEvent('error', ['success' => false, 'message' => __('事件拉取失败')]);
        }
        $sse->close();
    }

    /**
     * 运行状态：扩展助手轮询（面板展示、暂停/停止感知）。
     *
     * GET /ai_acquisition/frontend/bridge/status?ticket=
     */
    public function getStatus(): string
    {
        return $this->guard(function (): array {
            $run = $this->bridge()->validateTicket($this->queryTicket());
            $runId = $this->runId($run);
            $status = (string)($run[LeadRun::schema_fields_STATUS] ?? '');
            $meta = \is_array($run['meta'] ?? null) ? $run['meta'] : [];

            return [
                'ok' => true,
                'run' => [
                    'id' => $runId,
                    'status' => $status,
                    'status_label' => (string)(LeadRun::STATUSES[$status] ?? $status),
                    'model_mode' => (string)($meta['model_mode'] ?? 'ai_module'),
                    'model_label' => (string)($meta['bridge_model_label'] ?? ''),
                    'target_count' => (int)($run[LeadRun::schema_fields_TARGET_COUNT] ?? 0),
                    'leads_saved' => (int)($run[LeadRun::schema_fields_LEADS_SAVED] ?? 0),
                    'searches_count' => (int)($run[LeadRun::schema_fields_SEARCHES_COUNT] ?? 0),
                    'input_tokens' => (int)($run[LeadRun::schema_fields_INPUT_TOKENS] ?? 0),
                    'output_tokens' => (int)($run[LeadRun::schema_fields_OUTPUT_TOKENS] ?? 0),
                    'cost' => (float)($run[LeadRun::schema_fields_COST] ?? 0),
                    'currency' => (string)($run[LeadRun::schema_fields_CURRENCY] ?? 'CNY'),
                    'ticket_expires_at' => (int)($run[LeadRun::schema_fields_TICKET_EXPIRES_AT] ?? 0),
                ],
            ];
        });
    }

    /**
     * 模型初始化成功：pending → running（运行记录此刻才进入执行中）。
     *
     * POST /ai_acquisition/frontend/bridge/ready
     * Body: {ticket,model_mode?,model_label?}
     */
    public function postReady(): string
    {
        return $this->guard(function (): array {
            $body = $this->jsonBody();
            $run = $this->bridge()->validateTicket($this->bodyTicket($body));
            $runId = $this->runId($run);
            $this->bridge()->assertRateLimit($runId);

            $status = (string)($run[LeadRun::schema_fields_STATUS] ?? '');
            if (!\in_array($status, [LeadRun::STATUS_PENDING, LeadRun::STATUS_RUNNING], true)) {
                throw new \RuntimeException(__('运行当前状态不可接入：%{1}', [$status]), 409);
            }

            $this->runService()->markBridgeReady($runId, [
                'model_mode' => (string)($body['model_mode'] ?? ''),
                'model_label' => (string)($body['model_label'] ?? ''),
            ]);
            $this->bridge()->heartbeat($runId, ['event' => 'ready']);

            return ['ok' => true, 'run_id' => $runId, 'status' => LeadRun::STATUS_RUNNING];
        });
    }

    /**
     * 运行控制：暂停 / 继续 / 停止（票据鉴权，扩展助手与后台共用）。
     *
     * POST /ai_acquisition/frontend/bridge/control
     * Body: {ticket,action}
     */
    public function postControl(): string
    {
        return $this->guard(function (): array {
            $body = $this->jsonBody();
            $run = $this->bridge()->validateTicket($this->bodyTicket($body));
            $runId = $this->runId($run);
            $this->bridge()->assertRateLimit($runId);

            $action = \strtolower(\trim((string)($body['action'] ?? '')));
            $status = (string)($run[LeadRun::schema_fields_STATUS] ?? '');
            $terminal = [LeadRun::STATUS_COMPLETED, LeadRun::STATUS_FAILED, LeadRun::STATUS_CANCELLED];

            if ($action === 'pause') {
                if ($status !== LeadRun::STATUS_RUNNING) {
                    throw new \RuntimeException(__('只有执行中的运行可以暂停'), 409);
                }
                $this->runService()->pause($runId);
            } elseif ($action === 'resume') {
                if ($status !== LeadRun::STATUS_PAUSED) {
                    throw new \RuntimeException(__('只有已暂停的运行可以继续'), 409);
                }
                $this->runService()->resume($runId);
            } elseif ($action === 'stop') {
                if (!\in_array($status, $terminal, true)) {
                    $this->runService()->cancel($runId);
                }
            } else {
                throw new \InvalidArgumentException(__('不支持的运行控制：%{1}', [$action]));
            }

            $nextStatus = match ($action) {
                'pause' => LeadRun::STATUS_PAUSED,
                'resume' => LeadRun::STATUS_RUNNING,
                default => \in_array($status, $terminal, true) ? $status : LeadRun::STATUS_CANCELLED,
            };
            $updated = $this->runService()->status($runId) ?? [];
            // 同一请求内读回可能落在只读副本上，明确以本次动作的目标状态为准
            $updated['status'] = $nextStatus;
            $updated['status_label'] = (string)(LeadRun::STATUSES[$nextStatus] ?? $nextStatus);
            $this->bridge()->enqueue($runId, 'control', [
                'action' => $action,
                'status' => $nextStatus,
            ], BridgeEvent::DIRECTION_SERVER_TO_BROWSER);

            return ['ok' => true, 'action' => $action, 'run' => $updated];
        });
    }

    /**
     * 浏览器回传事件。
     *
     * POST /ai_acquisition/frontend/bridge/event
     * Body: {ticket,type,payload}
     */
    public function postEvent(): string
    {
        return $this->guard(function (): array {
            $body = $this->jsonBody();
            $run = $this->bridge()->validateTicket($this->bodyTicket($body));
            $runId = $this->runId($run);
            $this->assertExecutable($run);
            $this->bridge()->assertRateLimit($runId);

            $type = BridgeService::normalizeType((string)($body['type'] ?? ''));
            $payload = $body['payload'] ?? [];
            if (!\is_array($payload)) {
                throw new \InvalidArgumentException(__('payload 必须是 JSON 对象'));
            }
            if (\strlen(BridgeService::encodePayload($payload)) > self::EVENT_PAYLOAD_MAX_BYTES) {
                throw new \InvalidArgumentException(__('payload 过大'));
            }

            $this->bridge()->enqueue(
                $runId,
                $type,
                $payload,
                BridgeEvent::DIRECTION_BROWSER_TO_SERVER
            );
            $this->bridge()->heartbeat($runId, ['event' => $type]);

            return ['ok' => true];
        });
    }

    /**
     * 上报已扫描页面：扩展抓过的 URL 落站点级台账，下次运行直接跳过。
     *
     * POST /ai_acquisition/frontend/bridge/scan
     * Body: {ticket,urls:[string,...]}
     */
    public function postScan(): string
    {
        return $this->guard(function (): array {
            $body = $this->jsonBody();
            $run = $this->bridge()->validateTicket($this->bodyTicket($body));
            $runId = $this->runId($run);
            $this->assertExecutable($run);
            $this->bridge()->assertRateLimit($runId);

            $urls = $body['urls'] ?? [];
            if (!\is_array($urls)) {
                throw new \InvalidArgumentException(__('urls 必须是数组'));
            }
            $clean = [];
            foreach (\array_slice($urls, 0, self::SCAN_BATCH_LIMIT) as $url) {
                $url = \trim((string)$url);
                if ($url !== '' && \preg_match('#^https?://#i', $url) === 1) {
                    $clean[$url] = true;
                }
            }
            if ($clean === []) {
                return ['ok' => true, 'added' => 0];
            }

            $added = $this->scanRegistry()->mark(
                (int)($run[LeadRun::schema_fields_WEBSITE_ID] ?? 0),
                \array_keys($clean),
                $runId
            );
            $this->bridge()->heartbeat($runId, ['event' => 'scan']);

            return ['ok' => true, 'added' => $added];
        });
    }

    /**
     * 浏览器智能体步进：调用运行所选模型，记 token 与成本。
     *
     * POST /ai_acquisition/frontend/bridge/step
     * Body: {ticket,messages,tools,params}
     */
    public function postStep(): string
    {
        return $this->guard(function (): array {
            $body = $this->jsonBody();
            $run = $this->bridge()->validateTicket($this->bodyTicket($body));
            $runId = $this->runId($run);
            $this->assertExecutable($run);
            $this->bridge()->assertRateLimit($runId);

            $meta = \is_array($run['meta'] ?? null) ? $run['meta'] : [];
            $modelMode = (string)($meta['model_mode'] ?? 'ai_module');
            if ($modelMode === 'local_computer' || $modelMode === 'browser_local') {
                return $this->recordLocalStep($run, $runId, $modelMode, $body);
            }

            $messages = $body['messages'] ?? [];
            if (!\is_array($messages) || $messages === []) {
                throw new \InvalidArgumentException(__('messages 不能为空'));
            }
            if (\count($messages) > self::MAX_MESSAGES) {
                throw new \InvalidArgumentException(__('messages 数量超出上限'));
            }
            $tools = $body['tools'] ?? [];
            if (!\is_array($tools)) {
                throw new \InvalidArgumentException(__('tools 必须是数组'));
            }
            $params = $body['params'] ?? [];
            if (!\is_array($params)) {
                throw new \InvalidArgumentException(__('params 必须是数组'));
            }
            $params = $this->safeModelParams($params);

            $modelCode = (string)($run[LeadRun::schema_fields_MODEL_CODE] ?? '');
            $model = $this->catalog()->resolve($modelCode !== '' ? $modelCode : null);
            if ($model === null) {
                throw new \RuntimeException(__('运行使用的模型不可用'), 409);
            }

            try {
                $result = $this->gateway()->chat($model, $messages, $tools, $params);
            } catch (\InvalidArgumentException $exception) {
                throw new \InvalidArgumentException($exception->getMessage());
            } catch (\RuntimeException $exception) {
                throw new \RuntimeException(__('模型调用失败：') . $exception->getMessage(), 502);
            }

            $usage = \is_array($result['usage'] ?? null) ? $result['usage'] : [];
            $promptTokens = (int)($usage['prompt_tokens'] ?? 0);
            $completionTokens = (int)($usage['completion_tokens'] ?? 0);
            $cost = (float)($result['cost'] ?? 0);
            $this->runService()->addUsage($runId, $promptTokens, $completionTokens, $cost);

            $toolCalls = \is_array($result['tool_calls'] ?? null) ? $result['tool_calls'] : [];
            $content = (string)($result['content'] ?? '');
            $assistantMessage = \is_array($result['assistant_message'] ?? null)
                ? $result['assistant_message']
                : ['role' => 'assistant', 'content' => $content];

            $this->bridge()->enqueue($runId, 'step', [
                'model_code' => (string)($result['model_code'] ?? $modelCode),
                'tool_calls' => \count($toolCalls),
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
            ], BridgeEvent::DIRECTION_BROWSER_TO_SERVER);

            return [
                'ok' => true,
                'assistant' => $content,
                'assistant_message' => $assistantMessage,
                'tool_calls' => $toolCalls,
                'usage' => [
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens' => (int)($usage['total_tokens'] ?? ($promptTokens + $completionTokens)),
                ],
                'cost' => $cost,
                'currency' => (string)($result['currency'] ?? 'CNY'),
                'model_code' => (string)($result['model_code'] ?? $modelCode),
            ];
        });
    }

    /**
     * 线索回传入库（去重合并由 LeadService 负责）。
     *
     * POST /ai_acquisition/frontend/bridge/lead
     * Body: {ticket,lead}
     */
    public function postLead(): string
    {
        return $this->guard(function (): array {
            $body = $this->jsonBody();
            $run = $this->bridge()->validateTicket($this->bodyTicket($body));
            $runId = $this->runId($run);
            $this->assertExecutable($run);
            $this->bridge()->assertRateLimit($runId);

            $lead = $body['lead'] ?? null;
            if (!\is_array($lead) || $lead === []) {
                throw new \InvalidArgumentException(__('lead 必须是 JSON 对象'));
            }

            $meta = is_array($run['meta'] ?? null) ? $run['meta'] : [];
            $modelMode = (string)($meta['model_mode'] ?? 'ai_module');
            if ($modelMode === 'browser_local' || $modelMode === 'local_computer') {
                $lead['entry_channel'] = Lead::ENTRY_CHANNEL_BROWSER_MODEL;
            } elseif (!isset($lead['entry_channel']) || trim((string)$lead['entry_channel']) === '') {
                $lead['entry_channel'] = Lead::ENTRY_CHANNEL_DEFAULT;
            }

            $result = $this->leadService()->save($lead, $runId);
            if (!empty($result['created'])) {
                $this->bridge()->markLeadSaved($runId);
            }
            $this->bridge()->enqueue($runId, 'lead', [
                'created' => (bool)($result['created'] ?? false),
            ], BridgeEvent::DIRECTION_BROWSER_TO_SERVER);

            return [
                'ok' => true,
                'lead' => $result['lead'] ?? null,
                'created' => (bool)($result['created'] ?? false),
            ];
        });
    }

    /**
     * 运行结束：写最终状态与统计。
     *
     * POST /ai_acquisition/frontend/bridge/complete
     * Body: {ticket,status,stats}
     */
    public function postComplete(): string
    {
        return $this->guard(function (): array {
            $body = $this->jsonBody();
            $runId = $this->runId($this->bridge()->validateTicket($this->bodyTicket($body)));
            $this->bridge()->assertRateLimit($runId);

            $status = (string)($body['status'] ?? LeadRun::STATUS_COMPLETED);
            if (!\in_array($status, [
                LeadRun::STATUS_COMPLETED,
                LeadRun::STATUS_FAILED,
                LeadRun::STATUS_CANCELLED,
            ], true)) {
                throw new \InvalidArgumentException(__('运行状态不合法'));
            }

            $stats = $body['stats'] ?? [];
            if (!\is_array($stats)) {
                throw new \InvalidArgumentException(__('stats 必须是数组'));
            }

            // 零产出不算完成：目标没达成本次运行就是失败，与端侧判定保持一致。
            if ($status === LeadRun::STATUS_COMPLETED && (int)($stats['leads_saved'] ?? 0) <= 0) {
                $status = LeadRun::STATUS_FAILED;
                $stats['error'] = $stats['error']
                    ?? __('未找到符合条件的线索，请调整目标市场或检索词后重试。');
            }

            $this->runService()->finish($runId, $status, $this->safeStats($stats));
            $this->bridge()->enqueue($runId, 'complete', [
                'status' => $status,
            ], BridgeEvent::DIRECTION_BROWSER_TO_SERVER);

            return ['ok' => true, 'status' => $status];
        });
    }

    /**
     * 已有线索摘要，供浏览器端 list_existing_leads 工具去重。
     *
     * GET /ai_acquisition/frontend/bridge/leads?ticket=&keyword=&limit=
     */
    public function getLeads(): string
    {
        return $this->guard(function (): array {
            $run = $this->bridge()->validateTicket($this->queryTicket());
            $runId = $this->runId($run);
            $this->bridge()->assertRateLimit($runId);

            $keyword = \trim((string)$this->request->getGet('keyword', ''));
            $limit = \max(1, \min(20, (int)$this->request->getGet('limit', 10)));
            $filters = ['keyword' => $keyword];
            $websiteId = (int)($run[LeadRun::schema_fields_WEBSITE_ID] ?? 0);
            if ($websiteId > 0) {
                $filters['website_id'] = $websiteId;
            }

            $listing = $this->leadService()->listing($filters, 1, $limit);
            $items = [];
            foreach (\is_array($listing['items'] ?? null) ? $listing['items'] : [] as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $items[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'company_name' => (string)($row['company_name'] ?? ''),
                    'company_domain' => (string)($row['company_domain'] ?? ''),
                    'country_code' => (string)($row['country_code'] ?? ''),
                    'email' => (string)($row['email'] ?? ''),
                    'status' => (string)($row['status'] ?? ''),
                    'ai_score' => (int)($row['ai_score'] ?? 0),
                    'source_url' => (string)($row['source_url'] ?? ''),
                ];
            }

            return [
                'ok' => true,
                'items' => $items,
                'total' => (int)($listing['total'] ?? 0),
            ];
        });
    }

    /**
     * 统一异常出口：错误体固定包含 success=false 与 message。
     */
    private function guard(callable $action): string
    {
        try {
            return $this->json((array)$action());
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (\RuntimeException $exception) {
            $code = (int)$exception->getCode();
            $status = $code >= 400 && $code <= 599 ? $code : 400;
            return $this->json(['success' => false, 'message' => $exception->getMessage()], $status);
        } catch (\Throwable $throwable) {
            w_log_error('[AiAcquisition] bridge unhandled: ' . $throwable->getMessage()
                . ' @' . $throwable->getFile() . ':' . $throwable->getLine());
            return $this->json(['success' => false, 'message' => __('请求处理失败，请稍后重试')], 500);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): string
    {
        $response = Response::json($payload, $status);
        try {
            if (isset($this->request)) {
                $scoped = $this->request->getResponse();
                $scoped->setHttpResponseCode($status);
                $scoped->setHeader('Content-Type', 'application/json; charset=utf-8');
            }
        } catch (\Throwable) {
            // 无请求上下文（CLI/测试）时只返回响应体
        }

        return (string)$response->getBody();
    }

    /**
     * 只接受 application/json 的 JSON 对象请求体。
     *
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $contentType = \strtolower((string)$this->request->getContentType());
        if (!\str_contains($contentType, 'application/json')) {
            throw new \InvalidArgumentException(__('仅接受 application/json 请求体'));
        }

        $body = $this->request->getBodyParams();
        if (\is_string($body)) {
            $body = \json_decode($body, true);
        }
        if (!\is_array($body) || $body === []) {
            throw new \InvalidArgumentException(__('请求体必须是 JSON 对象'));
        }
        if (\strlen((string)\json_encode($body)) > self::JSON_MAX_BYTES) {
            throw new \InvalidArgumentException(__('请求体过大'));
        }

        return $body;
    }

    private function queryTicket(): string
    {
        return \trim((string)$this->request->getGet('ticket', ''));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function bodyTicket(array $body): string
    {
        $ticket = $body['ticket'] ?? $this->request->getGet('ticket', '');
        if (!\is_string($ticket) || \trim($ticket) === '') {
            throw new \InvalidArgumentException(__('缺少票据'));
        }

        return \trim($ticket);
    }

    /**
     * 只有执行中的运行可以回传事件 / 步进 / 线索；暂停与终态一律拒绝。
     *
     * @param array<string, mixed> $run
     */
    private function assertExecutable(array $run): void
    {
        $status = (string)($run[LeadRun::schema_fields_STATUS] ?? '');
        if ($status === LeadRun::STATUS_PAUSED) {
            throw new \RuntimeException((string)__('运行已暂停，请在扩展助手或运行记录中继续'), 409);
        }
        if ($status !== LeadRun::STATUS_RUNNING) {
            throw new \RuntimeException((string)__('运行当前状态不可执行：%{1}', [$status]), 409);
        }
    }

    /**
     * @param array<string, mixed> $run
     */
    private function runId(array $run): int
    {
        $runId = (int)($run[LeadRun::schema_fields_ID] ?? 0);
        if ($runId <= 0) {
            throw new \RuntimeException(__('运行记录不存在'), 404);
        }

        return $runId;
    }

    /**
     * 模型参数白名单：拒绝任何后端地址、密钥、供应商覆盖。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */

    /**
     * 本地电脑 / 浏览器端侧模型的步进回传：只记 usage 与事件，不在服务端调用模型。
     *
     * @param array<string,mixed> $run
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function recordLocalStep(array $run, int $runId, string $modelMode, array $body): array
    {
        $local = $body['local_result'] ?? null;
        if (!\is_array($local) || $local === []) {
            throw new \InvalidArgumentException((string)__('本地模型结果为空。'));
        }
        $assistant = (string)($local['assistant'] ?? '');
        $toolCalls = $local['tool_calls'] ?? [];
        if (!\is_array($toolCalls)) {
            throw new \InvalidArgumentException((string)__('本地模型 tool_calls 必须是数组。'));
        }
        $toolCalls = \array_slice(\array_values(\array_filter(
            $toolCalls,
            static fn ($call): bool => \is_array($call) && (
                isset($call['function']['name']) || isset($call['name'])
            )
        )), 0, 20);
        $usage = \is_array($local['usage'] ?? null) ? $local['usage'] : [];
        $promptTokens = \max(0, \min(1000000, (int)($usage['prompt_tokens'] ?? 0)));
        $completionTokens = \max(0, \min(1000000, (int)($usage['completion_tokens'] ?? 0)));

        $this->runService()->addUsage($runId, $promptTokens, $completionTokens, 0);
        $this->bridge()->enqueue($runId, 'step', [
            'model_code' => (string)($run[LeadRun::schema_fields_MODEL_CODE] ?? ''),
            'model_mode' => $modelMode,
            'tool_calls' => \count($toolCalls),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
        ], BridgeEvent::DIRECTION_BROWSER_TO_SERVER);

        $assistantMessage = ['role' => 'assistant', 'content' => $assistant];
        if ($toolCalls !== []) {
            $assistantMessage['tool_calls'] = $toolCalls;
        }

        return [
            'ok' => true,
            'assistant' => $assistant,
            'assistant_message' => $assistantMessage,
            'tool_calls' => $toolCalls,
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ],
            'cost' => 0,
            'currency' => 'CNY',
            'model_code' => (string)($run[LeadRun::schema_fields_MODEL_CODE] ?? ''),
            'model_mode' => $modelMode,
        ];
    }

    private function safeModelParams(array $params): array
    {
        $floatKeys = [
            'temperature' => [0.0, 2.0],
            'top_p' => [0.0, 1.0],
            'presence_penalty' => [-2.0, 2.0],
            'frequency_penalty' => [-2.0, 2.0],
        ];
        $intKeys = [
            'max_tokens' => [1, 32000],
            'seed' => [0, 2147483647],
        ];

        $safe = [];
        foreach ($floatKeys as $key => [$min, $max]) {
            if (\array_key_exists($key, $params) && \is_numeric($params[$key])) {
                $safe[$key] = \max($min, \min($max, (float)$params[$key]));
            }
        }
        foreach ($intKeys as $key => [$min, $max]) {
            if (\array_key_exists($key, $params) && \is_numeric($params[$key])) {
                $safe[$key] = \max($min, \min($max, (int)$params[$key]));
            }
        }
        if (isset($params['tool_choice']) && \is_string($params['tool_choice'])) {
            $toolChoice = \strtolower(\trim($params['tool_choice']));
            if (\in_array($toolChoice, ['auto', 'none', 'required'], true)) {
                $safe['tool_choice'] = $toolChoice;
            }
        }

        return $safe;
    }

    /**
     * 运行统计白名单。
     *
     * @param array<string, mixed> $stats
     * @return array<string, int>
     */
    private function safeStats(array $stats): array
    {
        $safe = [];
        foreach (['searches_count', 'leads_found', 'leads_saved', 'iterations'] as $key) {
            if (\array_key_exists($key, $stats) && \is_numeric($stats[$key])) {
                $safe[$key] = \max(0, (int)$stats[$key]);
            }
        }
        // 失败原因要落库，否则后台只看到状态看不到为什么停。
        if (isset($stats['error']) && \is_scalar($stats['error'])) {
            $error = \trim((string)$stats['error']);
            if ($error !== '') {
                $safe['error'] = \mb_substr($error, 0, 500);
            }
        }

        return $safe;
    }

    /**
     * @param array<string, mixed> $meta
     * @return list<string>
     */
    private function querySlots(array $meta): array
    {
        $slots = $meta['query_slots'] ?? [];
        if (!\is_array($slots)) {
            return [];
        }

        $result = [];
        foreach ($slots as $slot) {
            if (\is_string($slot) && \trim($slot) !== '') {
                $result[] = \trim($slot);
            }
            if (\count($result) >= 20) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return array{id:int,name:string,url:string}|null
     */
    private function websiteContext(int $websiteId): ?array
    {
        if ($websiteId < 0 || !\class_exists(Website::class)) {
            return null;
        }

        try {
            $row = ObjectManager::getInstance(Website::class)
                ->clearData()
                ->clearQuery()
                ->where(Website::schema_fields_ID, $websiteId)
                ->find()
                ->fetchArray();
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($row) || $row === []) {
            return null;
        }

        return [
            'id' => $websiteId,
            'name' => (string)($row[Website::schema_fields_NAME] ?? ''),
            'url' => (string)($row[Website::schema_fields_URL] ?? ''),
        ];
    }

    private function scanRegistry(): ScanRegistry
    {
        return ObjectManager::getInstance(ScanRegistry::class);
    }

    private function bridge(): BridgeService
    {
        return ObjectManager::getInstance(BridgeService::class);
    }

    private function runService(): RunService
    {
        return ObjectManager::getInstance(RunService::class);
    }

    private function catalog(): ModelCatalogService
    {
        return ObjectManager::getInstance(ModelCatalogService::class);
    }

    private function gateway(): ModelGatewayService
    {
        return ObjectManager::getInstance(ModelGatewayService::class);
    }

    private function leadService(): LeadService
    {
        return ObjectManager::getInstance(LeadService::class);
    }
}
