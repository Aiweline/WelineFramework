<?php

declare(strict_types=1);

namespace Weline\Social\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;
use Weline\Social\Model\SocialExtensionDevice;
use Weline\Social\Model\SocialExtensionJob;
use Weline\Social\Model\SocialExtensionStep;
use Weline\Social\Model\SocialPlatformAccount;
use Weline\Social\Service\SocialExtensionJobService;
use Weline\Social\Service\SocialRecipeRepository;
use Weline\Social\Service\SocialStepDecider;

/**
 * 浏览器扩展 REST 接口（一次性授权码 / 设备令牌，不依赖后台会话）。
 *
 * 路由：/weline_social/frontend/extension/{action}
 *
 * @Extra type=fpc enabled=false
 */
class Extension extends FrontendController
{
    private const ASSET_MAX_BYTES = 5242880;

    protected function csrf(): string
    {
        return '';
    }

    public function authorize(): string
    {
        try {
            if (\strtoupper((string)$this->request->getMethod()) !== 'POST') {
                return $this->fail('method_not_allowed', '授权接口仅支持 POST 请求。', 405);
            }

            $body = $this->body();
            $code = \trim((string)($body['code'] ?? ''));
            if ($code === '') {
                return $this->fail('missing_params', '缺少授权码。', 400);
            }

            $secret = \trim((string)Env::get('wls.social_extension_secret', ''));
            if ($secret === '') {
                return $this->fail('grant_not_configured', '扩展授权密钥未配置。', 503);
            }

            $websiteId = \max(0, (int)($body['website_id'] ?? 0));
            $grant = $this->validateGrantCode($code, $websiteId, $secret);
            if (($grant['ok'] ?? false) !== true) {
                $error = (string)($grant['error'] ?? 'grant_invalid');

                return $this->fail($error, $this->messageForError($error), $this->statusForError($error));
            }

            $cacheKey = 'grant.' . \hash('sha256', $code);
            $cache = \w_cache('social_extension_grant');
            $used = $cache->get($cacheKey);
            if ($used !== null && $used !== false) {
                return $this->fail('grant_already_used', '授权码已使用。', 403);
            }

            $token = \bin2hex(\random_bytes(16));
            $userId = 0;
            try {
                $backendSession = SessionFactory::getInstance()->createBackendSession();
                if ($backendSession->isLoggedIn()) {
                    $userId = \max(0, (int)$backendSession->getUserId());
                }
            } catch (\Throwable) {
            }

            $name = \trim((string)($body['name'] ?? ''));
            if ($name === '') {
                $name = (string)__('浏览器扩展');
            }
            $name = \function_exists('mb_substr') ? \mb_substr($name, 0, 100) : \substr($name, 0, 100);
            $version = \trim((string)($body['version'] ?? ''));
            $version = \function_exists('mb_substr') ? \mb_substr($version, 0, 32) : \substr($version, 0, 32);
            $now = \date('Y-m-d H:i:s');

            $device = ObjectManager::getInstance(SocialExtensionDevice::class);
            $device->clearData()->clearQuery();
            $device->setData([
                SocialExtensionDevice::schema_fields_USER_ID => $userId,
                SocialExtensionDevice::schema_fields_WEBSITE_ID => $websiteId,
                SocialExtensionDevice::schema_fields_NAME => $name,
                SocialExtensionDevice::schema_fields_FP_HASH => \hash('sha256', \random_bytes(32)),
                SocialExtensionDevice::schema_fields_TOKEN_HASH => \hash('sha256', $token),
                SocialExtensionDevice::schema_fields_VERSION => $version,
                SocialExtensionDevice::schema_fields_STATUS => SocialExtensionDevice::STATUS_ACTIVE,
                SocialExtensionDevice::schema_fields_CREATED_AT => $now,
            ])->save();

            if (!$device->getId()) {
                return $this->fail('internal_error', '请求处理失败，请稍后重试。', 500);
            }

            $ttl = \max(1, (int)$grant['expires_at'] - \time());
            if (!$cache->set($cacheKey, 1, $ttl)) {
                try {
                    $device->delete();
                } catch (\Throwable) {
                }

                return $this->fail('grant_cache_unavailable', '授权码状态写入失败，请重试。', 503);
            }

            return $this->json([
                'ok' => true,
                'token' => $token,
                'device_id' => (int)$device->getId(),
                'website_id' => $websiteId,
            ]);
        } catch (\Throwable) {
            return $this->fail('internal_error', '请求处理失败，请稍后重试。', 500);
        }
    }

    public function claim(): string
    {
        try {
            $device = $this->authDevice();
            if ($device === null) {
                return $this->fail('forbidden', '扩展令牌无效或设备已撤销。', 403);
            }

            $body = $this->body();
            $websiteId = 0;
            try {
                $backendSession = SessionFactory::getInstance()->createBackendSession();
                if ($backendSession->isLoggedIn()) {
                    $websiteId = \max(0, (int)($body['website_id'] ?? 0));
                }
            } catch (\Throwable) {
            }

            $job = $this->jobService()->claimNext((string)$device['device_id'], $websiteId);
            if ($job === null) {
                return $this->json(['ok' => true, 'job' => null, 'recipe' => null, 'entry' => '']);
            }

            $recipe = $this->loadRecipe(
                (string)($job[SocialExtensionJob::schema_fields_PLATFORM_CODE] ?? ''),
                (string)($job[SocialExtensionJob::schema_fields_CONTENT_KIND] ?? '')
            );
            $accountName = '';
            $recipeEntry = \is_string($recipe['entry'] ?? null) ? $recipe['entry'] : '';
            if (\preg_match('/\{[A-Za-z0-9_]+\}/', $recipeEntry) === 1) {
                $accountName = $this->accountName((int)($job[SocialExtensionJob::schema_fields_ACCOUNT_ID] ?? 0));
            }
            [$recipe, $entry] = $this->resolveRecipeEntry($recipe, $accountName);

            return $this->json([
                'ok' => true,
                'job' => $job,
                'recipe' => $recipe,
                'entry' => $entry,
            ]);
        } catch (\Throwable) {
            return $this->fail('internal_error', '请求处理失败，请稍后重试。', 500);
        }
    }

    public function heartbeat(): string
    {
        try {
            $device = $this->authDevice();
            if ($device === null) {
                return $this->fail('forbidden', '扩展令牌无效或设备已撤销。', 403);
            }

            $body = $this->body();
            $jobId = (string)($body['job_id'] ?? '');
            $claimToken = $this->claimToken($body);
            if ($jobId === '' || $claimToken === '') {
                return $this->fail('missing_params', '缺少必要参数。', 400);
            }

            $version = (string)($body['version'] ?? $device['version'] ?? '');
            $result = $this->jobService()->heartbeat($jobId, $claimToken, $version);
            if (($result['ok'] ?? false) !== true) {
                $error = (string)($result['error'] ?? 'heartbeat_failed');

                return $this->fail($error, $this->messageForError($error), $this->statusForError($error));
            }

            return $this->json($result);
        } catch (\Throwable) {
            return $this->fail('internal_error', '请求处理失败，请稍后重试。', 500);
        }
    }

    public function step(): string
    {
        try {
            $device = $this->authDevice();
            if ($device === null) {
                return $this->fail('forbidden', '扩展令牌无效或设备已撤销。', 403);
            }

            $body = $this->body();
            $jobId = (int)($body['job_id'] ?? 0);
            $claimToken = $this->claimToken($body);
            if ($jobId < 1 || $claimToken === '') {
                return $this->fail('missing_params', '缺少必要参数。', 400);
            }

            $job = $this->loadJob($jobId);
            if ($job === null) {
                return $this->fail('job_not_found', '扩展任务不存在。', 404);
            }
            if (!$this->claimTokenMatches($job, $claimToken)) {
                return $this->fail('invalid_claim_token', '任务领取凭证无效。', 403);
            }
            $claimedBy = (string)($job->getData(SocialExtensionJob::schema_fields_CLAIMED_BY) ?? '');
            if ($claimedBy !== '' && !\hash_equals($claimedBy, (string)$device['device_id'])) {
                return $this->fail('forbidden', '任务已被其他设备领取。', 403);
            }

            $status = (string)($job->getData(SocialExtensionJob::schema_fields_STATUS) ?? '');
            if ($this->isTerminalStatus($status)) {
                return $this->fail('job_finished', '任务已结束，不能继续操作。', 409);
            }
            if (!\in_array($status, [SocialExtensionJob::STATUS_CLAIMED, SocialExtensionJob::STATUS_RUNNING], true)) {
                return $this->fail('job_not_claimed', '任务不在执行中。', 409);
            }

            $heartbeat = $this->jobService()->heartbeat(
                (string)$jobId,
                $claimToken,
                (string)($body['version'] ?? $device['version'] ?? '')
            );
            if (($heartbeat['ok'] ?? false) !== true) {
                $error = (string)($heartbeat['error'] ?? 'heartbeat_failed');

                return $this->fail($error, $this->messageForError($error), $this->statusForError($error));
            }

            $this->markRunning($jobId, $claimToken);

            $jobArray = $this->jobArray($job);
            $snapshot = $this->decodeArray($body['snapshot'] ?? []);
            $recipe = $this->decodeArray($body['recipe'] ?? null);
            if ($recipe === []) {
                $payload = $this->decodeArray($jobArray[SocialExtensionJob::schema_fields_PAYLOAD_JSON] ?? '');
                $recipe = \is_array($payload['recipe'] ?? null) ? $payload['recipe'] : [];
            }
            if ($recipe === []) {
                $recipe = $this->loadRecipe(
                    (string)($jobArray[SocialExtensionJob::schema_fields_PLATFORM_CODE] ?? ''),
                    (string)($jobArray[SocialExtensionJob::schema_fields_CONTENT_KIND] ?? '')
                );
            }
            $accountName = '';
            $recipeEntry = \is_string($recipe['entry'] ?? null) ? $recipe['entry'] : '';
            if (\preg_match('/\{[A-Za-z0-9_]+\}/', $recipeEntry) === 1) {
                $accountName = $this->accountName((int)($jobArray[SocialExtensionJob::schema_fields_ACCOUNT_ID] ?? 0));
            }
            $resolvedRecipe = $this->resolveRecipeEntry($recipe, $accountName);
            $recipe = $resolvedRecipe[0];

            $stepNo = $this->resolveStepNo($jobId);
            $jobArray['_executed_actions'] = $this->executedActions($jobId);
            $decision = $this->stepDecider()->decide($jobArray, $snapshot, $recipe, $stepNo);
            $decision = [
                'action' => (string)($decision['action'] ?? ''),
                'args' => \is_array($decision['args'] ?? null) ? $decision['args'] : [],
                'done' => (bool)($decision['done'] ?? false),
                'error' => (string)($decision['error'] ?? ''),
            ];
            if (!\in_array($decision['action'], SocialStepDecider::ALLOWED_ACTIONS, true)) {
                $decision = ['action' => 'give_up', 'args' => [], 'done' => false, 'error' => 'action_not_allowed'];
            }

            $actions = $this->toActions($decision);
            if ($actions === []) {
                $actions = [$this->giveUpAction('action_not_allowed')];
            }
            $reason = '';
            foreach ($actions as $action) {
                if (($action['type'] ?? '') === 'give_up') {
                    $reason = (string)($action['reason'] ?? 'give_up');
                    break;
                }
            }

            $result = $this->stepResult($body['result'] ?? 'decided');
            $durationMs = \max(0, \min(3600000, (int)($body['duration_ms'] ?? 0)));
            $this->writeStep(
                $jobId,
                $stepNo,
                $actions,
                $this->snapshotHash($snapshot),
                $result,
                $durationMs,
                $reason
            );

            $payload = ['ok' => true, 'step_no' => $stepNo, 'actions' => $actions];
            if ($reason !== '') {
                $payload['reason'] = $reason;
            }

            return $this->json($payload);
        } catch (\Throwable) {
            return $this->fail('internal_error', '请求处理失败，请稍后重试。', 500);
        }
    }

    public function report(): string
    {
        try {
            $device = $this->authDevice();
            if ($device === null) {
                return $this->fail('forbidden', '扩展令牌无效或设备已撤销。', 403);
            }

            $body = $this->body();
            $jobId = (string)($body['job_id'] ?? '');
            $claimToken = $this->claimToken($body);
            if ($jobId === '' || $claimToken === '') {
                return $this->fail('missing_params', '缺少必要参数。', 400);
            }

            $job = $this->loadJob((int)$jobId);
            if ($job === null) {
                return $this->fail('job_not_found', '扩展任务不存在。', 404);
            }
            $claimedBy = (string)($job->getData(SocialExtensionJob::schema_fields_CLAIMED_BY) ?? '');
            if ($claimedBy !== '' && !\hash_equals($claimedBy, (string)$device['device_id'])) {
                return $this->fail('forbidden', '任务已被其他设备领取。', 403);
            }

            $result = $this->jobService()->report($jobId, $claimToken, $this->reportResult($body));
            if (($result['ok'] ?? false) !== true) {
                $error = (string)($result['error'] ?? 'report_failed');
                $payload = [
                    'ok' => false,
                    'error' => $error,
                    'message' => (string)__($this->messageForError($error)),
                ];
                if (isset($result['error_code']) && \is_scalar($result['error_code'])) {
                    $payload['error_code'] = (string)$result['error_code'];
                }

                return $this->json($payload, $this->statusForError($error));
            }

            return $this->json($result);
        } catch (\Throwable) {
            return $this->fail('internal_error', '请求处理失败，请稍后重试。', 500);
        }
    }

    public function release(): string
    {
        try {
            if (\strtoupper((string)$this->request->getMethod()) !== 'POST') {
                return $this->fail('method_not_allowed', '释放接口仅支持 POST 请求。', 405);
            }

            $device = $this->authDevice();
            if ($device === null) {
                return $this->fail('forbidden', '扩展令牌无效或设备已撤销。', 403);
            }

            $body = $this->body();
            $jobId = (string)($body['job_id'] ?? '');
            $claimToken = $this->claimToken($body);
            if ($jobId === '' || $claimToken === '') {
                return $this->fail('missing_params', '缺少必要参数。', 400);
            }

            $job = $this->loadJob((int)$jobId);
            if ($job === null) {
                return $this->fail('job_not_found', '扩展任务不存在。', 404);
            }
            $claimedBy = (string)($job->getData(SocialExtensionJob::schema_fields_CLAIMED_BY) ?? '');
            if ($claimedBy !== '' && !\hash_equals($claimedBy, (string)$device['device_id'])) {
                return $this->fail('forbidden', '任务已被其他设备领取。', 403);
            }

            $result = $this->jobService()->release(
                $jobId,
                $claimToken,
                (string)($body['reason'] ?? '')
            );
            if (($result['ok'] ?? false) !== true) {
                $error = (string)($result['error'] ?? 'release_failed');

                return $this->fail($error, $this->messageForError($error), $this->statusForError($error));
            }

            return $this->json([
                'ok' => true,
                'status' => (string)($result['status'] ?? SocialExtensionJob::STATUS_PENDING),
            ]);
        } catch (\Throwable) {
            return $this->fail('internal_error', '请求处理失败，请稍后重试。', 500);
        }
    }

    /**
     * @return array{device_id:string,version:string,website_id:int,user_id:int}|null
     */
    private function authDevice(): ?array
    {
        $token = $this->requestToken();
        if ($token === '' || \strlen($token) > 256) {
            return null;
        }

        $tokenHash = \hash('sha256', $token);
        $device = ObjectManager::getInstance(SocialExtensionDevice::class);
        $device->clearData()->clearQuery()
            ->where(SocialExtensionDevice::schema_fields_TOKEN_HASH, $tokenHash)
            ->where(SocialExtensionDevice::schema_fields_STATUS, SocialExtensionDevice::STATUS_ACTIVE)
            ->find()
            ->fetch();

        $storedHash = (string)($device->getData(SocialExtensionDevice::schema_fields_TOKEN_HASH) ?? '');
        if (!(int)$device->getId() || $storedHash === '' || !\hash_equals($storedHash, $tokenHash)) {
            return null;
        }

        return [
            'device_id' => (string)($device->getData(SocialExtensionDevice::schema_fields_ID) ?? ''),
            'version' => (string)($device->getData(SocialExtensionDevice::schema_fields_VERSION) ?? ''),
            'website_id' => (int)($device->getData(SocialExtensionDevice::schema_fields_WEBSITE_ID) ?? 0),
            'user_id' => (int)($device->getData(SocialExtensionDevice::schema_fields_USER_ID) ?? 0),
        ];
    }

    private function requestToken(): string
    {
        $bag = $this->request->getServerBag();
        $token = $bag->getHeader('X-Social-Ext-Token');
        if (\is_array($token)) {
            $token = (string)\reset($token);
        }
        $token = \is_string($token) ? \trim($token) : '';

        if ($token === '') {
            $raw = $bag->get('HTTP_X_SOCIAL_EXT_TOKEN', '');
            $token = \is_string($raw) ? \trim($raw) : '';
        }

        if ($token === '') {
            $authorization = $bag->getHeader('Authorization');
            if (\is_array($authorization)) {
                $authorization = (string)\reset($authorization);
            }
            if (\is_string($authorization)
                && \preg_match('/\ABearer\s+(.+)\z/i', \trim($authorization), $matches) === 1) {
                $token = \trim($matches[1]);
            }
        }

        if ($token === '') {
            $bearer = $bag->getBearerToken();
            if (\is_string($bearer)) {
                $token = \trim($bearer);
            }
        }

        return $token;
    }

    /**
     * @return array<string,mixed>
     */
    private function body(): array
    {
        $body = $this->request->getBodyParams();
        if (\is_string($body) && \trim($body) !== '') {
            $decoded = \json_decode($body, true);
            if (\is_array($decoded)) {
                $body = $decoded;
            }
        }
        if (!\is_array($body)) {
            $body = $this->request->getParams();
        }

        return \is_array($body) ? $body : [];
    }

    /**
     * @param array<string,mixed> $body
     */
    private function claimToken(array $body): string
    {
        $token = $body['claim_token'] ?? $body['token'] ?? '';

        return \is_scalar($token) ? \trim((string)$token) : '';
    }

    private function loadJob(int $jobId): ?SocialExtensionJob
    {
        if ($jobId < 1) {
            return null;
        }

        $job = ObjectManager::getInstance(SocialExtensionJob::class);
        $job->clearData()->clearQuery()->load($jobId, null, true);

        return $job->getId() ? $job : null;
    }

    private function claimTokenMatches(SocialExtensionJob $job, string $claimToken): bool
    {
        $stored = (string)($job->getData(SocialExtensionJob::schema_fields_CLAIM_TOKEN) ?? '');

        return $claimToken !== '' && $stored !== '' && \hash_equals($stored, $claimToken);
    }

    private function isTerminalStatus(string $status): bool
    {
        return \in_array($status, [
            SocialExtensionJob::STATUS_SUCCEEDED,
            SocialExtensionJob::STATUS_FAILED,
            SocialExtensionJob::STATUS_SKIPPED,
            SocialExtensionJob::STATUS_STALE,
            SocialExtensionJobService::STATUS_UNVERIFIED,
        ], true);
    }

    private function markRunning(int $jobId, string $claimToken): void
    {
        try {
            ObjectManager::getInstance(SocialExtensionJob::class)->getQuery(false)
                ->where(SocialExtensionJob::schema_fields_ID, $jobId)
                ->where(SocialExtensionJob::schema_fields_CLAIM_TOKEN, $claimToken)
                ->where(SocialExtensionJob::schema_fields_STATUS, SocialExtensionJob::STATUS_CLAIMED)
                ->update([
                    SocialExtensionJob::schema_fields_STATUS => SocialExtensionJob::STATUS_RUNNING,
                    SocialExtensionJob::schema_fields_UPDATED_AT => \date('Y-m-d H:i:s'),
                ])
                ->fetch();
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function jobArray(SocialExtensionJob $job): array
    {
        if (\method_exists($job, 'toArrayData')) {
            $data = $job->toArrayData();
            if (\is_array($data)) {
                return $data;
            }
        }

        $data = [];
        foreach ([
            SocialExtensionJob::schema_fields_ID,
            SocialExtensionJob::schema_fields_TARGET_ID,
            SocialExtensionJob::schema_fields_ACCOUNT_ID,
            SocialExtensionJob::schema_fields_WEBSITE_ID,
            SocialExtensionJob::schema_fields_PLATFORM_CODE,
            SocialExtensionJob::schema_fields_CONTENT_KIND,
            SocialExtensionJob::schema_fields_RECIPE_VERSION,
            SocialExtensionJob::schema_fields_PAYLOAD_JSON,
            SocialExtensionJob::schema_fields_STATUS,
            SocialExtensionJob::schema_fields_ATTEMPTS,
            SocialExtensionJob::schema_fields_CLAIM_TOKEN,
            SocialExtensionJob::schema_fields_CLAIM_EXPIRES_AT,
            SocialExtensionJob::schema_fields_CLAIMED_BY,
            SocialExtensionJob::schema_fields_RESULT_JSON,
            SocialExtensionJob::schema_fields_ERROR_CODE,
            SocialExtensionJob::schema_fields_CREATED_AT,
            SocialExtensionJob::schema_fields_UPDATED_AT,
        ] as $field) {
            $data[$field] = $job->getData($field);
        }

        return $data;
    }

    /**
     * @return array<mixed>
     */
    private function decodeArray(mixed $value): array
    {
        if (\is_string($value) && \trim($value) !== '') {
            $decoded = \json_decode($value, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return \is_array($value) ? $value : [];
    }

    private function resolveStepNo(int $jobId): int
    {
        try {
            $rows = ObjectManager::getInstance(SocialExtensionStep::class)
                ->clearData()
                ->clearQuery()
                ->where(SocialExtensionStep::schema_fields_JOB_ID, $jobId)
                ->order(SocialExtensionStep::schema_fields_STEP_NO, 'DESC')
                ->limit(1)
                ->select()
                ->fetch();
            $items = \is_object($rows) && \method_exists($rows, 'getItems') ? $rows->getItems() : $rows;
            $item = \is_array($items) ? ($items[0] ?? null) : null;
            if ($item instanceof SocialExtensionStep) {
                return (int)($item->getData(SocialExtensionStep::schema_fields_STEP_NO) ?? 0) + 1;
            }
            if (\is_array($item)) {
                return (int)($item[SocialExtensionStep::schema_fields_STEP_NO] ?? 0) + 1;
            }
        } catch (\Throwable) {
        }

        return 1;
    }

    /**
     * @return list<string>
     */
    private function executedActions(int $jobId): array
    {
        $actions = [];
        try {
            $rows = ObjectManager::getInstance(SocialExtensionStep::class)
                ->clearData()
                ->clearQuery()
                ->where(SocialExtensionStep::schema_fields_JOB_ID, $jobId)
                ->select()
                ->fetch();
            $items = \is_object($rows) && \method_exists($rows, 'getItems') ? $rows->getItems() : $rows;
            foreach ((array)$items as $item) {
                $action = $item instanceof SocialExtensionStep
                    ? (string)$item->getData(SocialExtensionStep::schema_fields_ACTION)
                    : (string)($item[SocialExtensionStep::schema_fields_ACTION] ?? '');
                $action = \strtolower(\trim($action));
                if ($action !== '' && !\in_array($action, $actions, true)) {
                    $actions[] = $action;
                }
            }
        } catch (\Throwable) {
        }

        return $actions;
    }

    private function snapshotHash(array $snapshot): string
    {
        $provided = $snapshot['snapshot_hash'] ?? $snapshot['hash'] ?? '';
        if (\is_string($provided) && \preg_match('/^[a-f0-9]{64}$/i', $provided) === 1) {
            return \strtolower($provided);
        }

        $encoded = \json_encode($snapshot, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return \hash('sha256', \is_string($encoded) ? $encoded : '[]');
    }

    private function stepResult(mixed $result): string
    {
        if (\is_array($result)) {
            $result = $result['status'] ?? ($result['ok'] ?? 'decided');
        }
        $result = \trim((string)(\is_scalar($result) ? $result : 'decided'));
        if ($result === '') {
            $result = 'decided';
        }

        return \function_exists('mb_substr') ? \mb_substr($result, 0, 20) : \substr($result, 0, 20);
    }

    /**
     * @param list<array<string,mixed>> $actions
     */
    private function writeStep(
        int $jobId,
        int $stepNo,
        array $actions,
        string $snapshotHash,
        string $result,
        int $durationMs,
        string $reason
    ): void {
        $stored = ['actions' => $actions];
        if ($reason !== '') {
            $stored['reason'] = $reason;
        }
        foreach ($stored['actions'] as $index => $action) {
            if (($action['type'] ?? '') === 'upload' && \is_array($action['asset'] ?? null)) {
                $stored['actions'][$index]['asset']['data_url'] = '[inline]';
            }
        }
        $encoded = \json_encode($stored, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $step = ObjectManager::getInstance(SocialExtensionStep::class);
        $step->clearData()->clearQuery();
        $step->setData([
            SocialExtensionStep::schema_fields_JOB_ID => $jobId,
            SocialExtensionStep::schema_fields_STEP_NO => $stepNo,
            SocialExtensionStep::schema_fields_ACTION => (string)($actions[0]['type'] ?? 'give_up'),
            SocialExtensionStep::schema_fields_SNAPSHOT_HASH => $snapshotHash,
            SocialExtensionStep::schema_fields_DECISION_JSON => \is_string($encoded) ? $encoded : '{}',
            SocialExtensionStep::schema_fields_RESULT => $result,
            SocialExtensionStep::schema_fields_DURATION_MS => $durationMs,
            SocialExtensionStep::schema_fields_CREATED_AT => \date('Y-m-d H:i:s'),
        ])->save();

        if (!$step->getId()) {
            throw new \RuntimeException('step_write_failed');
        }
    }

    /**
     * @param array{action:string,args:array<string,mixed>,done:bool,error:string} $decision
     * @return list<array<string,mixed>>
     */
    private function toActions(array $decision): array
    {
        $action = (string)($decision['action'] ?? '');
        $args = \is_array($decision['args'] ?? null) ? $decision['args'] : [];

        switch ($action) {
            case 'navigate':
                $url = (string)($args['url'] ?? '');
                if (!$this->isActionUrl($url)) {
                    return [$this->giveUpAction('recipe_invalid')];
                }

                return [['type' => 'navigate', 'url' => $url]];

            case 'click':
                $handle = (int)($args['handle'] ?? 0);
                if ($handle < 1) {
                    return [$this->giveUpAction('anchor_not_found')];
                }

                return [['type' => 'click', 'handle' => $handle]];

            case 'type':
                $handle = (int)($args['handle'] ?? 0);
                if ($handle < 1) {
                    return [$this->giveUpAction('anchor_not_found')];
                }
                $text = $args['text'] ?? '';
                $text = \is_scalar($text) ? (string)$text : '';

                return [['type' => 'type', 'handle' => $handle, 'text' => $text]];

            case 'upload':
                $handle = (int)($args['handle'] ?? 0);
                if ($handle < 1) {
                    return [$this->giveUpAction('anchor_not_found')];
                }
                $assetSource = (string)($args['asset'] ?? '');
                $asset = $this->resolveUploadAsset($assetSource);
                if (($asset['ok'] ?? false) !== true) {
                    return [$this->giveUpAction((string)($asset['error'] ?? 'asset_not_found'))];
                }

                return [[
                    'type' => 'upload',
                    'handle' => $handle,
                    'asset' => [
                        'mime' => (string)$asset['mime'],
                        'data_url' => (string)$asset['data_url'],
                        'name' => $this->assetName($assetSource, (string)($args['name'] ?? '')),
                        'ref' => $this->assetRef($args, $assetSource),
                    ],
                ]];

            case 'scroll':
                $direction = \strtolower(\trim((string)($args['direction'] ?? 'down')));
                if (!\in_array($direction, ['up', 'down', 'left', 'right'], true)) {
                    $direction = 'down';
                }

                return [['type' => 'scroll', 'direction' => $direction]];

            case 'wait':
                $ms = \max(100, \min(5000, (int)($args['ms'] ?? 1000)));

                return [['type' => 'wait', 'ms' => $ms]];

            case 'assert':
                $expect = $args['expect'] ?? '';
                if (\is_array($expect)) {
                    $parts = [];
                    foreach (['url_matches', 'text_any'] as $key) {
                        if (\is_array($expect[$key] ?? null)) {
                            $parts[] = $key . ':' . \implode('|', \array_map('strval', $expect[$key]));
                        }
                    }
                    $expect = \implode(' ', $parts);
                }
                $expect = \trim((string)(\is_scalar($expect) ? $expect : ''));

                return [['type' => 'assert', 'expect' => $expect]];

            case 'done':
                $remoteUrl = $args['remote_url'] ?? '';
                $remoteUrl = \is_scalar($remoteUrl) ? (string)$remoteUrl : '';

                return [['type' => 'done', 'remote_url' => $remoteUrl]];

            case 'give_up':
                return [$this->giveUpAction((string)($decision['error'] ?? 'give_up'))];
        }

        return [$this->giveUpAction('action_not_allowed')];
    }

    /**
     * @return array{type:string,reason:string}
     */
    private function giveUpAction(string $reason): array
    {
        $reason = \strtolower(\trim($reason));
        $reason = \preg_replace('/[^a-z0-9_.:-]+/', '_', $reason) ?? '';
        $reason = \trim($reason, '_.:-');
        if ($reason === '') {
            $reason = 'give_up';
        }

        return ['type' => 'give_up', 'reason' => \substr($reason, 0, 64)];
    }

    private function isActionUrl(string $url): bool
    {
        $url = \trim($url);
        if ($url === '') {
            return false;
        }
        if (\str_starts_with($url, '/')) {
            return !\str_starts_with($url, '//');
        }

        $parts = \parse_url($url);

        return \is_array($parts)
            && \strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && \trim((string)($parts['host'] ?? '')) !== '';
    }

    private function assetName(string $asset, string $provided = ''): string
    {
        $name = \trim($provided);
        if ($name !== '') {
            return $name;
        }
        if (\str_starts_with(\trim($asset), 'data:')) {
            return 'asset.bin';
        }

        $path = \parse_url(\str_replace('\\', '/', $asset), \PHP_URL_PATH);
        $name = \basename(\is_string($path) && $path !== '' ? $path : $asset);

        return $name !== '' ? $name : 'asset.bin';
    }

    /**
     * @param array<string,mixed> $args
     */
    private function assetRef(array $args, string $asset): string
    {
        $ref = \trim((string)($args['ref'] ?? ''));
        if ($ref !== '') {
            return $ref;
        }
        $assetId = (int)($args['asset_id'] ?? 0);
        if ($assetId > 0) {
            return (string)$assetId;
        }
        $checksum = \trim((string)($args['checksum'] ?? ''));
        if ($checksum !== '') {
            return $checksum;
        }

        return $asset !== '' ? \sha1($asset) : '';
    }

    /**
     * @return array{ok:bool,error?:string,mime?:string,data_url?:string}
     */
    private function resolveUploadAsset(string $asset): array
    {
        $asset = \trim($asset);
        if ($asset === '') {
            return ['ok' => false, 'error' => 'asset_not_found'];
        }

        if (\str_starts_with($asset, 'data:')) {
            $comma = \strpos($asset, ',');
            if ($comma === false) {
                return ['ok' => false, 'error' => 'asset_not_found'];
            }
            $payload = \substr($asset, $comma + 1);
            $decoded = \base64_decode($payload, true);
            if ($decoded === false) {
                return ['ok' => false, 'error' => 'asset_not_found'];
            }
            if (\strlen($decoded) > self::ASSET_MAX_BYTES) {
                return ['ok' => false, 'error' => 'asset_too_large'];
            }
            $mime = 'application/octet-stream';
            if (\preg_match('/\Adata:([^;,]+)[;,]?/', $asset, $matches) === 1) {
                $mime = \strtolower(\trim($matches[1]));
            }

            return ['ok' => true, 'mime' => $mime, 'data_url' => $asset];
        }

        $path = $this->resolveAssetPath($asset);
        if ($path === '') {
            return ['ok' => false, 'error' => 'asset_not_found'];
        }
        $size = \filesize($path);
        if ($size === false) {
            return ['ok' => false, 'error' => 'asset_not_found'];
        }
        if ($size > self::ASSET_MAX_BYTES) {
            return ['ok' => false, 'error' => 'asset_too_large'];
        }
        $contents = \file_get_contents($path);
        if ($contents === false) {
            return ['ok' => false, 'error' => 'asset_not_found'];
        }
        $mime = 'application/octet-stream';
        if (\function_exists('mime_content_type')) {
            $detected = \mime_content_type($path);
            if (\is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }

        return [
            'ok' => true,
            'mime' => $mime,
            'data_url' => 'data:' . $mime . ';base64,' . \base64_encode($contents),
        ];
    }

    private function resolveAssetPath(string $asset): string
    {
        $asset = \str_replace('\\', '/', \trim($asset));
        if ($asset === '') {
            return '';
        }
        if (\str_starts_with($asset, '/')) {
            return \is_file($asset) && \is_readable($asset) ? $asset : '';
        }

        $root = \defined('BP') ? \rtrim((string)\BP, '/\\') : \dirname(__DIR__, 6);
        $candidates = [
            $root . '/' . \ltrim($asset, '/'),
            $root . '/pub/' . \ltrim($asset, '/'),
            $root . '/app/' . \ltrim($asset, '/'),
        ];
        foreach ($candidates as $candidate) {
            if (\is_file($candidate) && \is_readable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function accountName(int $accountId): string
    {
        if ($accountId < 1) {
            return '';
        }

        try {
            $account = ObjectManager::getInstance(SocialPlatformAccount::class);
            $account->clearData()->clearQuery()->load($accountId, null, true);
            if (!$account->getId()) {
                return '';
            }

            foreach ([
                SocialPlatformAccount::schema_fields_ACCOUNT_NAME,
                SocialPlatformAccount::schema_fields_REMOTE_ACCOUNT_NAME,
                SocialPlatformAccount::schema_fields_REMOTE_ACCOUNT_ID,
            ] as $field) {
                $value = \trim((string)($account->getData($field) ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }

            $profileUrl = \trim((string)($account->getData(SocialPlatformAccount::schema_fields_PROFILE_URL) ?? ''));
            if ($profileUrl !== '') {
                $path = \parse_url($profileUrl, \PHP_URL_PATH);
                $path = \is_string($path) ? \trim($path, '/') : '';
                if ($path !== '') {
                    $segments = \explode('/', $path);
                    $last = \trim((string)\end($segments));
                    if ($last !== '') {
                        return $last;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @return array{0:array<string,mixed>,1:string}
     */
    private function resolveRecipeEntry(array $recipe, string $accountName): array
    {
        $entry = \is_string($recipe['entry'] ?? null) ? \trim($recipe['entry']) : '';
        if ($entry === '') {
            $recipe['entry'] = '';

            return [$recipe, ''];
        }
        if (!\str_contains($entry, '{') && !\str_contains($entry, '}')) {
            $recipe['entry'] = $entry;

            return [$recipe, $entry];
        }

        $accountName = \trim($accountName);
        if ($accountName === '') {
            return $this->unresolvedRecipe($recipe);
        }

        $encoded = \rawurlencode($accountName);
        $resolved = \str_replace(['{page}', '{handle}'], $encoded, $entry);
        if (\str_contains($resolved, '{') || \str_contains($resolved, '}')) {
            return $this->unresolvedRecipe($recipe);
        }

        $recipe['entry'] = $resolved;

        return [$recipe, $resolved];
    }

    /**
     * @return array{0:array<string,mixed>,1:string}
     */
    private function unresolvedRecipe(array $recipe): array
    {
        $recipe['entry'] = '';
        $giveUp = \is_array($recipe['give_up'] ?? null) ? $recipe['give_up'] : [];
        $normalized = [];
        foreach ($giveUp as $reason) {
            if (!\is_scalar($reason)) {
                continue;
            }
            $reason = \trim((string)$reason);
            if ($reason !== '') {
                $normalized[] = $reason;
            }
        }
        $normalized[] = 'entry_unresolved';
        $recipe['give_up'] = \array_values(\array_unique($normalized));

        return [$recipe, ''];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadRecipe(string $platform, string $kind): array
    {
        $platform = \strtolower(\trim($platform));
        $kind = \strtolower(\trim($kind));

        if ($platform !== '' && $kind !== '') {
            try {
                $recipe = ObjectManager::getInstance(SocialRecipeRepository::class)->load($platform, $kind);
                if (\is_array($recipe)) {
                    return $recipe;
                }
            } catch (\Throwable) {
            }
        }

        return [
            'platform' => $platform,
            'version' => 0,
            'entry' => '',
            'give_up' => ['recipe_missing'],
        ];
    }

    /**
     * @return array{ok:bool,error?:string,expires_at?:int}
     */
    private function validateGrantCode(string $code, int $websiteId, string $secret): array
    {
        $parts = \explode('.', $code);
        if (\strlen($code) > 256
            || \count($parts) !== 3
            || $parts[0] !== 'sx1'
            || !\ctype_digit($parts[1])
            || \preg_match('/\A[a-f0-9]{64}\z/i', $parts[2]) !== 1) {
            return ['ok' => false, 'error' => 'grant_invalid'];
        }

        $expiresAt = (int)$parts[1];
        $now = \time();
        if ($expiresAt <= $now) {
            return ['ok' => false, 'error' => 'grant_expired'];
        }
        if ($expiresAt > $now + 305) {
            return ['ok' => false, 'error' => 'grant_invalid'];
        }

        $expected = \hash_hmac('sha256', $parts[1] . '|' . $websiteId, $secret);
        if (!\hash_equals($expected, \strtolower($parts[2]))) {
            return ['ok' => false, 'error' => 'grant_invalid'];
        }

        return ['ok' => true, 'expires_at' => $expiresAt];
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function reportResult(array $body): array
    {
        $result = $this->decodeArray($body['result'] ?? null);
        if ($result === [] && \is_array($body['result'] ?? null)) {
            $result = $body['result'];
        }
        if ($result !== []) {
            foreach (['status', 'error_code', 'remote_url', 'remote_id', 'error', 'message'] as $key) {
                if (!\array_key_exists($key, $result) && \array_key_exists($key, $body)) {
                    $result[$key] = $body[$key];
                }
            }

            return $result;
        }

        foreach ($body as $key => $value) {
            if (\in_array($key, ['job_id', 'claim_token', 'token', 'recipe', 'snapshot', 'version'], true)) {
                continue;
            }
            if (\is_scalar($value) || \is_array($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function jobService(): SocialExtensionJobService
    {
        return ObjectManager::getInstance(SocialExtensionJobService::class);
    }

    private function stepDecider(): SocialStepDecider
    {
        return ObjectManager::getInstance(SocialStepDecider::class);
    }

    private function statusForError(string $error): int
    {
        return match ($error) {
            'forbidden', 'invalid_claim_token', 'grant_invalid', 'grant_expired', 'grant_already_used' => 403,
            'job_not_found' => 404,
            'method_not_allowed' => 405,
            'grant_not_configured', 'grant_cache_unavailable' => 503,
            'missing_params', 'invalid_status', 'invalid_remote_url' => 400,
            'internal_error', 'write_failed' => 500,
            default => 409,
        };
    }

    private function messageForError(string $error): string
    {
        return match ($error) {
            'forbidden' => '扩展令牌无效或设备已撤销。',
            'job_not_found' => '扩展任务不存在。',
            'invalid_claim_token' => '任务领取凭证无效。',
            'invalid_status' => '回执状态不合法。',
            'invalid_remote_url' => '远端链接必须使用 https://。',
            'job_finished' => '任务已结束，不能继续操作。',
            'job_stale' => '任务已过期，不能继续操作。',
            'job_not_claimed' => '任务不在执行中。',
            'release_failed' => '任务释放失败。',
            'missing_params' => '缺少必要参数。',
            'method_not_allowed' => '授权接口仅支持 POST 请求。',
            'grant_not_configured' => '扩展授权密钥未配置。',
            'grant_invalid' => '授权码无效。',
            'grant_expired' => '授权码已过期。',
            'grant_already_used' => '授权码已使用。',
            'grant_cache_unavailable' => '授权码状态写入失败，请重试。',
            'internal_error', 'write_failed' => '请求处理失败，请稍后重试。',
            default => '扩展请求处理失败。',
        };
    }

    private function fail(string $error, string $message, int $status): string
    {
        return $this->json([
            'ok' => false,
            'error' => $error,
            'message' => (string)__($message),
        ], $status);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function json(array $payload, int $status = 200): string
    {
        try {
            $response = $this->request->getResponse();
            $response->setHttpResponseCode($status);
            $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        } catch (\Throwable) {
        }

        $encoded = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return \is_string($encoded) ? $encoded : '{"ok":false,"error":"encode_failed"}';
    }
}
