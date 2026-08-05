<?php

declare(strict_types=1);

namespace Weline\Websites\Queue;

use Weline\Queue\DeadWorkerRecoveryPatchQueueInterface;
use Weline\Queue\Exception\QueueDeferredCompletionException;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;
use Weline\Websites\Service\AiSiteProvisioningJobHandler;

class AiSiteProvisioningQueue implements QueueInterface, DeadWorkerRecoveryPatchQueueInterface
{
    private const MAX_DEAD_WORKER_RECOVERIES = 3;
    private const HOSTS_AUTHORIZATION_WAIT_KEY = '_hosts_authorization_wait_v1';
    private const HOSTS_AUTHORIZATION_WAIT_TIMEOUT_SECONDS = 420;
    private const HOSTS_AUTHORIZATION_BACKOFF_BASE_SECONDS = 5;
    private const HOSTS_AUTHORIZATION_BACKOFF_MAX_SECONDS = 60;

    /** @var null|\Closure():int */
    private ?\Closure $clock;

    /** @var null|\Closure(string):array */
    private ?\Closure $hostResolver;

    /**
     * Optional closures are deterministic unit-test seams. Production always
     * uses the system clock and the operating-system IPv4 resolver.
     *
     * @param null|\Closure():int $clock
     * @param null|\Closure(string):array $hostResolver
     */
    public function __construct(
        private readonly AiSiteProvisioningJobHandler $jobHandler,
        ?\Closure $clock = null,
        ?\Closure $hostResolver = null
    ) {
        $this->clock = $clock;
        $this->hostResolver = $hostResolver;
    }

    public function name(): string
    {
        return (string)__('AI建站域名准备队列');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('域名准备与 AI Plan/Build 并行；域名未完成时只阻断 Publish。');
    }

    public function validate(Queue &$queue): bool
    {
        $content = $this->decodeContent($queue);
        $requestId = \trim((string)($content['request_id'] ?? ''));
        $executionToken = \trim((string)($content['execution_token'] ?? ''));
        if ($requestId === '' || $executionToken === '') {
            $queue->setResult((string)__('域名准备队列缺少请求 ID 或执行令牌。'));

            return false;
        }

        if (!$this->jobHandler->canHandle($requestId, $executionToken)) {
            $queue->setResult((string)__('域名准备请求不存在、已失败或执行令牌无效。'));

            return false;
        }

        return true;
    }

    public function execute(Queue &$queue): string
    {
        $content = $this->decodeContent($queue);
        $requestId = (string)($content['request_id'] ?? '');
        $executionToken = (string)($content['execution_token'] ?? '');
        $wait = \is_array($content[self::HOSTS_AUTHORIZATION_WAIT_KEY] ?? null)
            ? $content[self::HOSTS_AUTHORIZATION_WAIT_KEY]
            : [];
        $takeoverToken = \trim((string)($content['_queue_takeover']['token'] ?? ''));
        if ($wait !== []
            && !\hash_equals(
                (string)($wait['takeover_token'] ?? ''),
                $takeoverToken
            )
        ) {
            // An explicit Scheduler takeover/retry starts a new bounded wait
            // generation without creating another Queue or request.
            unset($content[self::HOSTS_AUTHORIZATION_WAIT_KEY]);
            $wait = [];
        }

        if ($wait !== []) {
            $now = $this->now();
            $deadline = $this->authorizationDeadline($wait, $now);
            if ($deadline === null || $now >= $deadline) {
                return $this->expireAuthorization($requestId, $executionToken);
            }
            $wait['deadline_at'] = $deadline;
            $content[self::HOSTS_AUTHORIZATION_WAIT_KEY] = $wait;
            $domain = \strtolower(\trim((string)($wait['domain'] ?? '')));
            if (!$this->domainResolvesToLoopback($domain)) {
                $wait['checks'] = \max(1, (int)($wait['checks'] ?? 1)) + 1;
                $content[self::HOSTS_AUTHORIZATION_WAIT_KEY] = $wait;

                return $this->deferForAuthorization($content, $domain, $now);
            }
        }

        $result = $this->jobHandler->handle(
            $requestId,
            $executionToken
        );
        if (($result['authorization_pending'] ?? false) === true) {
            $domain = \strtolower(\trim((string)($result['target_domain'] ?? '')));
            if ($wait !== []) {
                $now = $this->now();
                $deadline = $this->authorizationDeadline($wait, $now);
                if ($deadline === null || $now >= $deadline) {
                    return $this->expireAuthorization($requestId, $executionToken);
                }
                $wait['domain'] = $domain;
                $wait['deadline_at'] = $deadline;
                $wait['checks'] = \max(1, (int)($wait['checks'] ?? 1)) + 1;
                $content[self::HOSTS_AUTHORIZATION_WAIT_KEY] = $wait;

                return $this->deferForAuthorization($content, $domain, $now);
            }
            $now = $this->now();
            if ($now < 1 || $now > \PHP_INT_MAX - self::HOSTS_AUTHORIZATION_WAIT_TIMEOUT_SECONDS) {
                throw new \RuntimeException('hosts_authorization_clock_invalid');
            }
            $content[self::HOSTS_AUTHORIZATION_WAIT_KEY] = [
                'domain' => $domain,
                'started_at' => $now,
                'deadline_at' => $now + self::HOSTS_AUTHORIZATION_WAIT_TIMEOUT_SECONDS,
                'checks' => 1,
                'takeover_token' => $takeoverToken,
            ];

            return $this->deferForAuthorization($content, $domain, $now);
        }

        return (string)(\json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)
            ?: __('域名准备完成。'));
    }

    public function shouldRecoverDeadWorker(Queue $queue, int $deadPid, string $workerOutput): bool
    {
        $content = $this->decodeContent($queue);
        $attempts = \max(0, (int)($content['_dead_worker_retries'] ?? 0));

        return $attempts < self::MAX_DEAD_WORKER_RECOVERIES;
    }

    public function deadWorkerRecoveryPatch(Queue $queue, int $deadPid, string $workerOutput): array
    {
        if (!$this->shouldRecoverDeadWorker($queue, $deadPid, $workerOutput)) {
            return [];
        }

        $content = $this->decodeContent($queue);
        $content['_dead_worker_retries'] = \max(0, (int)($content['_dead_worker_retries'] ?? 0)) + 1;
        $encoded = \json_encode($content, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (!\is_string($encoded) || $encoded === '') {
            return [];
        }

        return [Queue::schema_fields_content => $encoded];
    }

    public function deadWorkerRecoveryMessage(Queue $queue, int $deadPid, string $workerOutput): string
    {
        $attempts = \min(
            self::MAX_DEAD_WORKER_RECOVERIES,
            \max(0, (int)($this->decodeContent($queue)['_dead_worker_retries'] ?? 0)) + 1,
        );

        return (string)__('域名准备进程异常退出，Scheduler 将自动恢复（第 %{1}/%{2} 次）。', [
            $attempts,
            self::MAX_DEAD_WORKER_RECOVERIES,
        ]);
    }

    /** @return array<string, mixed> */
    private function decodeContent(Queue $queue): array
    {
        $content = $queue->getContent();
        if (\is_array($content)) {
            return $content;
        }

        $decoded = \json_decode((string)$content, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $content */
    private function deferForAuthorization(array $content, string $domain, int $now): never
    {
        $wait = \is_array($content[self::HOSTS_AUTHORIZATION_WAIT_KEY] ?? null)
            ? $content[self::HOSTS_AUTHORIZATION_WAIT_KEY]
            : [];
        $checks = \max(1, (int)($wait['checks'] ?? 1));
        $deadline = $this->authorizationDeadline($wait, $now);
        if ($deadline === null || $now >= $deadline) {
            throw new \RuntimeException('hosts_authorization_wait_invalid');
        }
        $remaining = $deadline - $now;
        $exponent = \min(4, $checks - 1);
        $delay = (int)\min(
            self::HOSTS_AUTHORIZATION_BACKOFF_MAX_SECONDS,
            self::HOSTS_AUTHORIZATION_BACKOFF_BASE_SECONDS * (2 ** $exponent),
            $remaining
        );
        $notBefore = \gmdate('Y-m-d H:i:s', $now + $delay);
        $encoded = \json_encode(
            $content,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR
        );
        $message = (string)__('等待 macOS 管理员批准 %{1} 的 hosts 配置；Scheduler 将自动复检。', [
            $domain,
        ]);
        $result = (string)\json_encode([
            'status' => Queue::status_pending,
            'authorization_pending' => true,
            'target_domain' => $domain,
            'not_before' => $notBefore,
            'message' => $message,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        throw new QueueDeferredCompletionException($encoded, $message, $result, $notBefore);
    }

    /** @param array<string,mixed> $wait */
    private function authorizationDeadline(array $wait, int $now): ?int
    {
        $startedAt = $wait['started_at'] ?? null;
        if (!\is_int($startedAt)
            || $startedAt < 1
            || $startedAt > $now
            || $startedAt > \PHP_INT_MAX - self::HOSTS_AUTHORIZATION_WAIT_TIMEOUT_SECONDS
        ) {
            return null;
        }
        $deadline = $startedAt + self::HOSTS_AUTHORIZATION_WAIT_TIMEOUT_SECONDS;
        if (\array_key_exists('deadline_at', $wait)
            && (!\is_int($wait['deadline_at']) || $wait['deadline_at'] !== $deadline)
        ) {
            return null;
        }

        return $deadline;
    }

    private function expireAuthorization(string $requestId, string $executionToken): string
    {
        // Marks the durable request terminal, then throws so the framework's
        // fenced Worker completion marks this Queue error.
        $terminal = $this->jobHandler->handle($requestId, $executionToken, true);
        if (($terminal['status'] ?? '') === 'done') {
            return (string)\json_encode(
                $terminal,
                \JSON_UNESCAPED_UNICODE
                | \JSON_UNESCAPED_SLASHES
                | \JSON_THROW_ON_ERROR
            );
        }

        throw new \RuntimeException('hosts_authorization_expiry_not_enforced');
    }

    private function now(): int
    {
        return $this->clock instanceof \Closure
            ? (int)($this->clock)()
            : \time();
    }

    private function domainResolvesToLoopback(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }
        $ips = $this->hostResolver instanceof \Closure
            ? ($this->hostResolver)($domain)
            : \gethostbynamel($domain);
        if (!\is_array($ips) || $ips === []) {
            return false;
        }

        foreach (\array_unique(\array_map('strval', $ips)) as $ip) {
            if ($ip !== '127.0.0.1') {
                return false;
            }
        }

        return true;
    }
}
