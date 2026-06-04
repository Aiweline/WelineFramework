<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Queue;

use GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonStateService;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueLogWriter;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
use GuoLaiRen\PageBuilder\Service\AiSiteWorkflowTrace;
use Weline\Ai\Service\AiRuntimeContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Queue\DeadWorkerRecoverableQueueInterface;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class AiSiteBuildQueue implements QueueInterface, DeadWorkerRecoverableQueueInterface
{
    private const DEFAULT_MAX_ATTEMPTS = 3;
    private const CONTENT_ATTEMPT_KEY = 'attempt';
    private const CONTENT_MAX_ATTEMPTS_KEY = 'max_attempts';
    private const CONTENT_LAST_GATE_REASON_KEY = 'last_gate_reason';
    private const CONTENT_LAST_GATE_AT_KEY = 'last_gate_at';
    private const CONTENT_LAST_GATE_DECISION_KEY = 'completion_gate_decision';
    private const QUEUE_CONTENT_PROGRESS_MERGE_MAX_BYTES = 262144;
    private const QUEUE_CONTENT_PROGRESS_KEYS = [
        'plan_json_task_summary' => true,
        'page_block_progress' => true,
        'plan_json_block_progress' => true,
        'progress_percent' => true,
        'active_concurrency' => true,
    ];
    private const REQUEST_CTX_INLINE_IMAGE_GENERATION_DISABLED = 'pagebuilder.ai.inline_image_generation.disabled';
    private const QUEUE_SCOPE_PATCH_REDUNDANT_KEYS = [
        'content_manifest' => true,
        'build_contracts' => true,
        'render_data_contract' => true,
        'qa_report_contract' => true,
        'task_results' => true,
        'qa_report_v2' => true,
        'repair_patch' => true,
    ];
    private const BUILD_QUEUE_SCOPE_ARTIFACT_KEYS = [
        'plan_json',
    ];

    public function name(): string
    {
        return 'PageBuilder AI site build';
    }

    public function tip(): string
    {
        return 'Run PageBuilder AI site plan_json block node work asynchronously and persist build progress.';
    }

    public function attributes(): array
    {
        return [];
    }

    public function validate(Queue &$queue): bool
    {
        $content = \json_decode((string)$queue->getContent(), true);
        if (!\is_array($content)) {
            return false;
        }

        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? ''));
        if ($publicId === '' || $adminId <= 0 || $executionToken === '') {
            return false;
        }

        /** @var AiSiteAgentSessionService $sessionService */
        $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        $session = $sessionService->loadByPublicId($publicId, $adminId);

        return $session instanceof AiSiteAgentSession;
    }

    public function shouldRecoverDeadWorker(Queue $queue, int $deadPid, string $workerOutput): bool
    {
        unset($deadPid, $workerOutput);
        $content = \json_decode((string)$queue->getContent(), true);
        if (!\is_array($content)) {
            return false;
        }

        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? ''));
        if ($publicId === '' || $adminId <= 0 || $executionToken === '') {
            return false;
        }

        $operation = $this->normalizeQueuedOperation((string)($content['operation'] ?? 'build'));

        return \in_array($operation, ['build', 'regenerate_page', 'block_regenerate', 'block_partial_patch'], true);
    }

    public function deadWorkerRecoveryMessage(Queue $queue, int $deadPid, string $workerOutput): string
    {
        unset($queue, $deadPid, $workerOutput);

        return 'PageBuilder build worker exited before terminal state; queue reset to pending for scheduler resume.';
    }

    public function execute(Queue &$queue): string
    {
        $content = \json_decode((string)$queue->getContent(), true);
        $content = \is_array($content) ? $content : [];
        $publicId = \trim((string)($content['public_id'] ?? ''));
        $adminId = (int)($content['admin_id'] ?? 0);
        $executionToken = \trim((string)($content['execution_token'] ?? ''));
        $operation = $this->normalizeQueuedOperation((string)($content['operation'] ?? 'build'));
        $forceNewExecutionToken = \in_array($operation, ['build', 'regenerate_page', 'block_regenerate', 'block_partial_patch'], true)
            && (int)($content['_force_rebuild'] ?? 0) === 1;
        $forceFullBuildRegeneration = $operation === 'build' && $forceNewExecutionToken;
        $effectiveExecutionToken = $executionToken;
        if ($forceNewExecutionToken) {
            $effectiveExecutionToken = \sprintf(
                '%s-force-%s',
                $executionToken !== '' ? $executionToken : 'queue',
                \substr(\sha1((string)\microtime(true) . ':' . (string)\mt_rand()), 0, 10)
            );
        }
        $queueId = (int)$queue->getId();
        [$content, $attempt, $maxAttempts] = $this->beginQueueAttempt(
            $queue,
            $content,
            $effectiveExecutionToken !== '' ? $effectiveExecutionToken : $executionToken
        );
        $scopePatch = \is_array($content['scope_patch'] ?? null) ? $content['scope_patch'] : [];

        $sse = null;
        $previousAiRuntimeParamsExists = false;
        $previousAiRuntimeParams = [];
        $aiRuntimeParamsRegistered = false;
        $previousInlineImageDisabledExists = false;
        $previousInlineImageDisabled = null;
        $inlineImageDisabledRegistered = false;
        try {
            if ($this->queuedPayloadDisablesInlineImageGeneration($content, $scopePatch)) {
                $previousInlineImageDisabledExists = RequestContext::has(self::REQUEST_CTX_INLINE_IMAGE_GENERATION_DISABLED);
                $previousInlineImageDisabled = RequestContext::get(self::REQUEST_CTX_INLINE_IMAGE_GENERATION_DISABLED);
                RequestContext::set(self::REQUEST_CTX_INLINE_IMAGE_GENERATION_DISABLED, true);
                $inlineImageDisabledRegistered = true;
                $this->appendQueueLifecycleLine($queue, 'Inline image generation disabled for this queue run; image slots will be deferred for later retry.');
            }
            $this->appendQueueLifecycleLine($queue, '闁诲孩顔栭崰鎺楀磻閹炬枼鏀芥い鏃傗拡閸庢劕顭胯閺咁偊骞?queue_id=' . $queueId . ' public_id=' . $publicId . ' admin_id=' . $adminId);

            /** @var AiSiteAgentSessionService $sessionService */
            $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
            /** @var AiSiteScopeCompatibilityService $scopeService */
            $scopeService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
            /** @var AiSitePlanJsonTaskService $planJsonTaskService */
            $planJsonTaskService = ObjectManager::getInstance(AiSitePlanJsonTaskService::class);

            $session = $sessionService->loadByPublicId($publicId, $adminId);
            if (!$session instanceof AiSiteAgentSession) {
                throw new \RuntimeException('Session not found or access denied.');
            }
            if ($attempt > $maxAttempts) {
                $message = 'PageBuilder build queue has already run '
                    . $maxAttempts
                    . ' automatic attempts; automatic retry is stopped. Please confirm manually before running again.';
                $scope = $scopeService->normalizeScope(
                    $this->loadBuildQueueScope($sessionService, $session)
                );
                $scope = $this->patchBuildActiveOperationForAttemptLimit(
                    $scope,
                    $operation,
                    $queueId,
                    $attempt,
                    $maxAttempts,
                    $effectiveExecutionToken,
                    $message
                );
                $sessionService->replaceScope((int)$session->getId(), $adminId, $scope);
                $this->markQueueStopped($queue, $content, $message);

                return $message;
            }
            AiSiteWorkflowTrace::log('queue_build_execute_start', [
                'public_id' => $publicId,
                'queue_id' => $queueId,
                'operation' => $operation,
                'execution_token' => $effectiveExecutionToken,
                'force_rebuild' => $forceFullBuildRegeneration,
            ]);
            $this->appendQueueLifecycleLine($queue, '闁诲海鎳撻幉陇銇愰崘鈺傚弿闁绘劕鐡ㄦ慨婊堟煙鐎涙ê绗х紒鎰殜閹?session_id=' . (int)$session->getId());
            $supersedingQueueRow = $this->findSupersedingQueueRow(
                $queueId,
                $queue->getBizKey(),
                $operation,
                $effectiveExecutionToken
            );
            if ($supersedingQueueRow !== []) {
                $newerQueueId = (int)($supersedingQueueRow['queue_id'] ?? 0);
                $message = 'Superseded by newer PageBuilder queue #' . $newerQueueId . '; skipped duplicate AI execution.';
                $this->appendQueueLifecycleLine($queue, $message);

                return $message;
            }
            $activeQueueId = $this->resolveActiveQueueIdForQueuedOperation(
                $sessionService,
                $scopeService,
                $session,
                $operation,
                $effectiveExecutionToken
            );
            if ($activeQueueId > 0 && $activeQueueId !== $queueId && $activeQueueId > $queueId) {
                $message = 'Active operation is already owned by newer queue #' . $activeQueueId . '; skipped duplicate AI execution.';
                $this->appendQueueLifecycleLine($queue, $message);

                return $message;
            }
            if ($forceFullBuildRegeneration) {
                $session = $this->applyForceBuildQueuePreset($sessionService, $scopeService, $session, $adminId);
                $this->appendQueueLifecycleLine(
                    $queue,
                    'force rebuild requested; refreshed execution_token=' . \substr($effectiveExecutionToken, 0, 24)
                );
            }

            $session = $this->ensureQueuedActiveOperation(
                $sessionService,
                $scopeService,
                $session,
                $adminId,
                $queueId,
                $operation,
                $effectiveExecutionToken
            );
            $this->appendQueueLifecycleLine(
                $queue,
                'active_operation=queued operation=' . $operation . ' execution_token=' . \substr($effectiveExecutionToken, 0, 12)
            );

            $scope = $scopeService->normalizeScope(
                $this->loadBuildQueueScope($sessionService, $session)
            );
            $confirmedScope = $scope;
            if ($operation === 'build') {
                $scopePatch = $planJsonTaskService->stripPlanJsonMutationScopePatch($scopePatch, $confirmedScope);
                if ($scopePatch !== []) {
            $scope = $scopeService->normalizeScope(\array_replace($scope, $scopePatch));
                }
                $scope = $planJsonTaskService->restorePlanJsonContract($scope, $confirmedScope);
                $workspaceTrack = $scopeService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
                $scope = $planJsonTaskService->ensureTaskScope(
                    $scope,
                    \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                    $workspaceTrack !== '' ? $workspaceTrack : AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
                );
                if ($forceFullBuildRegeneration) {
                    $scope = $planJsonTaskService->clearBuildArtifactsForRegeneration($scope);
                    $scope = $planJsonTaskService->resetPlanJsonTasksToPendingForRebuild($scope, false);
                }
                $normalizedScope = $planJsonTaskService->normalizeConfirmedPlanJsonFlag($scope);
                $scopeChanged = $normalizedScope !== $confirmedScope;
                $scope = $normalizedScope;
            } elseif (\in_array($operation, ['block_regenerate', 'block_partial_patch'], true) && $scopePatch !== []) {
            $scope = $scopeService->normalizeScope(\array_replace($scope, $scopePatch));
                $scopeChanged = $scope !== $confirmedScope;
            } else {
                $scopeChanged = false;
            }
            if ($scopeChanged) {
                $sessionService->replaceScope((int)$session->getId(), $adminId, $scope);
                $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
            }
            if (!$planJsonTaskService->hasConfirmedPlanJsonForBuild($scope)) {
                throw new \RuntimeException('Please confirm plan_json before build.');
            }

            $sse = new AiSiteQueueLogWriter(
                (int)$session->getId(),
                $adminId,
                $queueId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                $operation,
                $effectiveExecutionToken,
                \trim((string)($content['job_key'] ?? '')),
                \trim((string)($content['job_type'] ?? ''))
            );
            $previousAiRuntimeParamsExists = AiRuntimeContext::hasDefaultParams();
            $previousAiRuntimeParams = AiRuntimeContext::getDefaultParams();
            AiRuntimeContext::setDefaultParams(AiRuntimeContext::thinkingModeParams());
            $aiRuntimeParamsRegistered = true;
            $this->queueTrace($sse, 'AI thinking mode enabled for queue execution; reasoning_content is kept separate from output content.');
            $this->queueTrace($sse, 'Queue log writer created; build progress is recorded as compact queue telemetry.');

            /** @var AiSiteAgent $controller */
            $controller = AiSiteAgentForQueue::create();
            $claim = $this->invokePrivate($controller, 'claimActiveOperationExecution', [$session, $adminId, $effectiveExecutionToken, $operation, 'queue']);
            if (!\is_array($claim) || !($claim['ok'] ?? false)) {
                if ((string)($claim['reason'] ?? '') === 'duplicate_stream') {
                    $this->queueTrace($sse, 'duplicate_stream claim rejected; keep queue open for manual retry.');

                    throw new \RuntimeException((string)__(
                        'Duplicate build task claim detected; refresh the workspace and retry build manually.'
                    ));
                }

                throw new \RuntimeException((string)($claim['message'] ?? 'Operation claim failed.'));
            }
            $this->queueTrace($sse, 'claimActiveOperationExecution ok; entering queued operation=' . $operation);

            // mergeScope 闂備礁鎲￠悷顖涚濠靛枹娲锤濡も偓濡﹢鏌℃径搴㈢《婵ǜ鍔戦弻?scope闂備焦瀵х粙鎺旂矙閹达箑鑸归悗鐢电《閸嬫挸鈽夊▍顓炪偢閹虫瑩骞嬮敂钘夊壄?$session 闂備礁鎲￠悷顖炲垂閻㈢绀傛俊顖濆吹椤╅鈧箍鍎遍幊鎰板极閵娾晜鐓涢柛鎰鐎氼剟鍩涢弮鍫熷仩婵炴垶顭囬悘鍗炩攽椤旇姤鍊愭鐐╁亾婵炴挻鑹鹃敃锔剧矆婢跺⊕褰掑礂閼规澘顥濆銈嗘礋娴滃爼骞?ensureTaskScope 缂傚倸鍊风紞鈧柛娑卞灡閺嗕即姊洪崨濠呭闁绘妫濋幊鐔兼偄閸濄儮鏋?done 濠电偛顕慨鏉戭潩閿曞倸鐒垫い鎺嗗亾闁活剙銈搁敐鐐参旈崘顏嗭紲闂佽鍎抽悺銊т焊閸℃稒鐓?
            $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;

            // 闂備礁鎼崑鍡涘储妤ｅ啫纾婚柨婵嗘川绾惧吋銇勯幘璺盒ｉ柡鍛倐閺岀喓鈧稒锚婵洭鏌涘▎蹇旑棦鐎规洩缍侀弫鎰緞鐎ｆ挴鏅濋埀顒€鐏氭竟瀣ｉ崟顓燁潟闁谎呮疅ild operation 闂佸搫顦弲婊呯矙閹达箑鐭楅柛鈩冪☉缁犲磭鎲稿澶婃槬婵°倕鎳庨梻顖炴煏婵炲灝鈧顢樺ú顏呯厱闁规儳纾埢宀€绱掓潏銊х疄鐎规洘顨婂畷濂告偄閼茶　鏅涢埥澶愬箻缁涜鏁惧銈嗗姌閸嬫劕鈽夐悽鍓叉晢闁逞屽墰缁岸宕稿Δ浣规珫闂侀潻瀵屽鈧琈/kill -9/Worker 婵犳鍠楃换鍡涱敊婵犲喚娈介柟闂寸劍閺?
            // 婵犵數鍋涢惇鏌ュΧ閸喎鍙?catch 闂備礁鎲＄敮鎺懳涘▎鎾虫瀬妞ゆ洍鍋撻柡浣哥У瀵板嫮鈧綆鍓氬▓顓㈡⒑娴兼瑧绋婚柣鐔濆啠鏋?status=running 濠电偛顕慨楣冾敋瑜庨幈銊╂偄閺嬵偀鍋撻幒妤€宸濇い鎾跺枔椤?pending+attempt_no=0闂備線娼уΛ妤呭磹閻ｅ本宕查柡宥庡幖閸愨偓闂佺偓鑹鹃崐濠氭偡閹惧绠?
            // pickConcurrentTasks 闂備胶鍘ч悿鍥ㄦ叏閵堝洠鍋撻敐鍌氫壕闂備礁鎲￠悷锕傛偋閺囥垹绀傞梺顒€绉寸粈鍕煃瑜滈崜鐔煎箚?task闂備焦瀵х粙鎴︽儗閸屾稑顕遍柍鍝勬噹缁€?markTaskRunning 闂?bumpAttempt 闂?
            // attempt_no 缂傚倷绶氱涵鎼佸垂閻㈠壊鏁囬柟闂寸缁€?PLAN_JSON_TASK_MAX_GENERATION_ATTEMPTS=3 濠电偞鍨堕弻銊╊敄閸涙潙绠栨俊銈呭暞閸嬫牗銇勯幇鍓佺ɑ妞ゃ垹绉撮埥?failed闂?
            // 闂佽崵濮崇拋鏌ュ疾濞戞鏆﹂柣鏃€鎮舵禍?闂?section 闂備焦鐪归崝宀€鈧凹鍣ｉ幃鑺ョ節濮橆厼浠洪梺缁樻⒒椤牓鎮橀崶顒佺厱婵炲棙锚閻忕姵绻濋埀顒勵敂閸涱垪鏋栭悗骞垮劚鐎氼喚鎷归埡鍛仯?-f 闂備焦鐪归崝宀€鈧凹鍓氶弲鍫曟偐鐠囪尙顔岄梺褰掑亰娴滅偤鎮烽幘缁樼厵閻庢稒锚婵鏌ｅ┑鍥╂创濠?
            // controller 闂備胶顭堢换鍫ュ磿鏉堫偁浜?runHtmlBlockNodesBuildOperationV3 濠电偞鍨跺濠氬窗閹邦剨鑰垮〒姘ｅ亾鐎规洘顨呴…銊╁礃椤忓啩瀛?reset闂備焦瀵х粙鎴︽儗娴ｇ儤宕查柡宥庡幗閻撳倿鎮橀悙鍨珪婵炵》绲介湁婵犲﹤鍟撮妤冣偓娈垮枦瀹曞灚绂掗敃鍌氱＜闁绘劘鎳曢埡鍐ｅ亾闂堟稏鍋夐柟闈涘暱娴?
            if ($operation === 'build') {
                $resumeScope = $scopeService->normalizeScope(
                    $this->loadBuildQueueScope($sessionService, $session)
                );
                if ($forceFullBuildRegeneration) {
                    $resetScope = $planJsonTaskService->clearBuildArtifactsForRegeneration($resumeScope);
                    $resetScope = $planJsonTaskService->resetPlanJsonTasksToPendingForRebuild($resetScope, false);
                    if ($resetScope !== $resumeScope) {
                        $sessionService->replaceScope((int)$session->getId(), $adminId, $resetScope);
                        $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
                        $this->queueTrace($sse, 'force rebuild: cleared stale build artifacts and reset all plan_json block node work.');
                    }
                } elseif (isset($resumeScope['_build_regeneration']) || isset($resumeScope['_queue_force_build'])) {
                    unset($resumeScope['_build_regeneration'], $resumeScope['_queue_force_build']);
                    $resumeScope = $planJsonTaskService->reconcileGeneratedArtifactsWithTaskState($resumeScope, true);
                    $sessionService->replaceScope((int)$session->getId(), $adminId, $resumeScope);
                    $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
                    $this->queueTrace($sse, 'resume build: cleared stale force markers and synced existing artifacts.');
                }
                $resumeScope = $scopeService->normalizeScope(
                    $this->loadBuildQueueScope($sessionService, $session)
                );
                $resetScope = $planJsonTaskService->resetRunningTasksForInterruptedBuild(
                    $resumeScope,
                    'Queue restart: clearing stale running tasks for resume.'
                );
                if ($resetScope !== $resumeScope) {
                    $sessionService->replaceScope((int)$session->getId(), $adminId, $resetScope);
                    $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
                    $this->queueTrace($sse, 'queue restart: cleared stale running tasks for resume.');
                }
            }

            $operationContexts = $operation === 'block_regenerate'
                ? $this->resolveQueuedOperationContexts($content, $sessionService, $session, $scopeService)
                : [];

            if ($operation === 'block_regenerate') {
                foreach ($operationContexts as $operationContext) {
                    $this->invokePrivate($controller, 'runRegenerateBlockOperation', [
                        $sse,
                        $session,
                        $adminId,
                        (string)($operationContext['page_type'] ?? ''),
                        (string)($operationContext['component_code'] ?? ''),
                        (string)($operationContext['instruction'] ?? ''),
                    ]);
                    $session = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
                }
            } elseif ($operation === 'block_partial_patch') {
                $operationContext = $this->resolveQueuedOperationContext($content, $sessionService, $session, $scopeService);
                $this->invokePrivate($controller, 'runBlockPartialPatchOperation', [
                    $sse,
                    $session,
                    $adminId,
                    (string)($operationContext['page_type'] ?? ''),
                    (string)($operationContext['component_code'] ?? ''),
                    (string)($operationContext['instruction'] ?? ''),
                    $effectiveExecutionToken,
                ]);
            } elseif ($operation === 'regenerate_page') {
                $this->invokePrivate($controller, 'runRegeneratePageOperation', [
                    $sse,
                    $session,
                    $adminId,
                    $this->resolveQueuedPageType($content, $sessionService, $session, $scopeService),
                ]);
            } else {
                $this->invokePrivate($controller, 'runBuildOperation', [$sse, $session, $adminId]);
            }
            $this->queueTrace($sse, '闂傚倸鍊搁崯浼村窗鎼淬劌鍨傛い蹇撶墕缁犺偐鈧箍鍎辩€氼喚绮欐繝鍕ㄥ亾鐟欏嫮鎽冮悘蹇ｄ簽閹广垽骞嬮敃鈧悙?operation=' . $operation);

            if (\in_array($operation, ['build', 'regenerate_page'], true)) {
                $gateAction = $this->finalizeQueueBuildCompletion(
                    $queue,
                    $sessionService,
                    $scopeService,
                    $planJsonTaskService,
                    $session,
                    $adminId,
                    $operation,
                    $effectiveExecutionToken,
                    $queueId,
                    $attempt,
                    $maxAttempts
                );
                if (($gateAction['action'] ?? '') === 'retryable') {
                    $retryMessage = (string)($gateAction['message'] ?? 'Build queue marked retryable after completion gate failure.');
                    $this->queueTrace($sse, 'completion gate blocked, queue marked retryable: ' . $retryMessage);

                    return $retryMessage;
                }
                $this->markQueueBuildOperationPassedGate(
                    $sessionService,
                    $scopeService,
                    $planJsonTaskService,
                    $session,
                    $adminId,
                    $operation,
                    $effectiveExecutionToken,
                    $queueId
                );
            } elseif (\in_array($operation, ['block_regenerate', 'block_partial_patch'], true)) {
                $this->markQueueBuildOperationPassedGate(
                    $sessionService,
                    $scopeService,
                    $planJsonTaskService,
                    $session,
                    $adminId,
                    $operation,
                    $effectiveExecutionToken,
                    $queueId
                );
            }
            if ($forceNewExecutionToken) {
                $this->clearQueueForceBuildMarker($sessionService, (int)$session->getId(), $adminId);
            }

            if (\in_array($operation, ['build', 'regenerate_page'], true)) {
                $this->assertBuildQueueMayFinish($sessionService, $scopeService, $planJsonTaskService, $session, $adminId, $operation);
            }

            $doneMessage = $this->buildOperationDoneMessage($operation);
            $this->queueTrace($sse, 'queue operation succeeded: ' . $doneMessage);
            $this->markQueueDone($queue, $doneMessage);
            $sse->complete();

            return $doneMessage;
        } catch (\Throwable $throwable) {
            $diagnostic = $this->formatThrowableDiagnostic($throwable);
            $surfaceMessage = $this->summarizeThrowableForQueueSurface($throwable);
            AiSiteWorkflowTrace::log('build_queue_exception', [
                'queue_id' => $queueId,
                'operation' => $operation,
                'public_id' => $publicId,
                'diagnostic' => $diagnostic,
                'surface_message' => $surfaceMessage,
            ]);
            $this->logInternalBuildQueueDiagnostic($queueId, $operation, $publicId, $diagnostic, $surfaceMessage);
            $this->mirrorToCli('[' . \date('H:i:s') . '] ERROR_DIAGNOSTIC ' . $diagnostic);
            $throwable = new \RuntimeException($surfaceMessage, 0, $throwable);
            $diagnostic = $surfaceMessage;
            if ($sse instanceof AiSiteQueueLogWriter) {
                $this->queueTrace($sse, 'exception: ' . $diagnostic);
            } else {
                $this->appendQueueLifecycleLine($queue, 'exception before queue log writer initialized: ' . $diagnostic);
            }
            $this->updateSessionError($publicId, $adminId, $effectiveExecutionToken, $throwable->getMessage());
            throw new \RuntimeException('Build failed: ' . $throwable->getMessage(), 0, $throwable);
        } finally {
            if ($aiRuntimeParamsRegistered) {
                if ($previousAiRuntimeParamsExists) {
                    AiRuntimeContext::setDefaultParams($previousAiRuntimeParams);
                } else {
                    AiRuntimeContext::removeDefaultParams();
                }
            }
            if ($inlineImageDisabledRegistered) {
                if ($previousInlineImageDisabledExists) {
                    RequestContext::set(self::REQUEST_CTX_INLINE_IMAGE_GENERATION_DISABLED, $previousInlineImageDisabled);
                } else {
                    RequestContext::remove(self::REQUEST_CTX_INLINE_IMAGE_GENERATION_DISABLED);
                }
            }
            if ($sse instanceof AiSiteQueueLogWriter) {
                $sse->complete();
            }
        }
    }

    /**
     * Forced retries rotate execution_token and reset plan_json block node status so
     * page/block regeneration can run again without duplicate streams.
     */
    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed> $scopePatch
     */
    private function queuedPayloadDisablesInlineImageGeneration(array $content, array $scopePatch): bool
    {
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $buildOptions = \is_array($scopePatch['ai_site_build_options'] ?? null)
            ? $scopePatch['ai_site_build_options']
            : [];
        $runtimeOptions = \is_array($scopePatch['runtime'] ?? null) ? $scopePatch['runtime'] : [];

        foreach ([
            $content['disable_inline_image_generation'] ?? null,
            $content['skip_inline_image_generation'] ?? null,
            $content['ai_site_test_skip_images'] ?? null,
            $content['pagebuilder_ai_skip_inline_images'] ?? null,
            $details['disable_inline_image_generation'] ?? null,
            $details['skip_inline_image_generation'] ?? null,
            $details['test_skip_inline_images'] ?? null,
            $details['ai_site_test_skip_images'] ?? null,
            $scopePatch['_disable_inline_image_generation'] ?? null,
            $scopePatch['_skip_inline_image_generation'] ?? null,
            $scopePatch['disable_inline_image_generation'] ?? null,
            $scopePatch['skip_inline_image_generation'] ?? null,
            $scopePatch['test_skip_inline_images'] ?? null,
            $scopePatch['ai_site_test_skip_images'] ?? null,
            $scopePatch['pagebuilder_ai_skip_inline_images'] ?? null,
            $buildOptions['disable_inline_image_generation'] ?? null,
            $buildOptions['skip_inline_image_generation'] ?? null,
            $buildOptions['test_skip_inline_images'] ?? null,
            $runtimeOptions['disable_inline_image_generation'] ?? null,
            $runtimeOptions['skip_inline_image_generation'] ?? null,
            \getenv('PAGEBUILDER_AI_SITE_SKIP_INLINE_IMAGES') ?: null,
        ] as $value) {
            if ($this->isTruthyQueueSwitchValue($value)) {
                return true;
            }
        }

        return false;
    }

    private function isTruthyQueueSwitchValue(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }
        if (!\is_scalar($value)) {
            return false;
        }

        $normalized = \strtolower(\trim((string)$value));
        if ($normalized === '') {
            return false;
        }

        return !\in_array($normalized, ['0', 'false', 'no', 'off', 'disable', 'disabled'], true);
    }

    private function normalizeQueuedOperation(string $operation): string
    {
        $operation = \trim($operation);

        return \in_array($operation, ['build', 'block_regenerate', 'block_partial_patch', 'regenerate_page'], true) ? $operation : 'build';
    }

    private function buildOperationDoneMessage(string $operation): string
    {
        return match ($operation) {
            default => 'Queued AI operation failed.',
        };
    }

    private function resolveQueuedPageType(
        array $content,
        AiSiteAgentSessionService $sessionService,
        AiSiteAgentSession $session,
        AiSiteScopeCompatibilityService $scopeService
    ): string {
            $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $session)
        );
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $activeDetails = \is_array($active['details'] ?? null) ? $active['details'] : [];
        $pageType = $this->firstNonEmptyString([
            $content['page_type'] ?? null,
            $details['page_type'] ?? null,
            $content['page_key'] ?? null,
            $details['page_key'] ?? null,
            $active['page_type'] ?? null,
            $activeDetails['page_type'] ?? null,
            $active['page_key'] ?? null,
            $activeDetails['page_key'] ?? null,
            $scope['preview_page_type'] ?? null,
            $scope['preview_page_key'] ?? null,
        ]);
        if ($pageType === '') {
            $pageTypes = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [];
            $normalizedPageTypes = [];
            foreach ($pageTypes as $candidate) {
                if (!\is_scalar($candidate)) {
                    continue;
                }
                $candidate = \trim((string)$candidate);
                if ($candidate !== '') {
                    $normalizedPageTypes[] = $candidate;
                }
            }
            if (\in_array('home_page', $normalizedPageTypes, true)) {
                $pageType = 'home_page';
            } elseif ($normalizedPageTypes !== []) {
                $pageType = (string)$normalizedPageTypes[0];
            }
        }
        if ($pageType === '') {
            throw new \RuntimeException('Unable to resolve queued page type.');
        }

        return $pageType;
    }

    /**
     * @return list<array{page_type: string, component_code: string, instruction: string}>
     */
    private function resolveQueuedOperationContexts(
        array $content,
        AiSiteAgentSessionService $sessionService,
        AiSiteAgentSession $session,
        AiSiteScopeCompatibilityService $scopeService
    ): array {
            $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $session)
        );
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $activeDetails = \is_array($active['details'] ?? null) ? $active['details'] : [];

        $pageType = $this->firstNonEmptyString([$content['page_type'] ?? null, $details['page_type'] ?? null, $active['page_type'] ?? null, $activeDetails['page_type'] ?? null]);
        $pageTypes = $this->mergeStringLists([
            $content['page_types'] ?? null,
            $details['page_types'] ?? null,
            $active['page_types'] ?? null,
            $activeDetails['page_types'] ?? null,
            $content['page_keys'] ?? null,
            $details['page_keys'] ?? null,
        ]);
        if ($pageTypes === [] && $pageType !== '') {
            $pageTypes = [$pageType];
        }

        $instruction = $this->firstNonEmptyString([$content['instruction'] ?? null, $details['instruction'] ?? null, $active['instruction'] ?? null, $activeDetails['instruction'] ?? null]);
        $targetContexts = $this->buildQueuedTargetOperationContexts([$details, $content], $pageType, $instruction);
        if ($targetContexts !== []) {
            return $targetContexts;
        }
        $targetContexts = $this->buildQueuedTargetOperationContexts([$activeDetails, $active], $pageType, $instruction);
        if ($targetContexts !== []) {
            return $targetContexts;
        }

        $singleComponentCode = $this->firstNonEmptyString([
            $content['component_code'] ?? null,
            $details['component_code'] ?? null,
            $active['component_code'] ?? null,
            $activeDetails['component_code'] ?? null,
            $content['section_code'] ?? null,
            $details['section_code'] ?? null,
            $active['section_code'] ?? null,
            $activeDetails['section_code'] ?? null,
        ]);
        $componentCodes = $this->mergeStringLists([
            $singleComponentCode,
            $content['component_codes'] ?? null,
            $details['component_codes'] ?? null,
            $active['component_codes'] ?? null,
            $activeDetails['component_codes'] ?? null,
            $content['section_codes'] ?? null,
            $details['section_codes'] ?? null,
        ]);
        if ($componentCodes === []) {
            $singleBlockCode = $this->firstNonEmptyString([
                $content['block_id'] ?? null,
                $details['block_id'] ?? null,
                $active['block_id'] ?? null,
                $activeDetails['block_id'] ?? null,
                $content['block_key'] ?? null,
                $details['block_key'] ?? null,
                $active['block_key'] ?? null,
                $activeDetails['block_key'] ?? null,
            ]);
            $componentCodes = $this->mergeStringLists([
                $singleBlockCode,
                $content['block_ids'] ?? null,
                $details['block_ids'] ?? null,
                $active['block_ids'] ?? null,
                $activeDetails['block_ids'] ?? null,
                $content['block_keys'] ?? null,
                $details['block_keys'] ?? null,
                $active['block_keys'] ?? null,
                $activeDetails['block_keys'] ?? null,
            ]);
        }
        if ($componentCodes === []) {
            $componentCodes = $this->mergeStringLists([
                $content['task_key'] ?? null,
                $details['task_key'] ?? null,
                $active['task_key'] ?? null,
                $activeDetails['task_key'] ?? null,
                $content['task_keys'] ?? null,
                $details['task_keys'] ?? null,
                $active['task_keys'] ?? null,
                $activeDetails['task_keys'] ?? null,
            ]);
        }

        if ($pageTypes === [] || $componentCodes === []) {
            throw new \RuntimeException('Queued block operation requires page_type and component_code.');
        }

        $contexts = [];
        foreach ($componentCodes as $index => $componentCode) {
            $contextPageType = (string)($pageTypes[$index] ?? $pageTypes[0] ?? '');
            if ($contextPageType === '' || $componentCode === '') {
                continue;
            }
            $contexts[] = [
                'page_type' => $contextPageType,
                'component_code' => $componentCode,
                'instruction' => $instruction,
            ];
        }

        if ($contexts === []) {
            throw new \RuntimeException('Queued block operation has no valid page/block contexts.');
        }

        return $this->uniqueQueuedOperationContexts($contexts);
    }

    /**
     * @param list<array<string, mixed>> $targetSources
     * @return list<array{page_type: string, component_code: string, instruction: string}>
     */
    private function buildQueuedTargetOperationContexts(array $targetSources, string $defaultPageType, string $defaultInstruction): array
    {
        $contexts = [];
        foreach ($targetSources as $source) {
            $targets = \is_array($source['targets'] ?? null) ? $source['targets'] : [];
            foreach ($targets as $target) {
                if (!\is_array($target)) {
                    continue;
                }
                $pageType = $this->firstNonEmptyString([
                    $target['page_type'] ?? null,
                    $target['page_key'] ?? null,
                    $defaultPageType,
                ]);
                $componentCode = $this->firstNonEmptyString([
                    $target['section_code'] ?? null,
                    $target['component_code'] ?? null,
                    $target['block_id'] ?? null,
                    $target['block_key'] ?? null,
                    $target['task_key'] ?? null,
                ]);
                if ($pageType === '' || $componentCode === '') {
                    continue;
                }
                $contexts[] = [
                    'page_type' => $pageType,
                    'component_code' => $componentCode,
                    'instruction' => $this->firstNonEmptyString([$target['instruction'] ?? null, $defaultInstruction]),
                ];
            }
        }

        return $this->uniqueQueuedOperationContexts($contexts);
    }

    /**
     * @param list<array{page_type: string, component_code: string, instruction: string}> $contexts
     * @return list<array{page_type: string, component_code: string, instruction: string}>
     */
    private function uniqueQueuedOperationContexts(array $contexts): array
    {
        $unique = [];
        $seen = [];
        foreach ($contexts as $context) {
            $pageType = \trim((string)($context['page_type'] ?? ''));
            $componentCode = \trim((string)($context['component_code'] ?? ''));
            if ($pageType === '' || $componentCode === '') {
                continue;
            }
            $key = $pageType . '|' . $componentCode;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = [
                'page_type' => $pageType,
                'component_code' => $componentCode,
                'instruction' => \trim((string)($context['instruction'] ?? '')),
            ];
        }

        return $unique;
    }

    /**
     * @return array{page_type: string, component_code: string, instruction: string}
     */
    private function resolveQueuedOperationContext(
        array $content,
        AiSiteAgentSessionService $sessionService,
        AiSiteAgentSession $session,
        AiSiteScopeCompatibilityService $scopeService
    ): array {
            $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $session)
        );
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        $activeDetails = \is_array($active['details'] ?? null) ? $active['details'] : [];

        $pageType = $this->firstNonEmptyString([$content['page_type'] ?? null, $details['page_type'] ?? null, $active['page_type'] ?? null, $activeDetails['page_type'] ?? null]);
        $componentCode = $this->firstNonEmptyString([
            $content['block_id'] ?? null,
            $details['block_id'] ?? null,
            $active['block_id'] ?? null,
            $activeDetails['block_id'] ?? null,
            $content['component_code'] ?? null,
            $details['component_code'] ?? null,
            $active['component_code'] ?? null,
            $activeDetails['component_code'] ?? null,
            $content['block_key'] ?? null,
            $details['block_key'] ?? null,
            $active['block_key'] ?? null,
            $activeDetails['block_key'] ?? null,
            $content['section_code'] ?? null,
            $details['section_code'] ?? null,
            $active['section_code'] ?? null,
            $activeDetails['section_code'] ?? null,
            $content['task_key'] ?? null,
            $details['task_key'] ?? null,
            $active['task_key'] ?? null,
            $activeDetails['task_key'] ?? null,
        ]);
        $instruction = $this->firstNonEmptyString([$content['instruction'] ?? null, $details['instruction'] ?? null, $active['instruction'] ?? null, $activeDetails['instruction'] ?? null]);

        if ($pageType === '' || $componentCode === '') {
            throw new \RuntimeException('Queued block operation requires page_type and component_code.');
        }

        return [
            'page_type' => $pageType,
            'component_code' => $componentCode,
            'instruction' => $instruction,
        ];
    }

    /**
     * @param list<mixed> $values
     */
    private function firstNonEmptyString(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $candidate = \trim((string)$value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private function mergeStringLists(array $values): array
    {
        $merged = [];
        foreach ($values as $value) {
            foreach ($this->stringListFromMixed($value) as $item) {
                if ($item !== '' && !\in_array($item, $merged, true)) {
                    $merged[] = $item;
                }
            }
        }

        return $merged;
    }

    /**
     * @return list<string>
     */
    private function stringListFromMixed(mixed $value): array
    {
        if (\is_array($value)) {
            return \array_values(\array_filter(\array_map(
                static fn($item): string => \is_scalar($item) ? \trim((string)$item) : '',
                $value
            ), static fn(string $item): bool => $item !== ''));
        }
        if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
            $item = \trim((string)$value);
            return $item !== '' ? [$item] : [];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadBuildQueueScope(
        AiSiteAgentSessionService $sessionService,
        AiSiteAgentSession $session
    ): array {
        return $sessionService->loadScopeForStage(
            $session,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            self::BUILD_QUEUE_SCOPE_ARTIFACT_KEYS
        );
    }

    private function applyForceBuildQueuePreset(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId
    ): AiSiteAgentSession {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        /** @var AiSitePlanJsonTaskService $planJsonTaskService */
        $planJsonTaskService = ObjectManager::getInstance(AiSitePlanJsonTaskService::class);
            $scope = $scopeService->normalizeScope(
            $sessionService->loadScopeForStage(
                $fresh,
                AiSiteAgentSession::STAGE_PLAN,
                ['plan_json']
            )
        );
        $workspaceTrack = $scopeService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $scope = $planJsonTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            $workspaceTrack !== '' ? $workspaceTrack : AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
        );
        $pageTypes = $scopeService->resolveScopedPageTypes($scope);
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId > 0 && $pageTypes !== []) {
            /** @var AiSiteVirtualThemeService $virtualThemeService */
            $virtualThemeService = ObjectManager::getInstance(AiSiteVirtualThemeService::class);
            $virtualThemeService->resetGeneratedPageLayoutsForRebuild($virtualThemeId, $pageTypes);
        }
        $scope = $planJsonTaskService->clearBuildArtifactsForRegeneration($scope);
        $scope = $planJsonTaskService->resetPlanJsonTasksToPendingForRebuild($scope, false);
        $sessionService->mergeScope((int)$fresh->getId(), $adminId, \array_replace($planJsonTaskService->extractPlanJsonDerivedScopePatch($scope), [
            'virtual_pages_by_type' => [],
            'pagebuilder_pages_by_type' => [],
            'materialized_pages_by_type' => [],
            'page_type_layouts' => [],
            'pending_generation_page_types' => [],
            'build_summary' => [],
            'build_contracts' => [],
            'render_data_contract' => [],
            'qa_report_contract' => [],
            'publish_verification' => [],
            'pre_publish_visual_urls' => [],
            'preview_full_url' => '',
            'visual_preview_url' => '',
            'visual_edit_url' => '',
            'can_publish' => 0,
            'site_ready' => 0,
            'latest_build_failed' => 0,
            'latest_build_failure' => [],
            'publish_blocked_by_latest_ai_failure' => 0,
            'publish_blocked_reason' => '',
            'retryable_ai_failures' => \is_array($scope['retryable_ai_failures'] ?? null) ? $scope['retryable_ai_failures'] : [],
            'retryable_ai_failure_count' => (int)($scope['retryable_ai_failure_count'] ?? 0),
            'next_stage_blocked_by_ai_failures' => (int)($scope['next_stage_blocked_by_ai_failures'] ?? 0),
            '_build_regeneration' => \is_array($scope['_build_regeneration'] ?? null) ? $scope['_build_regeneration'] : [],
            '_queue_force_build' => [
                'active' => 1,
                'at' => \date('Y-m-d H:i:s'),
            ],
        ]));

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    /**
     * @return array{action:string,message:string}
     */
    private function finalizeQueueBuildCompletion(
        Queue &$queue,
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSitePlanJsonTaskService $planJsonTaskService,
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $executionToken,
        int $queueId,
        int $attempt,
        int $maxAttempts
    ): array {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
            $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $fresh)
        );
        $workspaceTrack = $scopeService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $restoredScope = $planJsonTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            $workspaceTrack !== '' ? $workspaceTrack : AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
        );
        if ($restoredScope !== $scope) {
            $sessionService->replaceScope((int)$fresh->getId(), $adminId, $restoredScope);
            $fresh = $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
            $scope = $scopeService->normalizeScope(
                $this->loadBuildQueueScope($sessionService, $fresh)
            );
        }
        $finalizedScope = $planJsonTaskService->finalizePlanJsonTaskStatesAfterRunLoop($scope);
        if ($finalizedScope !== $scope) {
            $sessionService->replaceScope((int)$fresh->getId(), $adminId, $finalizedScope);
            $fresh = $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
            $scope = $scopeService->normalizeScope(
                $this->loadBuildQueueScope($sessionService, $fresh)
            );
        } else {
            $scope = $finalizedScope;
        }
        $gate = $planJsonTaskService->inspectBuildCompletionGate($scope);
        $buildSummary = \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [];
        $buildSummary['completion_gate'] = $this->stripGateSummary($gate);
        $buildSummary['page_block_progress'] = \is_array($gate['page_block_progress'] ?? null)
            ? $gate['page_block_progress']
            : [];
        $buildSummary['last_gate_checked_at'] = \date('Y-m-d H:i:s');
        $scope['build_summary'] = $buildSummary;
        $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);

        $content = $this->decodeQueueContent($queue);
        $content[self::CONTENT_ATTEMPT_KEY] = $attempt;
        $content[self::CONTENT_MAX_ATTEMPTS_KEY] = $maxAttempts;
        $content[self::CONTENT_LAST_GATE_REASON_KEY] = (string)($gate['reason'] ?? ($gate['passed'] ? 'passed' : 'completion_gate_failed'));
        $content[self::CONTENT_LAST_GATE_AT_KEY] = \date('Y-m-d H:i:s');
        if (!empty($gate['passed'])) {
            $content[self::CONTENT_LAST_GATE_DECISION_KEY] = 'passed';
            $this->saveQueueContent($queue, $content);

            return [
                'action' => 'passed',
                'message' => '',
            ];
        }

        $message = $this->formatBuildCompletionGateBlockedMessage($planJsonTaskService, $gate, $operation);
        if ($this->shouldRetryBuildQueue($gate) && $attempt < $maxAttempts) {
            $content[self::CONTENT_LAST_GATE_DECISION_KEY] = 'retryable';
            $retryQueueId = $this->createCompletionGateRetryQueue($queue, $content, $message);
            $scope = $planJsonTaskService->resetUnfinishedTasksForQueueRetry($scope, $message);
            $scope = $this->patchBuildActiveOperationForRetry(
                $scope,
                $operation,
                $retryQueueId,
                $attempt,
                $maxAttempts,
                $executionToken,
                $message,
                $gate
            );
            $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);
            $sessionService->appendEvent(
                (int)$fresh->getId(),
                $adminId,
                'pagebuilder_queue_retry_scheduled',
                [
                    'queue_id' => $retryQueueId,
                    'retry_of_queue_id' => $queueId,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'last_gate_reason' => (string)($gate['reason'] ?? 'completion_gate_failed'),
                ],
                AiSiteAgentSession::STAGE_VISUAL_EDIT
            );
            $this->markQueueRetryScheduled($queue, $retryQueueId, $content, $message);

            return [
                'action' => 'retryable',
                'message' => $message . ' Queue #' . $retryQueueId . ' marked retryable.',
            ];
        }

        $content[self::CONTENT_LAST_GATE_DECISION_KEY] = 'error';
        $this->saveQueueContent($queue, $content);
        $scope = $this->patchBuildActiveOperationForGateFailure(
            (int)$fresh->getId(),
            $scope,
            $operation,
            $queueId,
            $attempt,
            $maxAttempts,
            $executionToken,
            $message,
            $gate
        );
        $scope = $planJsonTaskService->syncPlanJsonTaskFailuresToRetryableLedger($scope);
        $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);

        throw new \RuntimeException($message);
    }

    private function markQueueBuildOperationPassedGate(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSitePlanJsonTaskService $planJsonTaskService,
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $executionToken,
        int $queueId
    ): void {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
            $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $fresh)
        );
        $scope = $planJsonTaskService->syncPageTypeLayoutsWithSharedComponents($scope);
        $gate = $planJsonTaskService->inspectBuildCompletionGate($scope);
        $fullBuildGatePassed = !empty($gate['passed']);
        $isScopedBuildOperation = \in_array($operation, ['block_regenerate', 'block_partial_patch', 'regenerate_page'], true);
        if (!$fullBuildGatePassed && !$isScopedBuildOperation) {
            return;
        }

        $now = \date('Y-m-d H:i:s');
        $message = $this->buildOperationDoneMessage($operation);
        $operationStatePatch = [
            'operation' => $operation,
            'execution_token' => $executionToken,
            'status' => 'done',
            'queue_id' => $queueId,
            'message' => $message,
            'updated_at' => $now,
            'finished_at' => $now,
            'progress_percent' => 100,
            'failure_mode' => '',
            'retry_allowed' => 0,
            'retryable_ai_failure_count' => 0,
            'last_gate_reason' => $fullBuildGatePassed ? '' : (string)($gate['reason'] ?? ''),
            'queue_waiting_for_scheduler' => false,
            'can_close_stream' => true,
            'continue_other_operations' => false,
        ];

        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeOperation = \trim((string)($active['operation'] ?? ''));
        $activeToken = \trim((string)($active['execution_token'] ?? ''));
        if (
            $active === []
            || $activeOperation === $operation
            || $this->executionTokenMatches($activeToken, $executionToken)
        ) {
            $scope['active_operation'] = \array_replace($active, $operationStatePatch);
        }

        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $operationState = \is_array($activeOperations[$operation] ?? null) ? $activeOperations[$operation] : [];
        $operationToken = \trim((string)($operationState['execution_token'] ?? ''));
        if (
            $operationState === []
            || $this->executionTokenMatches($operationToken, $executionToken)
        ) {
            $activeOperations[$operation] = \array_replace($operationState, $operationStatePatch);
            $scope['active_operations'] = $activeOperations;
        }

        if ($fullBuildGatePassed) {
            $scope = $planJsonTaskService->clearRetryableAiFailures($scope, 'build');
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            $scope['can_publish'] = 1;
            $scope['site_ready'] = 1;
            $scope['latest_build_failed'] = 0;
            $scope['latest_build_failure'] = [];
            $scope['publish_blocked_by_latest_ai_failure'] = 0;
            $scope['publish_blocked_reason'] = '';
            $scope['next_stage_blocked_by_ai_failures'] = 0;
            $buildSummary = \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [];
            $buildSummary['can_publish'] = true;
            $buildSummary['active_operation'] = $operation;
            $buildSummary['last_generated_at'] = $now;
            $buildSummary['completion_gate'] = $this->stripGateSummary($gate);
            $buildSummary['page_block_progress'] = \is_array($gate['page_block_progress'] ?? null)
                ? $gate['page_block_progress']
                : [];
            $scope['build_summary'] = $buildSummary;
        } elseif ($isScopedBuildOperation) {
            $scope = $this->clearResolvedScopedBuildOperationFailureState(
                $scope,
                $operation,
                $executionToken,
                $planJsonTaskService
            );
            $buildSummary = \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [];
            $buildSummary['active_operation'] = $operation;
            $buildSummary['last_scoped_operation_at'] = $now;
            $scope['build_summary'] = $buildSummary;
        }

        $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function clearResolvedScopedBuildOperationFailureState(
        array $scope,
        string $operation,
        string $executionToken,
        AiSitePlanJsonTaskService $planJsonTaskService
    ): array {
        if (isset($scope['retryable_ai_failures']['build']['items'][$operation])) {
            unset($scope['retryable_ai_failures']['build']['items'][$operation]);
        }
        $scope = $planJsonTaskService->clearResolvedRetryableAiFailures($scope);
        $buildLedger = $planJsonTaskService->getRetryableAiFailures($scope, 'build');
        $items = \is_array($buildLedger['build']['items'] ?? null) ? $buildLedger['build']['items'] : [];
        if ($items !== []) {
            foreach ($items as $itemKey => $item) {
                if (!\is_array($item)) {
                    unset($items[$itemKey]);
                    continue;
                }
                $itemOperation = \trim((string)($item['operation'] ?? ''));
                $retryOperation = \trim((string)($item['retry_operation'] ?? ''));
                $failureToken = \trim((string)($item['execution_token'] ?? ''));
                if (
                    $itemKey === $operation
                    || $itemOperation === $operation
                    || $retryOperation === $operation
                    || ($failureToken !== '' && $this->executionTokenMatches($failureToken, $executionToken))
                ) {
                    unset($items[$itemKey]);
                }
            }
        }
        $scope = $planJsonTaskService->replaceRetryableAiFailures($scope, 'build', $items);

        $latestFailure = \is_array($scope['latest_build_failure'] ?? null) ? $scope['latest_build_failure'] : [];
        $latestOperation = \trim((string)($latestFailure['operation'] ?? ''));
        if ($latestOperation === $operation && $items === []) {
            $scope['latest_build_failed'] = 0;
            $scope['latest_build_failure'] = [];
            $scope['publish_blocked_by_latest_ai_failure'] = 0;
            $scope['publish_blocked_reason'] = '';
            $scope['next_stage_blocked_by_ai_failures'] = 0;
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $gate
     */
    private function shouldRetryBuildQueue(array $gate): bool
    {
        $reason = \trim((string)($gate['reason'] ?? ''));
        if (!empty($gate['passed']) || $reason === 'cancelled_plan_json_block_nodes') {
            return false;
        }

        if ($reason === '') {
            return (int)($gate['total'] ?? 0) > 0;
        }

        return \in_array($reason, [
            'missing_plan_json_block_nodes',
            'failed_plan_json_block_nodes',
            'invalid_generated_artifacts',
            'duplicate_generated_artifacts',
            'unfinished_plan_json_block_nodes',
            'missing_plan_json_page_types',
            'missing_page_type_layouts',
            'empty_page_type_layouts',
            'missing_persisted_virtual_theme_layouts',
            'plan_json_missing_stage1_block_nodes',
            'default_template_page_layouts',
            'incomplete_page_block_counts',
        ], true);
    }

    private function assertBuildQueueMayFinish(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSitePlanJsonTaskService $planJsonTaskService,
        AiSiteAgentSession $session,
        int $adminId,
        string $operation
    ): void {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $fresh)
        );
        $scope = $planJsonTaskService->finalizePlanJsonTaskStatesAfterRunLoop($scope);
        $gate = $planJsonTaskService->inspectBuildCompletionGate($scope);
        if (!empty($gate['passed'])) {
            return;
        }

        throw new \RuntimeException($this->formatBuildCompletionGateBlockedMessage($planJsonTaskService, $gate, $operation));
    }

    /**
     * @param array<string, mixed> $gate
     */
    private function formatBuildCompletionGateBlockedMessage(
        AiSitePlanJsonTaskService $planJsonTaskService,
        array $gate,
        string $operation
    ): string {
        $detail = $planJsonTaskService->formatBuildCompletionGateFailureDetail($gate);
        $message = \sprintf(
            'Build queue operation %s cannot finish while completion gate is blocked: total=%d pending=%d running=%d failed=%d cancelled=%d invalid_artifacts=%d duplicate_artifacts=%d reason=%s.',
            $operation,
            (int)($gate['total'] ?? 0),
            (int)($gate['pending'] ?? 0),
            (int)($gate['running'] ?? 0),
            (int)($gate['failed'] ?? 0),
            (int)($gate['cancelled'] ?? 0),
            (int)($gate['invalid_artifacts'] ?? 0),
            (int)($gate['duplicate_artifacts'] ?? 0),
            (string)($gate['reason'] ?? '')
        );
        if ($detail !== '') {
            $message .= ' ' . $detail;
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $gate
     * @return array<string, mixed>
     */
    private function patchBuildActiveOperationForRetry(
        array $scope,
        string $operation,
        int $queueId,
        int $attempt,
        int $maxAttempts,
        string $executionToken,
        string $message,
        array $gate
    ): array {
        $now = \date('Y-m-d H:i:s');
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $startedAt = \trim((string)($activeOperation['started_at'] ?? ''));
        if ($startedAt === '') {
            $startedAt = $now;
        }
        $operationState = \array_replace($activeOperation, [
            'operation' => $operation,
            'status' => 'queued',
            'queue_id' => $queueId,
            'execution_token' => $executionToken,
            'message' => $message,
            'queue_waiting_for_scheduler' => true,
            'started_at' => $startedAt,
            'updated_at' => $now,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'last_gate_reason' => (string)($gate['reason'] ?? 'completion_gate_failed'),
            'can_close_stream' => true,
            'continue_other_operations' => true,
        ]);

        $scope['active_operation'] = $operationState;
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations[$operation] = $operationState;
        $scope['active_operations'] = $activeOperations;

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function patchBuildActiveOperationForAttemptLimit(
        array $scope,
        string $operation,
        int $queueId,
        int $attempt,
        int $maxAttempts,
        string $executionToken,
        string $message
    ): array {
        $now = \date('Y-m-d H:i:s');
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $operationState = \array_replace($activeOperation, [
            'operation' => $operation,
            'status' => 'stop',
            'queue_id' => $queueId,
            'execution_token' => $executionToken,
            'message' => $message,
            'updated_at' => $now,
            'finished_at' => $now,
            'queue_waiting_for_scheduler' => false,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'retry_allowed' => 1,
            'failure_mode' => 'build_retry_exhausted',
            'can_close_stream' => true,
            'continue_other_operations' => true,
        ]);
        $scope['active_operation'] = $operationState;
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations[$operation] = $operationState;
        $scope['active_operations'] = $activeOperations;
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
        $failurePayload = $this->buildPublishBlockingAiFailurePayload($operation, $message);
        $failurePayload['gate_reason'] = 'automatic_attempt_limit';
        $scope['latest_build_failed'] = 1;
        $scope['latest_build_failure'] = $failurePayload;
        $scope['publish_blocked_by_latest_ai_failure'] = 1;
        $scope['publish_blocked_reason'] = $this->formatPublishBlockedByAiFailureMessage($failurePayload);

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $gate
     * @return array<string, mixed>
     */
    private function patchBuildActiveOperationForGateFailure(
        int $sessionId,
        array $scope,
        string $operation,
        int $queueId,
        int $attempt,
        int $maxAttempts,
        string $executionToken,
        string $message,
        array $gate
    ): array {
        $now = \date('Y-m-d H:i:s');
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $operationState = \array_replace($activeOperation, [
            'operation' => $operation,
            'status' => 'error',
            'queue_id' => $queueId,
            'execution_token' => $executionToken,
            'message' => $message,
            'updated_at' => $now,
            'finished_at' => $now,
            'queue_waiting_for_scheduler' => false,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'last_gate_reason' => (string)($gate['reason'] ?? 'completion_gate_failed'),
            'can_close_stream' => false,
            'continue_other_operations' => false,
        ]);
        $scope['active_operation'] = $operationState;
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations[$operation] = $operationState;
        $scope['active_operations'] = $activeOperations;
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
        $gateReason = \trim((string)($gate['reason'] ?? ''));
        $failurePayload = $this->buildPublishBlockingAiFailurePayload($operation, $message);
        $failurePayload['gate_reason'] = $gateReason;
        $scope['latest_build_failed'] = 1;
        $scope['latest_build_failure'] = $failurePayload;
        $scope['publish_blocked_by_latest_ai_failure'] = 1;
        $scope['publish_blocked_reason'] = $this->formatPublishBlockedByAiFailureMessage($failurePayload);
        if (\in_array($gateReason, [
            'missing_plan_json_page_types',
            'missing_plan_json_block_nodes',
            'missing_page_type_layouts',
            'empty_page_type_layouts',
        ], true)) {
            $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
            $scope = \array_replace(
                $scope,
                (new AiSitePlanJsonStateService($sessionId))->setConfirmedScopePatch($planJson, false)
            );
        }
        $scope['_queue_force_build'] = [
            'active' => 0,
            'consumed_at' => $now,
            'failure_queue_id' => $queueId,
        ];
        $scope['_build_regeneration'] = [
            'active' => 0,
            'finished_at' => $now,
        ];

        return $scope;
    }

    private function clearQueueForceBuildMarker(AiSiteAgentSessionService $sessionService, int $sessionId, int $adminId): void
    {
        try {
            $sessionService->patchScopeManifest($sessionId, $adminId, [
                '_queue_force_build' => [
                    'active' => 0,
                    'consumed_at' => \date('Y-m-d H:i:s'),
                ],
                '_build_regeneration' => [
                    'active' => 0,
                    'finished_at' => \date('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Throwable) {
        }
    }

    private function updateSessionError(string $publicId, int $adminId, string $executionToken, string $message): void
    {
        try {
            /** @var AiSiteAgentSessionService $sessionService */
            $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
            /** @var AiSiteScopeCompatibilityService $scopeService */
            $scopeService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
            /** @var AiSitePlanJsonTaskService $planJsonTaskService */
            $planJsonTaskService = ObjectManager::getInstance(AiSitePlanJsonTaskService::class);

            $session = $sessionService->loadByPublicId($publicId, $adminId);
            if (!$session instanceof AiSiteAgentSession) {
                return;
            }

            $scope = $scopeService->normalizeScope(
                $this->loadBuildQueueScope($sessionService, $session)
            );
            $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            if ((string)($active['execution_token'] ?? '') !== $executionToken) {
                return;
            }

            $active['status'] = 'error';
            $active['message'] = $message;
            $active['updated_at'] = \date('Y-m-d H:i:s');
            $active['queue_waiting_for_scheduler'] = false;
            $active['can_close_stream'] = false;
            $active['continue_other_operations'] = false;
            $scope['active_operation'] = $active;
            $operation = \trim((string)($active['operation'] ?? ''));
            $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
            if ($operation !== '' && \is_array($activeOperations[$operation] ?? null)) {
                $operationState = $activeOperations[$operation];
                if ((string)($operationState['execution_token'] ?? '') === $executionToken) {
                    $operationState['status'] = 'error';
                    $operationState['message'] = $message;
                    $operationState['updated_at'] = $active['updated_at'];
                    $operationState['queue_waiting_for_scheduler'] = false;
                    $operationState['can_close_stream'] = false;
                    $operationState['continue_other_operations'] = false;
                    $activeOperations[$operation] = $operationState;
                    $scope['active_operations'] = $activeOperations;
                }
            }
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
            if (\in_array($operation, ['build', 'block_regenerate', 'block_partial_patch', 'regenerate_page'], true)) {
                $failurePayload = $this->buildPublishBlockingAiFailurePayload($operation, $message);
                $scope['latest_build_failed'] = 1;
                $scope['latest_build_failure'] = $failurePayload;
                $scope['publish_blocked_by_latest_ai_failure'] = 1;
                $scope['publish_blocked_reason'] = $this->formatPublishBlockedByAiFailureMessage($failurePayload);
            }
            if ($operation === 'build') {
                $scope = $planJsonTaskService->resetRunningTasksForInterruptedBuild(
                    $scope,
                    'Build interrupted before task completion: ' . $message
                );
            }
            $sessionService->replaceScope((int)$session->getId(), $adminId, $scope);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{blocked:bool,operation:string,status:string,message:string}
     */
    private function buildPublishBlockingAiFailurePayload(string $operation, string $message): array
    {
        $message = \trim($message);
        if ($message === '') {
            $message = 'Latest AI site build failed; publish is blocked until a successful AI rebuild completes.';
        }

        return [
            'blocked' => true,
            'operation' => $operation !== '' ? $operation : 'build',
            'status' => 'error',
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed> $failure
     */
    private function formatPublishBlockedByAiFailureMessage(array $failure): string
    {
        $message = \trim((string)($failure['message'] ?? ''));
        if ($message === '') {
            return 'Latest AI site build failed; publish is blocked until a successful AI rebuild completes.';
        }

        return 'Latest AI site build failed; publish is blocked until a successful AI rebuild completes. Error: ' . $message;
    }

    private function ensureQueuedActiveOperation(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        int $adminId,
        int $queueId,
        string $operation,
        string $executionToken
    ): AiSiteAgentSession {
        $fresh = $sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
            $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $fresh)
        );
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeStatus = \trim((string)($active['status'] ?? ''));
        $activeQueueId = (int)($active['queue_id'] ?? 0);
        if (
            (string)($active['operation'] ?? '') === $operation
            && $this->executionTokenMatches((string)($active['execution_token'] ?? ''), $executionToken)
            && \in_array($activeStatus, ['queued', 'running'], true)
            && $activeQueueId > 0
            && $activeQueueId !== $queueId
            && $activeQueueId > $queueId
        ) {
            throw new \RuntimeException('A newer build queue already owns this operation.');
        }
        if (
            (string)($active['operation'] ?? '') === $operation
            && (string)($active['execution_token'] ?? '') === $executionToken
            && \in_array($activeStatus, ['queued', 'running'], true)
            && ($activeQueueId === $queueId || $queueId <= 0)
        ) {
            return $fresh;
        }

        $scope['active_operation'] = \array_replace($active, [
            'operation' => $operation,
            'execution_token' => $executionToken,
            'status' => 'queued',
            'queue_id' => $queueId,
            'started_at' => (string)($active['started_at'] ?? \date('Y-m-d H:i:s')),
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations[$operation] = $scope['active_operation'];
        $scope['active_operations'] = $activeOperations;
        $sessionService->replaceScope((int)$fresh->getId(), $adminId, $scope);

        return $sessionService->loadById((int)$fresh->getId(), $adminId) ?? $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    private function findSupersedingQueueRow(int $queueId, string $bizKey, string $operation, string $executionToken): array
    {
        $bizKey = \trim($bizKey);
        if ($queueId <= 0 || $bizKey === '') {
            return [];
        }

        try {
            $result = w_query('queue', 'list', [
                'biz_key' => $bizKey,
                'page_size' => 20,
            ]);
        } catch (\Throwable) {
            return [];
        }

        foreach (\is_array($result['items'] ?? null) ? $result['items'] : [] as $item) {
            $row = \is_object($item) && \method_exists($item, 'getData') ? $item->getData() : $item;
            if (!\is_array($row)) {
                continue;
            }
            $candidateQueueId = (int)($row['queue_id'] ?? 0);
            if ($candidateQueueId <= $queueId) {
                continue;
            }
            $status = \trim((string)($row['status'] ?? ''));
            if (!\in_array($status, ['pending', 'queued', 'running', 'done', 'error', 'stop'], true)) {
                continue;
            }
            if ($this->isStrictSupersedingQueueSlot($bizKey)) {
                return $row;
            }
            if ($this->queueRowMatchesOperationToken($row, $operation, $executionToken)) {
                return $row;
            }
        }

        return [];
    }

    private function resolveActiveQueueIdForQueuedOperation(
        AiSiteAgentSessionService $sessionService,
        AiSiteScopeCompatibilityService $scopeService,
        AiSiteAgentSession $session,
        string $operation,
        string $executionToken
    ): int {
            $scope = $scopeService->normalizeScope(
            $this->loadBuildQueueScope($sessionService, $session)
        );
        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if (
            \trim((string)($active['operation'] ?? '')) === $operation
            && $this->executionTokenMatches((string)($active['execution_token'] ?? ''), $executionToken)
        ) {
            return (int)($active['queue_id'] ?? 0);
        }

        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $operationState = \is_array($activeOperations[$operation] ?? null) ? $activeOperations[$operation] : [];
        if ($this->executionTokenMatches((string)($operationState['execution_token'] ?? ''), $executionToken)) {
            return (int)($operationState['queue_id'] ?? 0);
        }

        return 0;
    }

    private function isStrictSupersedingQueueSlot(string $bizKey): bool
    {
        $bizKey = \trim($bizKey);
        if ($bizKey === '') {
            return false;
        }

        return \str_contains($bizKey, ':queue_slot:')
            || \str_contains($bizKey, ':operation:');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function queueRowMatchesOperationToken(array $row, string $operation, string $executionToken): bool
    {
        $content = $row['content'] ?? null;
        if (\is_string($content)) {
            $decoded = \json_decode($content, true);
            $content = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($content)) {
            return false;
        }

        $rowOperation = \trim((string)($content['operation'] ?? ''));
        $rowToken = \trim((string)($content['execution_token'] ?? $content['token'] ?? ''));

        return $rowOperation === $operation
            && $this->executionTokenMatches($rowToken, $executionToken);
    }

    private function executionTokenMatches(string $actualToken, string $requestedToken): bool
    {
        $actualToken = \trim($actualToken);
        $requestedToken = \trim($requestedToken);
        if ($actualToken === '' || $requestedToken === '') {
            return false;
        }
        if ($actualToken === $requestedToken) {
            return true;
        }

        $actualBase = \explode('-force-', $actualToken, 2)[0] ?? $actualToken;
        $requestedBase = \explode('-force-', $requestedToken, 2)[0] ?? $requestedToken;

        return $actualBase !== '' && $actualBase === $requestedBase;
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflectionMethod = new \ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $arguments);
    }

    private function appendQueueLifecycleLine(Queue &$queue, string $message): void
    {
        $qid = (int)$queue->getId();
        if ($qid <= 0 || $message === '') {
            return;
        }

        $row = w_query('queue', 'get', ['queue_id' => $qid]);
        if (!\is_array($row) || $row === []) {
            return;
        }

        $line = '[' . \date('H:i:s') . '] QUEUE ' . $message;
        w_query('queue', 'update', [
            'queue_id' => $qid,
            'patch' => [
                'process' => $message,
                'result' => $line,
            ],
        ]);
        $this->mirrorToCli($line);
    }

    private function markQueueDone(Queue &$queue, string $message): void
    {
        $qid = (int)$queue->getId();
        if ($qid <= 0) {
            return;
        }

        $row = w_query('queue', 'get', ['queue_id' => $qid]);
        if (!\is_array($row) || $row === []) {
            return;
        }

        $line = '[' . \date('H:i:s') . '] QUEUE_DONE ' . $message;
        w_query('queue', 'update', [
            'queue_id' => $qid,
            'patch' => [
                'status' => Queue::status_done,
                'pid' => 0,
                'finished' => 1,
                'process' => $message,
                'result' => $line,
            ],
        ]);
        $this->mirrorToCli($line);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function markQueueStopped(Queue &$queue, array $content, string $message): void
    {
        $qid = (int)$queue->getId();
        if ($qid <= 0) {
            return;
        }
        $content[self::CONTENT_LAST_GATE_DECISION_KEY] = 'manual_confirmation_required';
        $content[self::CONTENT_LAST_GATE_REASON_KEY] = 'automatic_attempt_limit';
        $content[self::CONTENT_LAST_GATE_AT_KEY] = \date('Y-m-d H:i:s');
        $line = '[' . \date('H:i:s') . '] QUEUE_STOP ' . $message;
        $queue->setStatus(Queue::status_stop)
            ->setContent($this->encodeQueueContent($content))
            ->setFinished(true)
            ->setPid(0)
            ->setProcess($message)
            ->setResult($line)
            ->save();
        $this->mirrorToCli($line);
    }

    private function queueTrace(AiSiteQueueLogWriter $sse, string $message): void
    {
        if ($message === '') {
            return;
        }

        $sse->sendEvent('log', [
            'message' => $message,
            'event_type' => 'queue_lifecycle',
            'level' => 'info',
        ]);
        $this->mirrorToCli('[' . \date('H:i:s') . '] LOG ' . $message);
    }

    private function formatThrowableDiagnostic(\Throwable $throwable): string
    {
        $frames = [];
        foreach (\array_slice($throwable->getTrace(), 0, 8) as $frame) {
            $file = \trim((string)($frame['file'] ?? ''));
            $line = (int)($frame['line'] ?? 0);
            $function = \trim((string)($frame['function'] ?? ''));
            $class = \trim((string)($frame['class'] ?? ''));
            $location = $file !== '' ? $file . ($line > 0 ? ':' . $line : '') : '[internal]';
            $call = $class !== '' ? $class . '::' . $function : $function;
            $frames[] = $location . ($call !== '' ? ' ' . $call : '');
        }

        return \sprintf(
            '%s in %s:%d%s',
            $throwable->getMessage(),
            $throwable->getFile(),
            (int)$throwable->getLine(),
            $frames !== [] ? ' | trace: ' . \implode(' <- ', $frames) : ''
        );
    }

    private function logInternalBuildQueueDiagnostic(
        int $queueId,
        string $operation,
        string $publicId,
        string $diagnostic,
        string $surfaceMessage
    ): void {
        try {
            \w_log_warning('[AI Site Build Queue Diagnostic] ' . \json_encode([
                'queue_id' => $queueId,
                'operation' => $operation,
                'public_id' => $publicId,
                'surface_message' => $surfaceMessage,
                'diagnostic' => $this->clipInternalDiagnostic($diagnostic, 4096),
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (\Throwable) {
        }
    }

    private function clipInternalDiagnostic(string $value, int $limit): string
    {
        $value = \trim((string)(\preg_replace('/\s+/u', ' ', $value) ?? $value));
        if ($limit <= 0 || \mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return \mb_substr($value, 0, $limit - 1, 'UTF-8') . '...';
    }

    private function summarizeThrowableForQueueSurface(\Throwable $throwable): string
    {
        $message = \trim($throwable->getMessage());
        if ($message === '') {
            $message = $throwable::class;
        }

        $lower = \mb_strtolower($message, 'UTF-8');
        if (\str_contains($lower, 'required_image_asset_unresolved')
            || \str_contains($lower, 'inline block image generation failed')
            || \str_contains($lower, 'image generation failed')
            || \str_contains($lower, 'vectorengine')
            || \str_contains($lower, 'generatecontent')
            || \str_contains($lower, 'chat pre-consumed quota')
            || \str_contains($lower, 'user quota')
            || \str_contains($lower, 'need quota')
            || \str_contains($lower, 'quota')
        ) {
            return 'Image generation is temporarily unavailable. The section will need another generation attempt.';
        }

        if (\str_contains($lower, 'openssl')
            || \str_contains($lower, 'ssl_read')
            || \str_contains($lower, 'curl')
            || \str_contains($lower, 'operation timed out')
            || \str_contains($lower, 'operation too slow')
            || \str_contains($lower, 'timed out after')
        ) {
            return 'AI generation timed out. The section will need another generation attempt.';
        }

        if (\str_contains($lower, 'contract findings')
            || \str_contains($lower, 'hard policy')
            || \str_contains($lower, 'quality gate failed')
            || \str_contains($lower, 'quality gate did not')
            || \str_contains($lower, 'component contract')
            || \str_contains($lower, 'build prompt contract')
            || \str_contains($lower, 'stage-2 plan-json task context')
            || \str_contains($lower, 'scope-level prompt fallback')
        ) {
            return 'AI output did not pass the section quality gate. The section will need another generation attempt.';
        }

        if ((\preg_match('/https?:\\/\\//i', $message) === 1)
            || (\preg_match('/\\brequest\\s*id\\b/i', $message) === 1)
            || (\preg_match('/\\bHTTP\\s*:?\\s*\\d{3}\\b/i', $message) === 1)
            || (\preg_match('/\\b[A-Za-z_]+Exception\\b/', $message) === 1)
            || \str_contains($message, '\\')
            || \str_contains($message, '::')
            || \str_contains($message, ' in ')
            || \str_contains($message, 'trace:')
        ) {
            return 'AI generation failed. The section will need another generation attempt.';
        }

        return \mb_substr((string)(\preg_replace('/\s+/u', ' ', $message) ?? $message), 0, 320, 'UTF-8');
    }

    private function mirrorToCli(string $line): void
    {
        if ($line === '' || \PHP_SAPI !== 'cli') {
            return;
        }

        echo $line . \PHP_EOL;
        if (\function_exists('flush')) {
            \flush();
        }
    }

    /**
     * @param array<string, mixed> $content
     * @return array{0:array<string,mixed>,1:int,2:int}
     */
    private function beginQueueAttempt(Queue &$queue, array $content, string $effectiveExecutionToken): array
    {
        $attempt = \max(0, (int)($content[self::CONTENT_ATTEMPT_KEY] ?? 0)) + 1;
        $maxAttempts = self::DEFAULT_MAX_ATTEMPTS;
        $content[self::CONTENT_ATTEMPT_KEY] = $attempt;
        $content[self::CONTENT_MAX_ATTEMPTS_KEY] = $maxAttempts;
        $content[self::CONTENT_LAST_GATE_REASON_KEY] = '';
        $content[self::CONTENT_LAST_GATE_AT_KEY] = '';
        $content[self::CONTENT_LAST_GATE_DECISION_KEY] = 'running';
        if ($effectiveExecutionToken !== '') {
            $content['execution_token'] = $effectiveExecutionToken;
        }
        $content = $this->clearQueueContentProgressFields($content);
        $content = $this->compactQueueContentForStorage($content);
        $this->saveQueueContent($queue, $content, false);

        return [$content, $attempt, $maxAttempts];
    }

    /**
     * @param array<string, mixed> $content
     */
    private function saveQueueContent(Queue &$queue, array $content, bool $preserveExistingProgress = true): void
    {
        if ($preserveExistingProgress) {
            $content = $this->mergeExistingQueueContentProgress($queue, $content);
        }
        $content = $this->compactQueueContentForStorage($content);
        $queue->setContent($this->encodeQueueContent($content))->save();
    }

    /**
     * QueueDbWriter persists compact progress while the worker is running. The
     * queue model may still hold the start-of-run content, so merge those small
     * fields back before lifecycle saves overwrite the row.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function mergeExistingQueueContentProgress(Queue &$queue, array $content): array
    {
        $queueId = (int)$queue->getId();
        if ($queueId <= 0) {
            return $content;
        }

        try {
            $row = w_query('queue', 'get', ['queue_id' => $queueId]);
        } catch (\Throwable) {
            return $content;
        }
        if (!\is_array($row) || $row === []) {
            return $content;
        }

        $rawContent = $row['content'] ?? null;
        $existing = [];
        if (\is_array($rawContent)) {
            $existing = $rawContent;
        } elseif (\is_string($rawContent)) {
            $rawContent = \trim($rawContent);
            if ($rawContent !== '' && \strlen($rawContent) <= self::QUEUE_CONTENT_PROGRESS_MERGE_MAX_BYTES) {
                $decoded = \json_decode($rawContent, true);
                $existing = \is_array($decoded) ? $decoded : [];
            }
        }
        if ($existing === []) {
            return $content;
        }

        foreach (self::QUEUE_CONTENT_PROGRESS_KEYS as $key => $_) {
            if (!\array_key_exists($key, $content) && \array_key_exists($key, $existing)) {
                $content[$key] = $existing[$key];
            }
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function clearQueueContentProgressFields(array $content): array
    {
        foreach (self::QUEUE_CONTENT_PROGRESS_KEYS as $key => $_) {
            unset($content[$key]);
        }

        return $content;
    }

    /**
     * Keep retry metadata on the same stage queue row. The HTTP entry layer
     * resets reused queue rows to pending, so retries and rebuilds do not create
     * unbounded duplicate rows for the same session stage slot.
     *
     * @param array<string, mixed> $content
     */
    private function createCompletionGateRetryQueue(Queue &$queue, array $content, string $message): int
    {
        $queueId = (int)$queue->getId();
        $content['retry_of_queue_id'] = $queueId;
        $content['retry_reason'] = $message;
        $content['retry_scheduled_at'] = \date('Y-m-d H:i:s');
        $content['execution_token'] = \trim((string)($content['execution_token'] ?? ''));
        $this->saveQueueContent($queue, $content);
        if ($queueId <= 0) {
            throw new \RuntimeException('Unable to mark PageBuilder build queue retry after completion gate block.');
        }

        return $queueId;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function markQueueRetryScheduled(Queue &$queue, int $retryQueueId, array $content, string $message): void
    {
        $content = $this->compactQueueContentForStorage($content);
        $content['retry_queue_id'] = $retryQueueId;
        $content = $this->mergeExistingQueueContentProgress($queue, $content);
        $line = '[' . \date('H:i:s') . '] QUEUE_RETRY same_queue=' . $retryQueueId . ' ' . $message;
        $queue->setStatus(Queue::status_pending)
            ->setContent($this->encodeQueueContent($content))
            ->setFinished(false)
            ->setPid(0)
            ->setData(Queue::schema_fields_start_at, null)
            ->setData(Queue::schema_fields_end_at, null)
            ->setProcess($message)
            ->setResult($line)
            ->save();
        $this->mirrorToCli($line);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function compactQueueContentForStorage(array $content): array
    {
        if (!\is_array($content['scope_patch'] ?? null)) {
            return $content;
        }

        $scopePatch = $content['scope_patch'];
        foreach (self::QUEUE_SCOPE_PATCH_REDUNDANT_KEYS as $key => $_) {
            unset($scopePatch[$key]);
        }
        $content['scope_patch'] = $scopePatch;

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeQueueContent(Queue &$queue): array
    {
        $decoded = \json_decode((string)$queue->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $gate
     * @return array<string, mixed>
     */
    private function stripGateSummary(array $gate): array
    {
        return \array_diff_key($gate, ['summary' => true]);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function encodeQueueContent(array $content): string
    {
        $json = \json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return \is_string($json) ? $json : '{}';
    }

}
