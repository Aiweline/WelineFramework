<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

/**
 * 本机静默 SSE 客户端：pair → 长连接 → 官方 inbound 重放 → ack。
 */
final class DevRelayLocalConnectService
{
    private const STATE_FILE = 'var/payment-dev-relay-worker.json';
    private const PID_FILE = 'var/payment-dev-relay-worker.pid';
    private const LOG_FILE = 'var/log/payment-dev-relay.log';
    private const STOP_FILE = 'var/payment-dev-relay-worker.stop';
    private const CRED_FILE = 'var/payment-dev-relay-worker.cred.json';
    /** 本机持久凭证（关闭 worker 后仍保留，供面板一键探测）。 */
    private const SECRET_FILE = 'var/payment-dev-relay-local.secret.json';

    public function __construct(
        private readonly DevRelayGateService $gate,
        private readonly DevRelaySessionService $sessions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $state = $this->readState();
        $pid = $this->readPid();
        $running = $pid > 0 && $this->isProcessAlive($pid);

        return [
            'running' => $running,
            'pid' => $running ? $pid : 0,
            'online_base_url' => (string) ($state['online_base_url'] ?? ''),
            'session_code' => (string) ($state['session_code'] ?? ''),
            'stream_url' => (string) ($state['stream_url'] ?? ''),
            'local_inbound_url' => (string) ($state['local_inbound_url'] ?? ''),
            'started_at' => (string) ($state['started_at'] ?? ''),
            'last_event_at' => (string) ($state['last_event_at'] ?? ''),
            'last_event_code' => (string) ($state['last_event_code'] ?? ''),
            'last_error' => (string) ($state['last_error'] ?? ''),
            'relayed_ok' => (int) ($state['relayed_ok'] ?? 0),
            'relayed_fail' => (int) ($state['relayed_fail'] ?? 0),
            'recent_events' => \is_array($state['recent_events'] ?? null) ? $state['recent_events'] : [],
            'credentials_ready' => $this->hasRememberedCredentials(),
        ];
    }

    /**
     * @return array{online_base_url:string,user_token:string}|null
     */
    public function readRememberedCredentials(): ?array
    {
        foreach ([self::SECRET_FILE, self::CRED_FILE] as $rel) {
            $path = BP . $rel;
            if (!is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            if (!\is_array($decoded)) {
                continue;
            }
            $online = rtrim(trim((string) ($decoded['online_base_url'] ?? '')), '/');
            $token = trim((string) ($decoded['user_token'] ?? ''));
            if ($online !== '' && $token !== '') {
                return ['online_base_url' => $online, 'user_token' => $token];
            }
        }

        return null;
    }

    public function hasRememberedCredentials(): bool
    {
        return $this->readRememberedCredentials() !== null;
    }

    public function rememberCredentials(string $onlineBaseUrl, string $userToken): void
    {
        $onlineBaseUrl = rtrim(trim($onlineBaseUrl), '/');
        $userToken = trim($userToken);
        if ($onlineBaseUrl === '' || $userToken === '') {
            return;
        }
        $path = BP . self::SECRET_FILE;
        @mkdir(dirname($path), 0775, true);
        @file_put_contents($path, json_encode([
            'online_base_url' => $onlineBaseUrl,
            'user_token' => $userToken,
            'updated_at' => date('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        @chmod($path, 0600);
    }

    /**
     * 面板一键：必要时用已存凭证拉起 worker，并等待 session 就绪。
     *
     * @return array<string, mixed>
     */
    public function ensureRunning(?string $onlineBaseUrl = null, ?string $userToken = null, int $waitSeconds = 20): array
    {
        $remembered = $this->readRememberedCredentials() ?? ['online_base_url' => '', 'user_token' => ''];
        $online = rtrim(trim((string) ($onlineBaseUrl ?: $remembered['online_base_url'] ?: ($this->gate->config()['online_base_url'] ?? ''))), '/');
        $token = trim((string) ($userToken ?: $remembered['user_token']));
        if ($online === '' || $token === '') {
            throw new \InvalidArgumentException((string) __('尚未记住线上凭证：请先到支付后台 DevRelay 控制台开启一次中继。'));
        }

        $status = $this->status();
        if (!empty($status['running']) && (string) ($status['session_code'] ?? '') !== '') {
            return $status;
        }

        if (empty($status['running'])) {
            try {
                $this->start($online, $token);
            } catch (\Throwable $e) {
                // 并发启动 / 宿主误杀后短暂存活：再读一次
                usleep(600_000);
                $status = $this->status();
                if (!empty($status['running'])) {
                    // continue wait loop below
                } elseif (!str_contains($e->getMessage(), '已在运行')) {
                    throw new \RuntimeException(
                        (string) __('面板自动拉起中继失败（WLS 请求内子进程易被回收）。请先在本机执行：')
                        . ' php bin/w payment:devrelay:start'
                        . ' — ' . $e->getMessage()
                    );
                }
            }
        }

        $deadline = time() + max(5, $waitSeconds);
        $sawRunning = false;
        do {
            usleep(400_000);
            $status = $this->status();
            if (!empty($status['running'])) {
                $sawRunning = true;
            }
            if (!empty($status['running']) && (string) ($status['session_code'] ?? '') !== '') {
                return $status;
            }
            // 进程中途挂掉：再拉起一次
            if ($sawRunning && empty($status['running'])) {
                $sawRunning = false;
                try {
                    $this->start($online, $token);
                } catch (\Throwable) {
                }
            }
        } while (time() < $deadline);

        if (empty($status['running'])) {
            throw new \RuntimeException((string) __('中继未能启动：') . $this->tailLog(400));
        }

        throw new \RuntimeException((string) __('中继已启动但尚未完成线上配对，请稍后重试。') . ' ' . $this->tailLog(240));
    }

    private function tailLog(int $bytes = 400): string
    {
        $path = BP . self::LOG_FILE;
        if (!is_file($path)) {
            return '';
        }
        $raw = (string) @file_get_contents($path, false, null, max(0, filesize($path) - $bytes));
        $raw = trim(preg_replace('/\s+/', ' ', strip_tags($raw)) ?? $raw);

        return $raw !== '' ? (' log=' . mb_substr($raw, -240)) : '';
    }

    /**
     * 用已存凭证向线上注入探针。
     *
     * @return array<string, mixed>
     */
    public function probeOnline(string $marker = '', ?string $onlineBaseUrl = null, ?string $userToken = null): array
    {
        $remembered = $this->readRememberedCredentials() ?? ['online_base_url' => '', 'user_token' => ''];
        $online = rtrim(trim((string) ($onlineBaseUrl ?: $remembered['online_base_url'] ?: ($this->gate->config()['online_base_url'] ?? ''))), '/');
        $token = trim((string) ($userToken ?: $remembered['user_token']));
        if ($online === '' || $token === '') {
            throw new \InvalidArgumentException((string) __('尚未记住线上凭证：请先到支付后台 DevRelay 控制台开启一次中继。'));
        }
        $this->rememberCredentials($online, $token);
        if ($marker === '') {
            $marker = 'panel_' . bin2hex(random_bytes(4));
        }

        $url = $online . '/payment/dev-relay/probe';
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['marker' => $marker], JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) {
            throw new \RuntimeException('probe HTTP failed: ' . $err);
        }
        $decoded = json_decode((string) $raw, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('probe bad response HTTP ' . $http . ': ' . (string) $raw);
        }
        $decoded['http_status'] = $http;
        $decoded['marker'] = $marker;
        $decoded['probe_url'] = $url;

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function start(string $onlineBaseUrl, string $userToken): array
    {
        if (!$this->gate->isLocalEnvironment()) {
            throw new \RuntimeException((string) __('仅本地环境可启动静默 DevRelay worker。'));
        }

        $remembered = $this->readRememberedCredentials() ?? ['online_base_url' => '', 'user_token' => ''];
        $onlineBaseUrl = rtrim(trim($onlineBaseUrl !== '' ? $onlineBaseUrl : (string) ($remembered['online_base_url'] ?: ($this->gate->config()['online_base_url'] ?? ''))), '/');
        $userToken = trim($userToken !== '' ? $userToken : (string) ($remembered['user_token'] ?? ''));

        if (!$this->gate->canOpenUi()) {
            throw new \RuntimeException((string) __('请先在支付钩子控制台启用 DevRelay。'));
        }

        if ($onlineBaseUrl === '' || $userToken === '') {
            throw new \InvalidArgumentException((string) __('请提供线上站点地址与用户 Token。'));
        }
        if (!preg_match('#^https?://#i', $onlineBaseUrl)) {
            throw new \InvalidArgumentException((string) __('线上站点地址必须是完整 http(s) URL（含子路径）。'));
        }

        $current = $this->status();
        if (!empty($current['running'])) {
            throw new \RuntimeException((string) __('静默 DevRelay worker 已在运行，请先关闭。'));
        }

        $this->clearStopFlag();
        $this->rememberCredentials($onlineBaseUrl, $userToken);
        @mkdir(dirname(BP . self::CRED_FILE), 0775, true);
        @file_put_contents(BP . self::CRED_FILE, json_encode([
            'online_base_url' => $onlineBaseUrl,
            'user_token' => $userToken,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
        @chmod(BP . self::CRED_FILE, 0600);

        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $binW = BP . 'bin/w';
        $cred = BP . self::CRED_FILE;
        $log = BP . self::LOG_FILE;
        @mkdir(dirname($log), 0775, true);

        // HTTP/WLS 会收走请求进程组内子进程。优先经 osascript 在系统侧拉起；
        // AppleScript 入参只用 ASCII 的 /tmp 包装脚本（仓库中文路径放进脚本正文）。
        $execOut = $this->spawnDetachedWorker($php, $binW, $cred, $log);
        $pid = $this->waitForWorkerPid(4.0);
        if ($pid <= 0 || !$this->isProcessAlive($pid)) {
            $execOut .= ' | py=' . $this->spawnViaPython($php, $binW, $cred, $log);
            $pid = $this->waitForWorkerPid(4.0);
        }
        // 再确认一次：避免「刚起就被宿主收走」的假阳性
        if ($pid > 0) {
            usleep(400_000);
            if (!$this->isProcessAlive($pid)) {
                $pid = $this->waitForWorkerPid(2.0);
            }
        }
        if ($pid <= 0 || !$this->isProcessAlive($pid)) {
            throw new \RuntimeException(
                (string) __('DevRelay worker 未能保持运行。')
                . ' spawn=' . trim(preg_replace('/\s+/', ' ', $execOut) ?? $execOut)
                . ' · ' . trim($this->tailLog(180))
            );
        }
        $this->writePid($pid);
        $prev = $this->readState();
        $this->writeState(array_merge($prev, [
            'online_base_url' => $onlineBaseUrl,
            'pid' => $pid,
            'started_at' => date('c'),
            'last_error' => '',
        ]));

        return $this->status();
    }

    private function spawnDetachedWorker(string $php, string $binW, string $cred, string $log): string
    {
        $root = rtrim((string) BP, '/');
        $pidFile = $root . '/' . self::PID_FILE;
        $repoPy = $root . '/var/payment-dev-relay-spawn.py';
        $tmpPy = '/tmp/weline-payment-devrelay-spawn.py';
        $label = 'com.weline.payment-devrelay';

        $py = "import os,subprocess\n"
            . 'os.chdir(' . var_export($root, true) . ")\n"
            . 'log=open(' . var_export($log, true) . ",'a')\n"
            . 'p=subprocess.Popen(['
            . var_export($php, true) . ', '
            . var_export($binW, true) . ', '
            . var_export('payment:devrelay:run', true) . ', '
            . var_export('--cred-file=' . $cred, true)
            . "], stdin=subprocess.DEVNULL, stdout=log, stderr=subprocess.STDOUT, start_new_session=True, close_fds=True)\n"
            . 'open(' . var_export($pidFile, true) . ",'w').write(str(p.pid))\n";
        @file_put_contents($repoPy, $py);
        @file_put_contents($tmpPy, $py);

        $parts = [];

        // 1) launchctl：完全脱离 HTTP/WLS（不探测路径是否 executable：open_basedir 下常误判）
        $label = 'com.weline.payment-devrelay';
        @shell_exec('launchctl remove ' . escapeshellarg($label) . ' 2>/dev/null');
        $submit = 'launchctl submit -l ' . escapeshellarg($label)
            . ' -- ' . escapeshellarg($php) . ' ' . escapeshellarg($binW)
            . ' payment:devrelay:run --cred-file=' . escapeshellarg($cred)
            . ' 2>&1; echo EXIT:$?';
        $launchOut = trim((string) shell_exec($submit));
        $parts[] = 'launchctl=' . $launchOut;
        if (str_contains($launchOut, 'EXIT:0') || $launchOut === '') {
            $pid = $this->waitForWorkerPid(6.0);
            if ($pid > 0) {
                return implode(' | ', $parts);
            }
            // launchctl 已提交但 pid 探测失败时，仍尝试认领 ps/list
            $pid = $this->findWorkerPid();
            if ($pid > 0) {
                $this->writePid($pid);

                return implode(' | ', $parts);
            }
        }

        // 2) osascript + python start_new_session（ASCII /tmp）
        if (is_file('/usr/bin/osascript')) {
            $osa = 'do shell script "python3 /tmp/weline-payment-devrelay-spawn.py"';
            $parts[] = 'osa=' . trim((string) shell_exec('/usr/bin/osascript -e ' . escapeshellarg($osa) . ' ; echo EXIT:$?'));
            if ($this->waitForWorkerPid(4.0) > 0) {
                return implode(' | ', $parts);
            }
        }

        // 3) 直接 python（CLI）
        $parts[] = 'py=' . trim((string) shell_exec('python3 ' . escapeshellarg($tmpPy) . ' ; echo EXIT:$?'));

        return implode(' | ', $parts);
    }

    private function spawnViaPython(string $php, string $binW, string $cred, string $log): string
    {
        $tmpPy = '/tmp/weline-payment-devrelay-spawn.py';
        if (!is_file($tmpPy)) {
            $this->spawnDetachedWorker($php, $binW, $cred, $log);
        }

        return trim((string) shell_exec('python3 ' . escapeshellarg($tmpPy) . ' ; echo EXIT:$?'));
    }

    private function waitForWorkerPid(float $seconds): int
    {
        $deadline = microtime(true) + max(0.2, $seconds);
        $pid = 0;
        do {
            $pid = $this->readPid();
            if ($pid <= 0) {
                $pid = $this->findWorkerPid();
            }
            if ($pid > 0) {
                if ($this->isProcessAlive($pid) || $this->isWorkerCommand($pid)) {
                    return $pid;
                }
                // 刚写入的 pid 文件：即使 posix_kill 在宿主内误判，也先认领
                $pidPath = BP . self::PID_FILE;
                if (is_file($pidPath) && (time() - (int) @filemtime($pidPath)) <= 8) {
                    return $pid;
                }
                if (!\function_exists('posix_kill')) {
                    return $pid;
                }
            }
            usleep(150_000);
        } while (microtime(true) < $deadline);

        $pid = $this->readPid();
        if ($pid > 0 && ($this->isProcessAlive($pid) || !\function_exists('posix_kill'))) {
            return $pid;
        }

        return 0;
    }

    private function findWorkerPid(): int
    {
        $out = (string) shell_exec(
            "ps ax -o pid=,command= | awk '/bin\\/w payment:devrelay:run --cred-file=/ {print \$1; exit}'"
        );

        return (int) trim($out);
    }

    private function isWorkerCommand(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        $cmd = (string) shell_exec('ps -p ' . (int) $pid . ' -o command=');
        if (trim($cmd) === '') {
            // WLS 请求内 ps 可能被限制；进程存活则放行
            return $this->isProcessAlive($pid);
        }

        return str_contains($cmd, 'bin/w payment:devrelay:run --cred-file=');
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(): array
    {
        $this->touchStopFlag();
        @shell_exec('launchctl remove com.weline.payment-devrelay 2>/dev/null');
        $state = $this->readState();
        $pid = $this->readPid();
        if ($pid <= 0) {
            $pid = $this->findWorkerPid();
        }
        if ($pid > 0 && $this->isProcessAlive($pid)) {
            if (\function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
                $deadline = time() + 5;
                while (time() < $deadline && $this->isProcessAlive($pid)) {
                    usleep(100_000);
                }
                if ($this->isProcessAlive($pid)) {
                    @posix_kill($pid, SIGKILL);
                }
            }
        }
        // 再扫一次，避免 launchctl 残留
        $extra = $this->findWorkerPid();
        if ($extra > 0 && $this->isProcessAlive($extra) && \function_exists('posix_kill')) {
            @posix_kill($extra, SIGTERM);
        }
        $this->writePid(0);
        $state['stopped_at'] = date('c');
        $state['pid'] = 0;
        $this->writeState($state);
        // 保留 CRED_FILE / SECRET，便于面板一键再次拉起；勿删除凭证。

        return $this->status();
    }

    public function runFromCredFile(string $credFile): int
    {
        $raw = is_file($credFile) ? (string) file_get_contents($credFile) : '';
        $cred = json_decode($raw, true);
        if (!\is_array($cred)) {
            throw new \InvalidArgumentException('invalid cred file');
        }

        return $this->runBlocking(
            (string) ($cred['online_base_url'] ?? ''),
            (string) ($cred['user_token'] ?? ''),
        );
    }

    public function runBlocking(string $onlineBaseUrl, string $userToken): int
    {
        $onlineBaseUrl = rtrim(trim($onlineBaseUrl), '/');
        $userToken = trim($userToken);
        $this->clearStopFlag();
        $this->writePid(getmypid() ?: 0);
        $this->log('pair starting online=' . $onlineBaseUrl);

        try {
            $pair = $this->httpJson('POST', $onlineBaseUrl . '/payment/dev-relay/pair', [
                'Authorization: Bearer ' . $userToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ], '{}');
            if (empty($pair['success']) || !\is_array($pair['session'] ?? null)) {
                throw new \RuntimeException((string) ($pair['message'] ?? 'pair failed'));
            }
            $session = $pair['session'];
            $sessionCode = (string) ($session['session_code'] ?? '');
            $relayToken = (string) ($session['token'] ?? '');
            $streamUrl = (string) ($session['stream_url'] ?? '');
            $ackUrl = (string) ($session['ack_url'] ?? ($onlineBaseUrl . '/payment/dev-relay/ack'));
            if ($sessionCode === '' || $relayToken === '' || $streamUrl === '') {
                throw new \RuntimeException((string) __('pair 响应缺少 session/stream。'));
            }

            $localInbound = $this->sessions->buildLocalInboundUrl($sessionCode, $relayToken);
            try {
                $this->sessions->bindLocalSession($sessionCode, $relayToken, $streamUrl);
            } catch (\Throwable $bindError) {
                $this->log('local bind skipped: ' . $bindError->getMessage());
            }

            $this->httpJson('POST', $onlineBaseUrl . '/payment/dev-relay/update-inbound', [
                'Authorization: Bearer ' . $userToken,
                'Content-Type: application/json',
            ], json_encode([
                'session_code' => $sessionCode,
                'token' => $relayToken,
                'local_inbound_url' => $localInbound,
            ], JSON_UNESCAPED_SLASHES) ?: '{}');

            $this->writeState([
                'online_base_url' => $onlineBaseUrl,
                'pid' => getmypid() ?: 0,
                'started_at' => date('c'),
                'session_code' => $sessionCode,
                'stream_url' => $streamUrl,
                'local_inbound_url' => $localInbound,
                'ack_url' => $ackUrl,
                'sse_connected' => false,
                'last_error' => '',
                'relayed_ok' => 0,
                'relayed_fail' => 0,
                'recent_events' => [],
            ]);

            $this->log('SSE connecting ' . $streamUrl);
            // 标记即将进入阻塞读；loopback 可再等一小段，但服务端已改为无 Last-Event-ID 时从 0 重放。
            $state = $this->readState();
            $state['sse_connecting_at'] = date('c');
            $this->writeState($state);
            $this->consumeSse($streamUrl, $sessionCode, $relayToken, $localInbound, $ackUrl, $onlineBaseUrl, $userToken);

            return 0;
        } catch (\Throwable $throwable) {
            $this->log('fatal: ' . $throwable->getMessage());
            $state = $this->readState();
            $state['last_error'] = $throwable->getMessage();
            $this->writeState($state);

            return 1;
        } finally {
            $this->writePid(0);
        }
    }

    private function consumeSse(
        string $streamUrl,
        string $sessionCode,
        string $relayToken,
        string $localInbound,
        string $ackUrl,
        string $onlineBaseUrl,
        string $userToken,
    ): void {
        $ch = curl_init($streamUrl);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        $buffer = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_HTTPHEADER => [
                'Accept: text/event-stream',
                'Cache-Control: no-cache',
                'Connection: keep-alive',
            ],
            CURLOPT_TIMEOUT => 0,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (
                &$buffer,
                $sessionCode,
                $relayToken,
                $localInbound,
                $ackUrl,
            ): int {
                if ($this->shouldStop()) {
                    return 0;
                }
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $frame = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    $this->handleSseFrame($frame, $sessionCode, $relayToken, $localInbound, $ackUrl);
                }

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        $this->httpJson('POST', $onlineBaseUrl . '/payment/dev-relay/close', [
            'Authorization: Bearer ' . $userToken,
            'Content-Type: application/json',
        ], json_encode([
            'session_code' => $sessionCode,
            'token' => $relayToken,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');

        if ($this->shouldStop()) {
            $this->log('stopped by flag');

            return;
        }
        if ($ok === false) {
            throw new \RuntimeException('SSE disconnected: ' . $err);
        }
    }

    private function handleSseFrame(
        string $frame,
        string $sessionCode,
        string $relayToken,
        string $localInbound,
        string $ackUrl,
    ): void {
        $event = 'message';
        $dataLines = [];
        foreach (preg_split('/\r\n|\n|\r/', $frame) ?: [] as $line) {
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }
        $data = trim(implode("\n", $dataLines));
        if ($event !== 'webhook.relay' || $data === '') {
            return;
        }
        $payload = json_decode($data, true);
        if (!\is_array($payload)) {
            return;
        }
        $eventCode = (string) ($payload['event_code'] ?? '');
        $fetchUrl = (string) ($payload['fetch_url'] ?? '');
        $inbound = trim((string) ($payload['local_inbound_url'] ?? '')) ?: $localInbound;
        if ($eventCode === '' || $fetchUrl === '' || $inbound === '') {
            return;
        }

        try {
            $this->log('event frame ' . $eventCode);
            $fetched = $this->httpJson('GET', $fetchUrl, ['Accept: application/json'], '', 20);
            if (empty($fetched['success']) || !\is_array($fetched['payload'] ?? null)) {
                throw new \RuntimeException((string) ($fetched['message'] ?? 'fetch failed'));
            }
            $bodyPayload = $fetched['payload'];
            $module = (string) ($bodyPayload['module'] ?? '');
            $inboxCode = (string) ($bodyPayload['inbox_code'] ?? '');
            $isDropship = $module === 'dropship' || str_starts_with($inboxCode, 'dropship:');
            if ($isDropship) {
                $endpoint = (string) ($bodyPayload['endpoint_code'] ?? '');
                $rawB64 = (string) ($bodyPayload['raw_body'] ?? '');
                $raw = base64_decode($rawB64, true);
                if (!\is_string($raw)) {
                    $raw = '';
                }
                $dropshipUrl = $this->resolveDropshipNotifyUrl($inbound, $endpoint);
                $headerLines = ['Content-Type: application/json'];
                foreach (\is_array($bodyPayload['headers'] ?? null) ? $bodyPayload['headers'] : [] as $hk => $hv) {
                    if (!\is_string($hk) || $hk === '' || !\is_scalar($hv)) {
                        continue;
                    }
                    $lower = strtolower($hk);
                    if (\in_array($lower, ['host', 'content-length', 'transfer-encoding', 'connection'], true)) {
                        continue;
                    }
                    $headerLines[] = $hk . ': ' . (string) $hv;
                }
                $replay = $this->httpRaw('POST', $dropshipUrl, $headerLines, $raw, 20);
            } else {
                $replay = $this->httpRaw('POST', $inbound, [
                    'Content-Type: application/json',
                    'X-Dev-Relay-Endpoint: ' . (string) ($bodyPayload['endpoint_code'] ?? ''),
                ], json_encode([
                    'endpoint_code' => $bodyPayload['endpoint_code'] ?? '',
                    'raw_body_b64' => $bodyPayload['raw_body'] ?? '',
                    'headers' => $bodyPayload['headers'] ?? [],
                    'signature' => $bodyPayload['signature'] ?? '',
                ], JSON_UNESCAPED_SLASHES) ?: '{}', 20);
            }
            $ok = $replay['status'] >= 200 && $replay['status'] < 300;
            $this->ack($ackUrl, $sessionCode, $relayToken, $eventCode, $ok, $ok ? '' : $replay['body']);
            $this->recordEvent($eventCode, $ok, $ok ? '' : $replay['body']);
            $this->log(($ok ? 'ok ' : 'fail ') . $eventCode . ' HTTP ' . $replay['status']);
        } catch (\Throwable $throwable) {
            $this->ack($ackUrl, $sessionCode, $relayToken, $eventCode, false, $throwable->getMessage());
            $this->recordEvent($eventCode, false, $throwable->getMessage());
            $this->log('error ' . $eventCode . ' ' . $throwable->getMessage());
        }
    }

    private function ack(
        string $ackUrl,
        string $sessionCode,
        string $relayToken,
        string $eventCode,
        bool $ok,
        string $error,
    ): void {
        $this->httpJson('POST', $ackUrl, ['Content-Type: application/json'], json_encode([
            'session_code' => $sessionCode,
            'token' => $relayToken,
            'event_code' => $eventCode,
            'success' => $ok,
            'error' => $error,
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function recordEvent(string $eventCode, bool $ok, string $error): void
    {
        $state = $this->readState();
        if ($ok) {
            $state['relayed_ok'] = (int) ($state['relayed_ok'] ?? 0) + 1;
        } else {
            $state['relayed_fail'] = (int) ($state['relayed_fail'] ?? 0) + 1;
            $state['last_error'] = $error;
        }
        $state['last_event_at'] = date('c');
        $state['last_event_code'] = $eventCode;
        $recent = \is_array($state['recent_events'] ?? null) ? $state['recent_events'] : [];
        array_unshift($recent, [
            'event_code' => $eventCode,
            'ok' => $ok,
            'error' => $error,
            'at' => date('c'),
        ]);
        $state['recent_events'] = array_slice($recent, 0, 30);
        $this->writeState($state);
    }

    private function resolveDropshipNotifyUrl(string $localInboundOrBase, string $endpointCode): string
    {
        $base = trim($localInboundOrBase);
        if ($base === '') {
            throw new \RuntimeException('local inbound url empty');
        }
        $parts = parse_url($base);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('invalid local inbound url');
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        $q = http_build_query(['endpoint_code' => $endpointCode]);

        return $origin . '/dropship/frontend/callback/notify?' . $q;
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function httpJson(string $method, string $url, array $headers, string $body = '', int $timeout = 60): array
    {
        $raw = $this->httpRaw($method, $url, $headers, $body, $timeout);
        $decoded = json_decode($raw['body'], true);

        return \is_array($decoded) ? $decoded : ['success' => false, 'message' => $raw['body'], 'http_status' => $raw['status']];
    }

    /**
     * @param list<string> $headers
     * @return array{status:int,body:string}
     */
    private function httpRaw(string $method, string $url, array $headers, string $body = '', int $timeout = 60): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(5, $timeout),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ];
        // 本机 *.test.weline.com 自签证书：静默 worker 回放 inbound 必须放行。
        if (preg_match('#^https://[^/]*\\.(?:test\\.weline\\.com|weline\\.test)(?::\\d+)?/#i', $url)
            || preg_match('#^https://127\\.0\\.0\\.1(?::\\d+)?/#i', $url)
            || preg_match('#^https://localhost(?::\\d+)?/#i', $url)) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        if ($body !== '' || strtoupper($method) === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new \RuntimeException($err !== '' ? $err : 'http failed');
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    private function shouldStop(): bool
    {
        return is_file(BP . self::STOP_FILE);
    }

    private function touchStopFlag(): void
    {
        @file_put_contents(BP . self::STOP_FILE, (string) time());
    }

    private function clearStopFlag(): void
    {
        if (is_file(BP . self::STOP_FILE)) {
            @unlink(BP . self::STOP_FILE);
        }
    }

    private function readPid(): int
    {
        $path = BP . self::PID_FILE;
        if (!is_file($path)) {
            return 0;
        }

        return (int) trim((string) file_get_contents($path));
    }

    private function writePid(int $pid): void
    {
        @file_put_contents(BP . self::PID_FILE, (string) $pid);
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        $path = BP . self::STATE_FILE;
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        @mkdir(dirname(BP . self::STATE_FILE), 0775, true);
        @file_put_contents(
            BP . self::STATE_FILE,
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}',
        );
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0 || !\function_exists('posix_kill')) {
            return false;
        }

        return @posix_kill($pid, 0);
    }

    private function log(string $message): void
    {
        @mkdir(dirname(BP . self::LOG_FILE), 0775, true);
        @file_put_contents(
            BP . self::LOG_FILE,
            '[' . date('c') . '] ' . $message . "\n",
            FILE_APPEND,
        );
    }
}
