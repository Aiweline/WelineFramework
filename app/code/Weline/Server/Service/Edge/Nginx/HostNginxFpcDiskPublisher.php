<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

/**
 * When host Nginx (宝塔 / 系统) is the public edge, WLS start writes the same
 * page-generation map and html-disk location the managed edge already has,
 * then reloads that Nginx. The map is http-context; the location stays in the
 * server include that already proxies this project to loopback.
 */
final class HostNginxFpcDiskPublisher
{
    public const MAP_BASENAME = '00-wls-fpc-page-gen.conf';

    public const MARK_BEGIN = '# wls-fpc-html-disk begin';

    public const MARK_END = '# wls-fpc-html-disk end';

    /**
     * Project-owned includes for host Nginx. Add these once to the host vhost;
     * future WLS updates only rewrite the project files (root still reloads Nginx).
     *
     * @return array{http:string,server:string}
     */
    public static function projectIncludePaths(string $projectRoot): array
    {
        $dir = \rtrim(\str_replace('\\', '/', $projectRoot), '/')
            . '/var/server/nginx/conf';

        return [
            'http' => $dir . '/host-fpc-http.include.conf',
            'server' => $dir . '/host-fpc-server.include.conf',
        ];
    }

    /**
     * @return array{ok:bool,skipped:bool,reloaded:bool,message:string,notice:string,map:?string,location:?string}
     */
    public function publish(
        string $projectRoot,
        string $publicHost,
        int $upstreamPort,
        ?string $nginxBinary = null,
        ?string $nginxConf = null,
    ): array {
        $target = $this->discover($projectRoot, $publicHost, $upstreamPort, $nginxBinary, $nginxConf);
        if ($target === null) {
            $binary = (new HostNginxDetector($projectRoot))->detectBinary();
            if ($binary !== null) {
                return $this->result(
                    false,
                    false,
                    false,
                    'host nginx server for this public host not found or unreadable',
                    $this->discoveryNotice($projectRoot, $publicHost, $upstreamPort, $binary),
                    null,
                    null,
                );
            }

            return $this->result(false, true, false, 'no host nginx server for this public host', '', null, null);
        }

        return $this->install($target, true);
    }

    /**
     * @return array{ok:bool,skipped:bool,reloaded:bool,message:string,notice:string,map:?string,location:?string}
     */
    public function install(HostNginxFpcTarget $target, bool $reload): array
    {
        $mapPath = \rtrim($target->mapDirectory, '/\\') . DIRECTORY_SEPARATOR . self::MAP_BASENAME;
        $mapBody = self::renderMap();
        $locationBody = self::renderLocationBlock(
            $target->projectRoot,
            $target->upstreamHost,
            $target->upstreamPort,
        );
        $this->writeProjectIncludes($target, $mapBody, $locationBody);
        $originalLocation = $this->readFile($target->locationFile);
        if ($originalLocation === null) {
            return $this->result(false, false, false, 'location conf unreadable', '', null, $target->locationFile);
        }
        $nextLocation = $this->replaceProxiedRootLocation($originalLocation, $locationBody);
        if ($nextLocation === null) {
            return $this->result(false, false, false, 'loopback location / not found', '', null, $target->locationFile);
        }

        $previousMap = $this->readFile($mapPath);
        $mapChanged = $this->writeIfChanged($mapPath, $mapBody);
        if ($mapChanged === false) {
            return $this->result(
                false,
                false,
                false,
                'map conf not writable: ' . $mapPath,
                $this->permissionNotice($target, $mapPath, $target->locationFile),
                $mapPath,
                $target->locationFile,
            );
        }
        $locationChanged = $nextLocation !== $originalLocation;
        if ($locationChanged && \file_put_contents($target->locationFile, $nextLocation) === false) {
            return $this->result(
                false,
                false,
                false,
                'location conf not writable',
                $this->permissionNotice($target, $mapPath, $target->locationFile),
                $mapPath,
                $target->locationFile,
            );
        }
        if ($mapChanged !== true && !$locationChanged) {
            return $this->result(true, false, false, 'unchanged', '', $mapPath, $target->locationFile);
        }
        if (!$reload) {
            return $this->result(true, false, false, 'written', '', $mapPath, $target->locationFile);
        }
        $tested = $this->nginxTest($target->nginxBinary);
        if (!$tested['ok']) {
            $this->restore(
                $mapPath,
                $mapChanged === true,
                $previousMap,
                $target->locationFile,
                $locationChanged ? $originalLocation : null,
            );
            return $this->result(false, false, false, 'nginx -t failed: ' . $tested['message'], '', $mapPath, $target->locationFile);
        }
        $reloaded = $this->nginxReload($target->nginxBinary);
        if (!$reloaded['ok']) {
            return $this->result(
                true,
                false,
                false,
                'written; reload failed: ' . $reloaded['message'],
                $this->reloadNotice($target->nginxBinary, $reloaded['message']),
                $mapPath,
                $target->locationFile,
            );
        }

        return $this->result(true, false, true, 'reloaded', '', $mapPath, $target->locationFile);
    }

    public function discover(
        string $projectRoot,
        string $publicHost,
        int $upstreamPort,
        ?string $nginxBinary = null,
        ?string $nginxConf = null,
    ): ?HostNginxFpcTarget {
        $projectRoot = $this->normalizeDirectory($projectRoot);
        $publicHost = \strtolower(\trim($publicHost));
        if ($projectRoot === null
            || $upstreamPort < 1
            || $upstreamPort > 65535
            || \preg_match('/^[a-z0-9.-]+$/D', $publicHost) !== 1
            || $publicHost === 'localhost'
            || \filter_var($publicHost, FILTER_VALIDATE_IP) !== false
        ) {
            return null;
        }
        $binary = $nginxBinary !== null && $nginxBinary !== ''
            ? $nginxBinary
            : (new HostNginxDetector($projectRoot))->detectBinary();
        if ($binary === null || !\is_file($binary)) {
            return null;
        }
        $confPath = $nginxConf !== null && $nginxConf !== ''
            ? (\is_file($nginxConf) ? $nginxConf : null)
            : $this->nginxConfPath($binary);
        if ($confPath === null) {
            return null;
        }
        $conf = $this->readFile($confPath);
        if ($conf === null) {
            return null;
        }
        $serverFile = null;
        $locationFile = null;
        foreach ($this->httpIncludeFiles($conf, \dirname($confPath)) as $file) {
            $text = $this->readFile($file);
            if ($text === null || !$this->serverNamesContain($text, $publicHost)) {
                continue;
            }
            $serverFile = $file;
            $locationFile = $this->findLoopbackLocationFile($file, $text);
            break;
        }
        if ($serverFile === null || $locationFile === null) {
            return null;
        }

        return new HostNginxFpcTarget(
            $binary,
            \dirname($serverFile),
            $locationFile,
            $projectRoot,
            $publicHost,
            '127.0.0.1',
            $upstreamPort,
        );
    }

    public static function renderMap(): string
    {
        return <<<'NGINX'
# http{} 级。WLS 启动写入。只放 map，不要包 server。
map $arg_w_pg $wls_fpc_page_gen {
    "~^[a-f0-9]{16}$" $arg_w_pg;
    "~.+"             __miss__;
    default           current;
}

map $args $wls_fpc_query_ok {
    default 0;
    "" 1;
    "~^w_pg=[a-f0-9]{16}$" 1;
}

map $http_authorization $wls_fpc_html_bypass_auth {
    default 0;
    "~." 1;
}

map $http_upgrade $wls_fpc_html_bypass_upgrade {
    default 0;
    "~." 1;
}

map $http_accept_encoding $wls_fpc_accepts_br {
    default 0;
    "~*(^|[, ])br($|[;,\s])" 1;
}

map "$scheme:$wls_fpc_html_bypass_auth:$wls_fpc_html_bypass_upgrade:$wls_fpc_accepts_br:$wls_fpc_query_ok" $wls_fpc_html_disk_try {
    default 0;
    "https:0:0:1:1" 1;
}
NGINX;
    }

    public static function renderLocationBlock(string $projectRoot, string $upstreamHost, int $upstreamPort): string
    {
        $root = \rtrim(\str_replace('\\', '/', $projectRoot), '/')
            . '/generated/html/live/$host/pg/$wls_fpc_page_gen';
        $root = \str_replace(['"', "\n", "\r"], '', $root);
        $upstreamHost = $upstreamHost === 'localhost' ? '127.0.0.1' : $upstreamHost;
        if ($upstreamHost !== '127.0.0.1' || $upstreamPort < 1 || $upstreamPort > 65535) {
            throw new \InvalidArgumentException('host nginx FPC upstream must be loopback');
        }

        return self::MARK_BEGIN . "\n"
            . "location / {\n"
            . "    error_page 418 = @wls_html_origin;\n"
            . "    if (\$wls_fpc_html_disk_try = 0) {\n"
            . "        return 418;\n"
            . "    }\n"
            . '    root "' . $root . "\";\n"
            . "    try_files \$uri/index.html.br =418;\n"
            . "    gzip off;\n"
            . "    gzip_vary off;\n"
            . "    default_type text/html;\n"
            . "    charset utf-8;\n"
            . "    add_header Content-Encoding br always;\n"
            . "    add_header Vary \"Accept-Encoding, Cookie\" always;\n"
            . "    add_header Cache-Control \"public, max-age=60, must-revalidate\" always;\n"
            . "    add_header CDN-Cache-Control \"public, max-age=300\" always;\n"
            . "    add_header Cloudflare-CDN-Cache-Control \"public, max-age=300\" always;\n"
            . "    add_header X-Weline-Cache-Status hit always;\n"
            . "    add_header X-Weline-FPC HIT always;\n"
            . "    add_header X-Weline-FPC-Source html-disk always;\n"
            . "}\n"
            . "location @wls_html_origin {\n"
            . "    proxy_pass http://{$upstreamHost}:{$upstreamPort};\n"
            . "    proxy_http_version 1.1;\n"
            . "    proxy_set_header Connection \"\";\n"
            . "    proxy_set_header Host \$host;\n"
            . "    proxy_set_header X-Real-IP \$remote_addr;\n"
            . "    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n"
            . "    proxy_set_header CF-Connecting-IP \$http_cf_connecting_ip;\n"
            . "    proxy_set_header X-Forwarded-Proto \$scheme;\n"
            . "    proxy_set_header X-Forwarded-Port 443;\n"
            . "    proxy_set_header Upgrade \$http_upgrade;\n"
            . "    proxy_connect_timeout 3s;\n"
            . "    proxy_read_timeout 300s;\n"
            . "    proxy_buffering off;\n"
            . "}\n"
            . self::MARK_END . "\n";
    }

    public function replaceProxiedRootLocation(string $conf, string $replacement): ?string
    {
        $begin = \strpos($conf, self::MARK_BEGIN);
        $end = $begin === false ? false : \strpos($conf, self::MARK_END, $begin);
        if ($begin !== false && $end !== false) {
            $end += \strlen(self::MARK_END);
            if (isset($conf[$end]) && ($conf[$end] === "\n" || $conf[$end] === "\r")) {
                $end++;
                if (($conf[$end - 1] ?? '') === "\r" && ($conf[$end] ?? '') === "\n") {
                    $end++;
                }
            }
            return \substr($conf, 0, $begin) . $replacement . \substr($conf, $end);
        }
        if (\preg_match('/location\s+\/\s*\{/', $conf, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = (int)$match[0][1];
        $open = \strpos($conf, '{', $start);
        if ($open === false) {
            return null;
        }
        $close = $this->matchingBrace($conf, $open);
        if ($close === null) {
            return null;
        }
        $block = \substr($conf, $start, $close - $start + 1);
        if (\preg_match('/proxy_pass\s+https?:\/\/(?:127\.0\.0\.1|\[::1\]|localhost)(?::\d+)?/i', $block) !== 1) {
            return null;
        }
        $tail = $close + 1;
        if (\preg_match('/\s*location\s+@wls_html_origin\s*\{/', $conf, $named, 0, $tail) === 1) {
            $namedOpen = \strpos($conf, '{', $tail);
            $namedClose = $namedOpen === false ? null : $this->matchingBrace($conf, $namedOpen);
            if ($namedClose !== null) {
                $close = $namedClose;
            }
        }

        return \substr($conf, 0, $start) . $replacement . \substr($conf, $close + 1);
    }

    /**
     * @return list<string>
     */
    public function httpIncludeFiles(string $nginxConf, string $confDirectory): array
    {
        $files = [];
        foreach ($this->httpIncludePatterns($nginxConf, $confDirectory) as $pattern) {
            $matched = \glob($pattern);
            if (!\is_array($matched)) {
                continue;
            }
            foreach ($matched as $file) {
                if (\is_file($file)) {
                    $files[] = $file;
                }
            }
        }
        \sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function httpIncludePatterns(string $nginxConf, string $confDirectory): array
    {
        $depth = 0;
        $httpDepth = null;
        $serverDepth = null;
        $patterns = [];
        $pending = '';
        $length = \strlen($nginxConf);
        $i = 0;
        while ($i < $length) {
            $char = $nginxConf[$i];
            if ($char === '#') {
                while ($i < $length && $nginxConf[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($char === '{') {
                $name = \strtolower($pending);
                if ($httpDepth === null && \preg_match('/(?:^|\s)http$/', $name) === 1) {
                    $httpDepth = $depth + 1;
                }
                if ($httpDepth !== null && $serverDepth === null && \preg_match('/(?:^|\s)server$/', $name) === 1) {
                    $serverDepth = $depth + 1;
                }
                $depth++;
                $pending = '';
                $i++;
                continue;
            }
            if ($char === '}') {
                if ($serverDepth !== null && $depth === $serverDepth) {
                    $serverDepth = null;
                }
                if ($httpDepth !== null && $depth === $httpDepth) {
                    $httpDepth = null;
                }
                if ($depth > 0) {
                    $depth--;
                }
                $pending = '';
                $i++;
                continue;
            }
            if ($httpDepth !== null && $depth === $httpDepth && $serverDepth === null
                && \preg_match('/\Ginclude\s+/A', $nginxConf, $include, 0, $i) === 1
            ) {
                $start = $i + \strlen($include[0]);
                $end = \strpos($nginxConf, ';', $start);
                if ($end === false) {
                    break;
                }
                $pattern = \trim(\substr($nginxConf, $start, $end - $start), " \t'\"");
                if ($pattern !== '' && !\str_starts_with($pattern, '/')) {
                    $pattern = \rtrim($confDirectory, '/\\') . '/' . $pattern;
                }
                if ($pattern !== '') {
                    $patterns[] = $pattern;
                }
                $i = $end + 1;
                $pending = '';
                continue;
            }
            if (\ctype_space($char) || $char === ';') {
                $i++;
                continue;
            }
            $pending .= $char;
            $i++;
        }

        return $patterns;
    }

    private function findLoopbackLocationFile(string $serverFile, string $serverText): ?string
    {
        $candidates = [$serverFile];
        $directory = \dirname($serverFile);
        if (\preg_match_all('/\binclude\s+([^;]+);/', $serverText, $includes) > 0) {
            foreach ($includes[1] as $raw) {
                $pattern = \trim($raw, " \t'\"");
                if ($pattern === '') {
                    continue;
                }
                if (!\str_starts_with($pattern, '/')) {
                    $pattern = $directory . '/' . $pattern;
                }
                $matched = \glob($pattern);
                if (!\is_array($matched)) {
                    continue;
                }
                foreach ($matched as $file) {
                    if (\is_file($file)) {
                        $candidates[] = $file;
                    }
                }
            }
        }
        foreach ($candidates as $file) {
            if ($file === $serverFile) {
                $text = $serverText;
            } else {
                $text = $this->readFile($file);
            }
            if ($text !== null && $this->replaceProxiedRootLocation($text, self::MARK_BEGIN) !== null) {
                return $file;
            }
        }

        return null;
    }

    private function serverNamesContain(string $conf, string $host): bool
    {
        if (\preg_match('/\bserver_name\s+([^;]+);/', $conf, $match) !== 1) {
            return false;
        }
        $names = \preg_split('/\s+/', \strtolower(\trim($match[1]))) ?: [];

        return \in_array($host, $names, true);
    }

    private function matchingBrace(string $conf, int $open): ?int
    {
        $depth = 0;
        $length = \strlen($conf);
        for ($i = $open; $i < $length; $i++) {
            if ($conf[$i] === '{') {
                $depth++;
                continue;
            }
            if ($conf[$i] !== '}') {
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }

        return null;
    }

    private function writeIfChanged(string $path, string $body): ?bool
    {
        $current = $this->readFile($path);
        if ($current === $body) {
            return null;
        }
        $directory = \dirname($path);
        if (!\is_dir($directory)) {
            return false;
        }
        if ($current === null && !\is_writable($directory)) {
            return false;
        }
        if ($current !== null && !\is_writable($path)) {
            return false;
        }
        if (\file_put_contents($path, $body) === false) {
            return false;
        }

        return true;
    }

    private function restore(
        string $mapPath,
        bool $mapWritten,
        ?string $previousMap,
        string $locationFile,
        ?string $originalLocation,
    ): void {
        if ($mapWritten && $previousMap === null && \is_file($mapPath)) {
            \unlink($mapPath);
        } elseif ($mapWritten && $previousMap !== null) {
            \file_put_contents($mapPath, $previousMap);
        }
        if ($originalLocation !== null) {
            \file_put_contents($locationFile, $originalLocation);
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function nginxTest(string $binary): array
    {
        return $this->run($binary, ['-t']);
    }

    /**
     * @return array{ok:bool,message:string}
     */
    private function nginxReload(string $binary): array
    {
        return $this->run($binary, ['-s', 'reload']);
    }

    /**
     * @param list<string> $args
     * @return array{ok:bool,message:string}
     */
    private function run(string $binary, array $args): array
    {
        $command = \array_merge([$binary], $args);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = \proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            return ['ok' => false, 'message' => 'proc_open failed'];
        }
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($process);
        $message = \trim(($stderr !== false ? $stderr : '') . "\n" . ($stdout !== false ? $stdout : ''));

        return ['ok' => $code === 0, 'message' => $message !== '' ? $message : 'exit ' . $code];
    }

    private function nginxConfPath(string $binary): ?string
    {
        $ran = $this->run($binary, ['-V']);
        $text = $ran['message'];
        if (\preg_match('/--conf-path=(\S+)/', $text, $match) !== 1) {
            return null;
        }
        $path = $match[1];

        return \is_file($path) ? $path : null;
    }

    private function readFile(string $path): ?string
    {
        if (!\is_file($path)) {
            return null;
        }
        $raw = \file_get_contents($path);

        return \is_string($raw) ? $raw : null;
    }

    private function normalizeDirectory(string $path): ?string
    {
        $path = \trim($path);
        if ($path === '' || !\is_dir($path)) {
            return null;
        }
        $real = \realpath($path);

        return \is_string($real) ? $real : null;
    }

    private function writeProjectIncludes(
        HostNginxFpcTarget $target,
        string $mapBody,
        string $locationBody,
    ): void {
        $paths = self::projectIncludePaths($target->projectRoot);
        $dir = \dirname($paths['http']);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return;
        }
        @\file_put_contents($paths['http'], $mapBody);
        @\file_put_contents($paths['server'], $locationBody);
    }

    /**
     * Startup text when a host Nginx exists but its vhost/config cannot be found
     * or read by this process (BaoTa / third-party / no permission).
     */
    public function discoveryNotice(
        string $projectRoot,
        string $publicHost,
        int $upstreamPort,
        string $binary,
    ): string {
        $paths = self::projectIncludePaths($projectRoot);

        return "检测到宿主 Nginx（{$binary}），但没找到/读不到 {$publicHost} 的 server 配置。\n"
            . "请用 root（或宿主 Nginx 配置属主）执行：\n"
            . '  sudo bash ' . \rtrim(\str_replace('\\', '/', $projectRoot), '/') . '/bin/wls-host-nginx-fpc'
            . ' --host=' . $publicHost . ' -p=' . $upstreamPort . "\n"
            . "或在宿主 Nginx 中确认：\n"
            . "1. server_name 包含 {$publicHost}；\n"
            . "2. http {} 引入：{$paths['http']}\n"
            . "3. server {} 引入：{$paths['server']}\n"
            . "4. location / 反代到 127.0.0.1:{$upstreamPort}，或直接用 include 文件里的 location。";
    }

    /**
     * Startup text when this process cannot write the host Nginx files.
     * Names the files and the root command that writes them.
     */
    public function permissionNotice(HostNginxFpcTarget $target, string $mapPath, string $locationFile): string
    {
        $root = \rtrim(\str_replace('\\', '/', $target->projectRoot), '/')
            . '/generated/html/live/$host/pg/$wls_fpc_page_gen';

        return "Nginx 还没按请求世代读磁盘：当前用户无权写入宿主 Nginx 配置。\n"
            . '写不进：' . $mapPath . "\n"
            . '站点片段：' . $locationFile . "\n"
            . "启动后 Nginx 仍会把页面反代给 127.0.0.1:{$target->upstreamPort}。\n"
            . "还要交给 Nginx 的配置：\n"
            . '1. http 里要有 ' . $mapPath . "\n"
            . "   w_pg 为 16 位十六进制时只读 pg/该值；没有 w_pg 时读 pg/current。\n"
            . "2. 已经反代到 127.0.0.1:{$target->upstreamPort} 的 location / 要改成\n"
            . '   root "' . $root . "\";\n"
            . "   这个文件不存在再回 127.0.0.1:{$target->upstreamPort}。\n"
            . "用有写权限的用户执行（root/配置属主）：\n"
            . '  sudo bash ' . \rtrim(\str_replace('\\', '/', $target->projectRoot), '/') . '/bin/wls-host-nginx-fpc'
            . ' --host=' . $target->publicHost . ' -p=' . $target->upstreamPort . "\n"
            . "或直接跑命令：\n"
            . "  sudo php bin/w server:nginx:host-fpc --host={$target->publicHost} -p={$target->upstreamPort}\n"
            . "或直接 include 本项目文件（以后更新无需再改宿主配置）：\n"
            . '  http { include ' . self::projectIncludePaths($target->projectRoot)['http'] . "; }\n"
            . '  server { include ' . self::projectIncludePaths($target->projectRoot)['server'] . "; }";
    }

    private function reloadNotice(string $binary, string $detail): string
    {
        return "FPC 世代配置已写入，但 Nginx 没有 reload。\n"
            . '命令：' . $binary . " -s reload\n"
            . $detail;
    }

    /**
     * @return array{ok:bool,skipped:bool,reloaded:bool,message:string,notice:string,map:?string,location:?string}
     */
    private function result(
        bool $ok,
        bool $skipped,
        bool $reloaded,
        string $message,
        string $notice,
        ?string $map,
        ?string $location,
    ): array {
        return [
            'ok' => $ok,
            'skipped' => $skipped,
            'reloaded' => $reloaded,
            'message' => $message,
            'notice' => $notice,
            'map' => $map,
            'location' => $location,
        ];
    }
}
