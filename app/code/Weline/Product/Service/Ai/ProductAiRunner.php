<?php

declare(strict_types=1);

namespace Weline\Product\Service\Ai;

use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\MediaManager\Service\MediaFileAccessContextFactory;
use Weline\Product\Model\Ai\AiDraft;
use Weline\Product\Model\Ai\AiJob;
use Weline\Storage\Api\Data\StorageDiskCode;

/**
 * Executes one queued Product AI job and writes review drafts.
 *
 * The job row is claimed with a compare-and-swap update on `status=queued`
 * plus a random claim token, then re-read: a second worker that lost the race
 * sees the winner's token and exits without calling the provider twice.
 */
final class ProductAiRunner
{
    private const DEFAULT_CANDIDATES = 3;
    private const MAX_CANDIDATES = 4;
    private const DRAFT_TTL_DAYS = 7;

    public function __construct(
        private readonly ProductAiJobService $jobs,
        private readonly ProductAiSnapshotService $snapshots,
        private readonly ProductAiPromptBuilder $prompts,
        private readonly FileAssetLibraryInterface $assets,
    ) {
    }

    /**
     * @return array{job_id:string,status:string,skipped?:bool,error_code?:string,drafts?:int}
     */
    public function run(string $jobId): array
    {
        $jobId = \strtolower(\trim($jobId));
        $job = $this->jobs->findJob($jobId);
        if ($job === null) {
            return ['job_id' => $jobId, 'status' => AiJob::STATUS_FAILED, 'error_code' => 'product_ai_job_not_ready'];
        }
        if ((string)$job->getData(AiJob::schema_fields_STATUS) !== AiJob::STATUS_QUEUED) {
            return [
                'job_id' => $jobId,
                'status' => (string)$job->getData(AiJob::schema_fields_STATUS),
                'skipped' => true,
            ];
        }

        $token = \bin2hex(\random_bytes(8));
        $this->jobs->updateStatus($jobId, AiJob::STATUS_QUEUED, [
            AiJob::schema_fields_STATUS => AiJob::STATUS_RUNNING,
            AiJob::schema_fields_STARTED_AT => \date('Y-m-d H:i:s'),
            AiJob::schema_fields_RESULT_JSON => $this->jobs->json(['claim' => $token]),
        ]);
        $claimed = $this->jobs->findJob($jobId);
        $result = $claimed?->getData(AiJob::schema_fields_RESULT_JSON);
        $claim = \is_string($result) ? (\json_decode($result, true)['claim'] ?? null) : null;
        if ($claimed === null
            || (string)$claimed->getData(AiJob::schema_fields_STATUS) !== AiJob::STATUS_RUNNING
            || !\is_string($claim)
            || !\hash_equals($token, $claim)
        ) {
            return [
                'job_id' => $jobId,
                'status' => (string)($claimed?->getData(AiJob::schema_fields_STATUS) ?? AiJob::STATUS_QUEUED),
                'skipped' => true,
            ];
        }

        try {
            $params = $this->decodeParams($claimed);
            $taskType = (string)$claimed->getData(AiJob::schema_fields_TASK_TYPE);
            $snapshot = $this->snapshots->snapshot(
                (int)$claimed->getData(AiJob::schema_fields_WEBSITE_ID),
                (string)$claimed->getData(AiJob::schema_fields_PRODUCT_UUID),
                (string)$claimed->getData(AiJob::schema_fields_LOCALE),
                (int)($params['store_id'] ?? 0),
            );
            $summary = match ($taskType) {
                AiJob::TASK_COPY => $this->runCopy($claimed, $params, $snapshot),
                AiJob::TASK_IMAGE => $this->runImage($claimed, $params, $snapshot),
                default => throw new ProductAiException(
                    'product_ai_task_unsupported',
                    (string)__('不支持的 AI 优化任务类型'),
                    422,
                    ['task_type' => $taskType],
                ),
            };
        } catch (\Throwable $exception) {
            $error = $exception instanceof ProductAiException
                ? $exception
                : new ProductAiException(
                    'product_ai_job_failed',
                    (string)__('AI 优化任务执行失败'),
                    500,
                    [],
                    $exception,
                );
            $this->jobs->finish(
                $jobId,
                AiJob::STATUS_FAILED,
                [
                    'job_id' => $jobId,
                    'task_type' => (string)$claimed->getData(AiJob::schema_fields_TASK_TYPE),
                    'model_code' => (string)$claimed->getData(AiJob::schema_fields_MODEL_CODE),
                    'error_code' => $error->errorCode(),
                ],
                $error->errorCode(),
                $error->getMessage(),
            );

            return [
                'job_id' => $jobId,
                'status' => AiJob::STATUS_FAILED,
                'error_code' => $error->errorCode(),
                'drafts' => 0,
            ];
        }

        $this->jobs->finish(
            $jobId,
            AiJob::STATUS_SUCCEEDED,
            $summary,
        );

        return [
            'job_id' => $jobId,
            'status' => AiJob::STATUS_SUCCEEDED,
            'drafts' => (int)($summary['drafts'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function runCopy(AiJob $job, array $params, array $snapshot): array
    {
        $jobId = (string)$job->getData(AiJob::schema_fields_ID);
        $modelCode = (string)$job->getData(AiJob::schema_fields_MODEL_CODE);
        $scenarioCode = (string)$job->getData(AiJob::schema_fields_SCENARIO_CODE);
        if (!\class_exists(\Weline\Ai\Service\AiService::class)) {
            throw new ProductAiException('product_ai_ai_unavailable', (string)__('AI 模块未安装或未启用'), 503);
        }

        $prompt = $this->prompts->copyPrompt($snapshot, (string)($params['scope'] ?? ''));
        $response = $this->aiService()->generate(
            $prompt,
            $modelCode,
            $scenarioCode,
            (string)$job->getData(AiJob::schema_fields_LOCALE),
            ['timeout_seconds' => 180],
            (int)$job->getData(AiJob::schema_fields_REQUESTED_BY),
            true,
        );
        $generated = $this->prompts->parseCopyResponse($response);

        $before = \is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
        $hashes = \is_array($snapshot['field_hashes'] ?? null) ? $snapshot['field_hashes'] : [];
        $drafts = 0;
        foreach (AiDraft::COPY_FIELDS as $field) {
            $after = \trim((string)($generated[$field] ?? ''));
            $previous = \trim((string)($before[$field] ?? ''));
            if ($after === '' || $after === $previous) {
                continue;
            }
            $this->upsertDraft($job, $snapshot, $field, [
                'before_json' => $this->jobs->json(['value' => $previous]),
                'before_hash' => (string)($hashes[$field] ?? $this->snapshots->hashField($field, $previous)),
                'after_json' => $this->jobs->json(['value' => $after]),
                'diff_json' => $this->jobs->json($this->diffSegments($previous, $after)),
            ]);
            ++$drafts;
        }

        return [
            'job_id' => $jobId,
            'task_type' => AiJob::TASK_COPY,
            'model_code' => $modelCode,
            'drafts' => $drafts,
            'fields' => \array_keys($generated),
            'local_version' => $snapshot['local_version'],
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function runImage(AiJob $job, array $params, array $snapshot): array
    {
        $jobId = (string)$job->getData(AiJob::schema_fields_ID);
        $modelCode = (string)$job->getData(AiJob::schema_fields_MODEL_CODE);
        $scenarioCode = (string)$job->getData(AiJob::schema_fields_SCENARIO_CODE);
        if (!\class_exists(\Weline\Ai\Service\AiService::class)) {
            throw new ProductAiException('product_ai_ai_unavailable', (string)__('AI 模块未安装或未启用'), 503);
        }
        if (!\class_exists(MediaFileAccessContextFactory::class)) {
            throw new ProductAiException('product_ai_media_unavailable', (string)__('媒体管理模块未启用'), 503);
        }
        $mediaContext = $params['media_context'] ?? null;
        if (!\is_array($mediaContext)) {
            throw new ProductAiException('product_ai_media_context_missing', (string)__('图片任务缺少媒体访问上下文'), 500);
        }
        $access = ObjectManager::getInstance(MediaFileAccessContextFactory::class)
            ->fromFrozen($mediaContext, (int)$job->getData(AiJob::schema_fields_REQUESTED_BY));
        $locale = (string)($mediaContext['locale_code'] ?? 'zh_Hans_CN');

        $candidates = (int)($params['candidates'] ?? self::DEFAULT_CANDIDATES);
        $candidates = \max(1, \min(self::MAX_CANDIDATES, $candidates));
        $prompt = $this->prompts->imagePrompt($snapshot, $candidates);
        $media = \is_array($snapshot['media'] ?? null) ? $snapshot['media'] : [];
        $currentMain = $this->snapshots->currentMainAssetId($media);
        $beforeHash = \hash('sha256', AiDraft::FIELD_MAIN_MEDIA . "\0" . $currentMain);
        $beforeJson = $this->jobs->json(['asset_id' => $currentMain]);

        $productName = \trim((string)($snapshot['fields']['name'] ?? ''));
        $saved = [];
        for ($index = 1; $index <= $candidates; ++$index) {
            $generation = $this->aiService()->generateImage(
                $prompt,
                $modelCode,
                $scenarioCode,
                ['size' => '1024x1280', 'n' => 1, 'job_id' => $jobId, 'candidate' => $index],
            );
            [$bytes, $mimeHint] = $this->resolveImageBytes($generation);
            [$width, $height, $mime] = $this->inspectImage($bytes, $mimeHint);
            $extension = $this->extensionForMime($mime);
            $objectKey = \sprintf(
                'product-ai/%d/%s/%s/%s-%d.%s',
                (int)$job->getData(AiJob::schema_fields_WEBSITE_ID),
                \date('Y'),
                \date('m'),
                $jobId,
                $index,
                $extension,
            );
            $asset = $this->uploadImage(
                $objectKey,
                $bytes,
                $mime,
                $width,
                $height,
                $locale,
                $access,
                $productName,
                $jobId,
                $index,
            );
            $assetId = \strtolower(\trim((string)($asset['asset_id'] ?? '')));
            if (\preg_match('/^[a-f0-9-]{36}$/', $assetId) !== 1) {
                throw new ProductAiException(
                    'product_ai_media_upload_failed',
                    (string)__('AI 产图落库失败'),
                    502,
                    ['candidate' => $index],
                );
            }
            $this->upsertDraft($job, $snapshot, AiDraft::FIELD_MAIN_MEDIA . ':' . $index, [
                'before_json' => $beforeJson,
                'before_hash' => $beforeHash,
                'after_json' => $this->jobs->json([
                    'asset_id' => $assetId,
                    'url' => (string)($asset['preview_url'] ?? ''),
                    'width' => $width,
                    'height' => $height,
                    'mime' => $mime,
                    'candidate' => $index,
                ]),
                'diff_json' => null,
            ]);
            $saved[] = $assetId;
        }

        return [
            'job_id' => $jobId,
            'task_type' => AiJob::TASK_IMAGE,
            'model_code' => $modelCode,
            'drafts' => \count($saved),
            'assets' => $saved,
            'local_version' => $snapshot['local_version'],
        ];
    }

    /**
     * @param array<string,mixed> $values
     */
    private function upsertDraft(AiJob $job, array $snapshot, string $fieldKey, array $values): void
    {
        $jobId = (string)$job->getData(AiJob::schema_fields_ID);
        $draftId = ProductAiJobService::uuidFromSeed('product-ai:draft:' . $jobId . ':' . $fieldKey);
        $draft = ObjectManager::make(AiDraft::class)
            ->reset()
            ->where(AiDraft::schema_fields_ID, $draftId)
            ->find()
            ->fetch();
        $data = [
            AiDraft::schema_fields_JOB_ID => $jobId,
            AiDraft::schema_fields_WEBSITE_ID => (int)$job->getData(AiJob::schema_fields_WEBSITE_ID),
            AiDraft::schema_fields_PRODUCT_UUID => (string)$job->getData(AiJob::schema_fields_PRODUCT_UUID),
            AiDraft::schema_fields_PRODUCT_ID => (int)($snapshot['product_id'] ?? 0),
            AiDraft::schema_fields_TASK_TYPE => (string)$job->getData(AiJob::schema_fields_TASK_TYPE),
            AiDraft::schema_fields_FIELD_KEY => $fieldKey,
            AiDraft::schema_fields_BEFORE_JSON => $values['before_json'] ?? null,
            AiDraft::schema_fields_BEFORE_HASH => (string)($values['before_hash'] ?? ''),
            AiDraft::schema_fields_AFTER_JSON => $values['after_json'] ?? null,
            AiDraft::schema_fields_DIFF_JSON => $values['diff_json'] ?? null,
            AiDraft::schema_fields_STATUS => AiDraft::STATUS_PENDING,
            AiDraft::schema_fields_EXPIRES_AT => \date('Y-m-d H:i:s', \time() + self::DRAFT_TTL_DAYS * 86400),
            AiDraft::schema_fields_CREATED_AT => \date('Y-m-d H:i:s'),
        ];
        if ($draft->getId()) {
            unset($data[AiDraft::schema_fields_CREATED_AT]);
            $draft->setData($data)->save();

            return;
        }
        $data[AiDraft::schema_fields_ID] = $draftId;
        ObjectManager::make(AiDraft::class)->clear()->setData($data)->save();
    }

    /**
     * @param array<string,mixed> $generation
     * @return array{0:string,1:string}
     */
    private function resolveImageBytes(array $generation): array
    {
        $images = \is_array($generation['images'] ?? null) ? $generation['images'] : [];
        $image = \is_array($images[0] ?? null) ? $images[0] : [];
        $mimeHint = \strtolower(\trim((string)($image['mime_type'] ?? '')));
        $b64 = \trim((string)($image['b64_json'] ?? ''));
        if ($b64 !== '') {
            $bytes = \base64_decode($b64, true);
            if ($bytes === false || $bytes === '') {
                throw new ProductAiException('product_ai_image_invalid', (string)__('AI 返回的图片数据无效'), 502);
            }

            return [$bytes, $mimeHint];
        }
        $url = \trim((string)($image['url'] ?? ''));
        if ($url === '') {
            throw new ProductAiException('product_ai_image_invalid', (string)__('AI 未返回图片'), 502);
        }
        if (\preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $matches) === 1) {
            $bytes = \base64_decode(\preg_replace('/\s+/', '', (string)$matches[2]) ?? '', true);
            if ($bytes === false || $bytes === '') {
                throw new ProductAiException('product_ai_image_invalid', (string)__('AI 返回的图片数据无效'), 502);
            }

            return [$bytes, \strtolower(\trim((string)$matches[1]))];
        }

        return [$this->downloadImageUrl($url), $mimeHint];
    }

    private function downloadImageUrl(string $url, int $redirects = 0): string
    {
        $maxBytes = \class_exists(\Weline\MediaManager\Service\MediaStorageService::class)
            ? (int)\Weline\MediaManager\Service\MediaStorageService::MAX_IMAGE_BYTES
            : 20 * 1024 * 1024;
        if ($redirects > 3 || !\function_exists('curl_init')) {
            throw new ProductAiException(
                'product_ai_image_download_failed',
                (string)__('无法安全下载生成的图片'),
                502,
            );
        }
        [$host, $port, $resolvedIp] = $this->assertPublicImageUrl($url);
        $curl = \curl_init($url);
        if (!$curl instanceof \CurlHandle) {
            throw new ProductAiException('product_ai_image_download_failed', (string)__('图片下载客户端初始化失败'), 502);
        }
        $body = '';
        $tooLarge = false;
        $location = '';
        $status = 0;
        try {
            \curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (\str_contains($resolvedIp, ':') ? '[' . $resolvedIp . ']' : $resolvedIp)],
                CURLOPT_HEADERFUNCTION => static function (mixed $_handle, string $line) use (&$location, &$tooLarge, $maxBytes): int {
                    $trimmed = \trim($line);
                    if (\stripos($trimmed, 'Location:') === 0) {
                        $location = \trim(\substr($trimmed, 9));
                    } elseif (\stripos($trimmed, 'Content-Length:') === 0
                        && (int)\trim(\substr($trimmed, 15)) > $maxBytes
                    ) {
                        $tooLarge = true;
                    }

                    return \strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function (mixed $_handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                    if ($tooLarge || \strlen($body) + \strlen($chunk) > $maxBytes) {
                        $tooLarge = true;

                        return 0;
                    }
                    $body .= $chunk;

                    return \strlen($chunk);
                },
            ]);
            $ok = \curl_exec($curl);
            $status = (int)\curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            \curl_close($curl);
        }
        if ($tooLarge) {
            throw new ProductAiException('product_ai_image_too_large', (string)__('生成图片超过下载大小限制'), 502);
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            return $this->downloadImageUrl($this->resolveImageRedirect($url, $location), $redirects + 1);
        }
        if ($ok !== true || $status < 200 || $status >= 300 || $body === '') {
            throw new ProductAiException('product_ai_image_download_failed', (string)__('图片下载失败'), 502);
        }

        return $body;
    }

    /** @return array{0:string,1:int,2:string} */
    private function assertPublicImageUrl(string $url): array
    {
        $parts = \parse_url($url);
        $scheme = \strtolower((string)($parts['scheme'] ?? ''));
        $host = \strtolower(\rtrim(\trim((string)($parts['host'] ?? '')), '.'));
        $port = (int)($parts['port'] ?? 443);
        if ($scheme !== 'https' || $host === '' || $port !== 443
            || isset($parts['user']) || isset($parts['pass'])
            || $host === 'localhost' || \str_ends_with($host, '.local') || \str_ends_with($host, '.localhost')
        ) {
            throw new ProductAiException(
                'product_ai_image_download_failed',
                (string)__('生成图片地址必须是公共 HTTPS 地址'),
                502,
            );
        }
        $ips = [];
        if (\filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } elseif (\function_exists('dns_get_record')) {
            foreach ((array)@\dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }
        } else {
            $ips = (array)@\gethostbynamel($host);
        }
        $ips = \array_values(\array_unique($ips));
        if ($ips === []) {
            throw new ProductAiException('product_ai_image_download_failed', (string)__('生成图片地址无法解析'), 502);
        }
        foreach ($ips as $ip) {
            if (!\filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new ProductAiException(
                    'product_ai_image_download_failed',
                    (string)__('生成图片地址不能解析到内网或保留地址'),
                    502,
                );
            }
        }

        return [$host, $port, $ips[0]];
    }

    private function resolveImageRedirect(string $baseUrl, string $location): string
    {
        if (\parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        if (!\str_starts_with($location, '/')) {
            throw new ProductAiException('product_ai_image_download_failed', (string)__('生成图片重定向地址无效'), 502);
        }
        $host = (string)(\parse_url($baseUrl)['host'] ?? '');
        if ($host === '') {
            throw new ProductAiException('product_ai_image_download_failed', (string)__('生成图片重定向地址无效'), 502);
        }

        return 'https://' . $host . $location;
    }

    /** @return array{0:int,1:int,2:string} */
    private function inspectImage(string $bytes, string $mimeHint): array
    {
        $maxBytes = \class_exists(\Weline\MediaManager\Service\MediaStorageService::class)
            ? (int)\Weline\MediaManager\Service\MediaStorageService::MAX_IMAGE_BYTES
            : 20 * 1024 * 1024;
        if ($bytes === '' || \strlen($bytes) > $maxBytes) {
            throw new ProductAiException('product_ai_image_too_large', (string)__('生成图片超过大小限制'), 502);
        }
        $info = @\getimagesizefromstring($bytes);
        if (!\is_array($info) || (int)($info[0] ?? 0) < 1 || (int)($info[1] ?? 0) < 1) {
            throw new ProductAiException('product_ai_image_invalid', (string)__('生成内容不是有效图片'), 502);
        }
        $mime = \strtolower((string)($info['mime'] ?? ''));
        if (!\str_starts_with($mime, 'image/')) {
            $mime = $mimeHint;
        }
        if (!\str_starts_with($mime, 'image/')) {
            throw new ProductAiException('product_ai_image_invalid', (string)__('生成图片格式不受支持'), 502);
        }

        return [(int)$info[0], (int)$info[1], $mime];
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
            default => 'png',
        };
    }

    /** @param array<string,mixed> $access */
    private function uploadImage(
        string $objectKey,
        string $bytes,
        string $mime,
        int $width,
        int $height,
        string $locale,
        $access,
        string $productName,
        string $jobId,
        int $index,
    ): array {
        $stream = \fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new ProductAiException('product_ai_media_upload_failed', (string)__('图片流创建失败'), 502);
        }
        try {
            \fwrite($stream, $bytes);
            \rewind($stream);
            $name = $productName !== '' ? $productName : (string)__('商品主图');
            $asset = $this->assets->upload(
                StorageDiskCode::BUILTIN_LOCAL_MEDIA,
                $objectKey,
                $stream,
                \sprintf('%s-%d.%s', $jobId, $index, $this->extensionForMime($mime)),
                $mime,
                $locale,
                $access,
                [
                    'display_name' => \mb_strcut($name . ' · AI ' . $index, 0, 200, 'UTF-8'),
                    'default_alt' => \mb_strcut($name . ' · AI', 0, 200, 'UTF-8'),
                    'description' => \mb_strcut((string)__('AI 生成的商品主图候选') . ' ' . $index, 0, 500, 'UTF-8'),
                ],
                FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                [
                    'ai_scenario' => 'product_image_optimize',
                    'ai_job_id' => $jobId,
                    'ai_candidate' => $index,
                ],
                $width,
                $height,
            );
        } catch (ProductAiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new ProductAiException(
                'product_ai_media_upload_failed',
                (string)__('AI 产图落库失败'),
                502,
                ['object_key' => $objectKey],
                $exception,
            );
        } finally {
            \fclose($stream);
        }

        return $asset;
    }

    /** @return array<string,mixed> */
    private function decodeParams(AiJob $job): array
    {
        $raw = $job->getData(AiJob::schema_fields_PARAMS_JSON);
        if (!\is_string($raw) || \trim($raw) === '') {
            return [];
        }
        try {
            $decoded = \json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private function aiService(): object
    {
        return ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
    }

    /**
     * Bounded common-prefix/suffix diff; never ships the whole document twice.
     *
     * @return array{before_prefix:string,before_suffix:string,after_prefix:string,after_suffix:string,before_changed:string,after_changed:string,truncated:bool}
     */
    private function diffSegments(string $before, string $after): array
    {
        $limit = 400;
        $beforeChars = \preg_split('//u', $before, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $afterChars = \preg_split('//u', $after, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $prefix = 0;
        $maxPrefix = \min(\count($beforeChars), \count($afterChars));
        while ($prefix < $maxPrefix && $beforeChars[$prefix] === $afterChars[$prefix]) {
            ++$prefix;
        }
        $suffix = 0;
        while ($suffix < \min(\count($beforeChars), \count($afterChars)) - $prefix
            && $beforeChars[\count($beforeChars) - 1 - $suffix] === $afterChars[\count($afterChars) - 1 - $suffix]
        ) {
            ++$suffix;
        }
        $beforeMiddle = \implode('', \array_slice($beforeChars, $prefix, \count($beforeChars) - $prefix - $suffix));
        $afterMiddle = \implode('', \array_slice($afterChars, $prefix, \count($afterChars) - $prefix - $suffix));

        return [
            'before_prefix' => \implode('', \array_slice($beforeChars, 0, \min($prefix, $limit))),
            'before_suffix' => \implode('', \array_slice($beforeChars, \count($beforeChars) - \min($suffix, $limit))),
            'after_prefix' => \implode('', \array_slice($afterChars, 0, \min($prefix, $limit))),
            'after_suffix' => \implode('', \array_slice($afterChars, \count($afterChars) - \min($suffix, $limit))),
            'before_changed' => \mb_strcut($beforeMiddle, 0, $limit, 'UTF-8'),
            'after_changed' => \mb_strcut($afterMiddle, 0, $limit, 'UTF-8'),
            'truncated' => \mb_strlen($beforeMiddle . $afterMiddle, 'UTF-8') > $limit * 2,
        ];
    }
}
