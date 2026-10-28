<?php
declare(strict_types=1);

namespace Weline\App\Service\Build;

use Weline\App\Model\AppArtifact;

class FlutterBuilder
{
    public function buildWeb(array $cfg, string $buildId, AppArtifact $artifact): array
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('proc_open 不可用，无法执行 flutter build');
        }
        if (!is_dir(VAR_DIR)) {
            throw new \RuntimeException('VAR_DIR 不存在');
        }
        $workDir = sys_get_temp_dir() . '/weline-app-' . $buildId;
        $this->assertSafeWorkDir($workDir);
        if (!is_dir($workDir)) {
            @mkdir($workDir, 0755, true);
        }
        $this->copyTemplate($workDir, $cfg);
        $this->runFlutterBuild($workDir, $cfg);
        $result = $this->collect($workDir, $cfg, $buildId);
        $this->stampArtifact($artifact, $result);
        $this->cleanup($workDir);
        return $result;
    }

    /**
     * Android 产物：flutter build apk --release → app-release.apk 落在产物目录 as app.apk。
     * 与 web 同一套配置渲染/登记/盖章链路，客户端版本检测走 version 接口。
     */
    public function buildAndroid(array $cfg, string $buildId, AppArtifact $artifact): array
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('proc_open 不可用，无法执行 flutter build');
        }
        if (!is_dir(VAR_DIR)) {
            throw new \RuntimeException('VAR_DIR 不存在');
        }
        $workDir = sys_get_temp_dir() . '/weline-app-' . $buildId;
        $this->assertSafeWorkDir($workDir);
        if (!is_dir($workDir)) {
            @mkdir($workDir, 0755, true);
        }
        $this->copyTemplate($workDir, $cfg);
        $this->runFlutterBuild($workDir, $cfg, 'android');
        $result = $this->collectApk($workDir, $cfg, $buildId);
        $this->stampArtifact($artifact, $result);
        $this->cleanup($workDir);

        return $result;
    }

    /**
     * iOS 产物：flutter build ios --release --no-codesign → Payload/Runner.app 打成未签名 app.ipa。
     * 不经 App Store：用户用自己的 Apple ID 自签侧载（Sideloadly/AltStore 等）。
     */
    public function buildIos(array $cfg, string $buildId, AppArtifact $artifact): array
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('proc_open 不可用，无法执行 flutter build');
        }
        if (!is_dir(VAR_DIR)) {
            throw new \RuntimeException('VAR_DIR 不存在');
        }
        $workDir = sys_get_temp_dir() . '/weline-app-' . $buildId;
        $this->assertSafeWorkDir($workDir);
        if (!is_dir($workDir)) {
            @mkdir($workDir, 0755, true);
        }
        $this->copyTemplate($workDir, $cfg);
        $this->runFlutterBuild($workDir, $cfg, 'ios');
        $result = $this->collectIpa($workDir, $cfg, $buildId);
        $this->stampArtifact($artifact, $result);
        $this->cleanup($workDir);

        return $result;
    }

    private function assertSafeWorkDir(string $dir): void
    {
        $base = realpath(sys_get_temp_dir());
        $real = realpath(dirname($dir));
        if ($base === false || $real === false || !str_starts_with($real, $base)) {
            throw new \RuntimeException('非法工作目录：' . $dir);
        }
    }

    private function copyTemplate(string $dir, array $cfg): void
    {
        $source = BP . 'app/code/Weline/App/source/app-template';
        if (!is_dir($source)) {
            w_log_error('FlutterBuilder 模板缺失，回退程序化 scaffold：' . $source);
            $this->legacyScaffold($dir, $cfg);
            return;
        }
        $skip = ['.dart_tool', 'build', '.git'];
        $inner = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $item) use ($skip, $source): bool {
                $rel = substr($item->getPathname(), strlen($source) + 1);
                $first = explode(DIRECTORY_SEPARATOR, $rel)[0];
                return !in_array($first, $skip, true);
            }
        );
        $it = new \RecursiveIteratorIterator($inner, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $rel = substr($item->getPathname(), strlen($source) + 1);
            $dest = $dir . DIRECTORY_SEPARATOR . $rel;
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    @mkdir($dest, 0755, true);
                }
                continue;
            }
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0755, true);
            }
            if (is_file($dest)) {
                @unlink($dest);
            }
            copy($item->getPathname(), $dest);
        }
        if (!is_file($dir . '/pubspec.yaml')) {
            w_log_error('FlutterBuilder 模板复制后缺 pubspec.yaml，回退程序化 scaffold');
            $this->legacyScaffold($dir, $cfg);
        }
        $this->applyPubspecVersion($dir, $cfg);
    }

    /**
     * 把站点版本写进 pubspec（Info.plist 的 CFBundleShortVersionString 取这里）。
     * Flutter 版本必须带 build number：x.y.z+N。
     */
    private function applyPubspecVersion(string $dir, array $cfg): void
    {
        $file = $dir . '/pubspec.yaml';
        if (!is_file($file)) {
            return;
        }
        $version = \trim((string)($cfg['version'] ?? ''));
        if ($version === '' || \preg_match('/^\d+\.\d+\.\d+/', $version) !== 1) {
            return;
        }
        if (!\str_contains($version, '+')) {
            $version .= '+' . \max(1, (int)($cfg['website_id'] ?? 0) + 1);
        }
        $yaml = (string)file_get_contents($file);
        $patched = (string)\preg_replace('/^version:\s*\S+\s*$/m', 'version: ' . $version, $yaml, 1);
        if ($patched !== '' && $patched !== $yaml) {
            file_put_contents($file, $patched);
        }
    }

    private function legacyScaffold(string $dir, array $cfg): void
    {
        $source = BP . 'app/code/Weline/App/source/app-template';
        if (!is_dir($source)) {
            throw new \RuntimeException('模板缺失');
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $rel = substr($item->getPathname(), strlen($source) + 1);
            if ($rel === '.dart_tool' || $rel === 'build' || $rel === 'README.md' || $rel === '对接说明.md') {
                continue;
            }
            $dest = $dir . DS . $rel;
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    @mkdir($dest, 0755, true);
                }
                continue;
            }
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0755, true);
            }
            copy($item->getPathname(), $dest);
        }
        if (!is_file($dir . '/pubspec.yaml') || !is_dir($dir . '/lib')) {
            throw new \RuntimeException('模板缺失');
        }
    }

    private function runFlutterBuild(string $dir, array $cfg, string $target = 'web'): void
    {
        $flutter = trim((string)shell_exec('which flutter 2>/dev/null')) ?: 'flutter';
        $command = match ($target) {
            'android' => 'apk',
            'ios' => 'ios --no-codesign',
            default => 'web',
        };
        $cmd = $flutter . ' build ' . $command . ' --release --no-tree-shake-icons';
        // BASE_URL 必传：本机协助编译必须显式给线上域名（--base-url），禁止把本机测试 Host 打进包
        $baseUrl = $this->resolveBaseUrl($cfg);
        $siteHosts = $this->siteHosts($baseUrl);
        $cmd .= ' --dart-define=WELINE_BASE_URL=' . escapeshellarg($baseUrl);
        if ($siteHosts !== '') {
            $cmd .= ' --dart-define=WELINE_SITE_HOSTS=' . escapeshellarg($siteHosts);
        }
        $cmd .= ' --dart-define=WELINE_APP_NAME=' . escapeshellarg((string)($cfg['app_name'] ?? ''))
            . ' --dart-define=WELINE_APP_VERSION=' . escapeshellarg((string)($cfg['version'] ?? ''))
            . ' 2>&1';
        // 镜像注入：cn 环境走国内镜像；env 在父环境基础上追加（proc_open 传自定义数组会整体替换，丢 PATH/HOME 导致 flutter 崩）
        $env = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && $k !== 'argv' && $k !== 'argc') {
                $env[$k] = $v;
            }
        }
        foreach (['PATH', 'HOME', 'USER', 'SHELL', 'TMPDIR'] as $must) {
            if (!isset($env[$must]) && ($v = getenv($must)) !== false) {
                $env[$must] = $v;
            }
        }
        // Android 构建要 JDK + SDK（gradle 读这两个变量）
        foreach (['JAVA_HOME', 'ANDROID_HOME', 'ANDROID_SDK_ROOT'] as $androidEnv) {
            $v = getenv($androidEnv);
            if (is_string($v) && $v !== '') {
                $env[$androidEnv] = $v;
            }
        }
        $probe = (new \Weline\App\Service\Toolchain\EnvProbe())->probe();
        if (($probe['network']['mirror'] ?? 'direct') === 'cn') {
            $env['FLUTTER_STORAGE_BASE_URL'] = 'https://storage.flutter-io.cn';
            $env['PUB_HOSTED_URL'] = 'https://pub.flutter-io.cn';
        }
        $spec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $spec, $pipes, $dir, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('flutter build 进程启动失败');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new \RuntimeException('flutter build 退出码 ' . $exit . '：' . substr($stdout . $stderr, -500));
        }
        $produced = match ($target) {
            'android' => $dir . '/build/app/outputs/flutter-apk/app-release.apk',
            'ios' => $dir . '/build/ios/iphoneos/Runner.app',
            default => $dir . '/build/web',
        };
        if (!file_exists($produced)) {
            throw new \RuntimeException('flutter build 未产出：' . $produced);
        }
    }

    /**
     * 编译期站点基址：必须是 http(s) 绝对地址。本机协助编译时由 --base-url 指定线上域名；
     * 拿不到就拒绝构建（禁止把本机测试 Host 打进包）。
     */
    private function resolveBaseUrl(array $cfg): string
    {
        $baseUrl = \rtrim(\trim((string)($cfg['base_url'] ?? $cfg['domain'] ?? '')), '/');
        if ($baseUrl === '' || \preg_match('#^https?://[^/\s]+#i', $baseUrl) !== 1) {
            throw new \RuntimeException(
                '缺少站点地址：本机协助编译必须显式指定线上域名，例：'
                . 'php bin/w app:build --website=550 --platform=android --base-url=https://www.example.com'
            );
        }

        return $baseUrl;
    }

    /**
     * App 壳内「同站」域名清单（含裸域），否则站内绝对链接会被当成外链跳系统浏览器。
     */
    private function siteHosts(string $baseUrl): string
    {
        $host = \strtolower(\trim((string)\parse_url($baseUrl, PHP_URL_HOST)));
        if ($host === '') {
            return '';
        }
        $hosts = [$host];
        $bare = \preg_replace('#^www\.#i', '', $host);
        if (\is_string($bare) && $bare !== '' && $bare !== $host) {
            $hosts[] = $bare;
        }

        return \implode(',', $hosts);
    }

    /**
     * 构建期站点上下文：写进 build.json，供上传后在目标机 app:artifact:import 核对打的是哪个站。
     *
     * @return array<string, mixed>
     */
    private function siteStamp(array $cfg): array
    {
        $baseUrl = \rtrim(\trim((string)($cfg['base_url'] ?? $cfg['domain'] ?? '')), '/');

        return [
            'base_url' => $baseUrl,
            'site_hosts' => $baseUrl === '' ? '' : $this->siteHosts($baseUrl),
            'app_name' => (string)($cfg['app_name'] ?? ''),
            'website_id' => (int)($cfg['website_id'] ?? 0),
        ];
    }

    private function collect(string $workDir, array $cfg, string $buildId): array
    {
        $artifactRoot = PUB . 'static' . DS . 'weline-app' . DS . $buildId;
        if (!is_dir($artifactRoot)) {
            @mkdir($artifactRoot, 0755, true);
        }
        $src = $workDir . '/build/web';
        $hashCtx = hash_init('sha256');
        $total = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($src) + 1);
            $dest = $artifactRoot . DS . $rel;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0755, true);
            }
            copy($file->getPathname(), $dest);
            if ($rel === 'index.html') {
                // index.html 会被框架路由兜底拦截（404），落一份 entry.html 别名供 302 直达
                $entry = dirname($dest) . DS . 'entry.html';
                // Web 产物只作「在线商店」入口：Flutter web 壳没有商品数据（空壳页面会被当成原型），
                // 直接跳到店面；native 端的版本更新走 version 接口，与这里无关。
                $redirect = '<!doctype html><html lang="zh-Hans"><head><meta charset="utf-8">'
                    . '<meta name="robots" content="noindex"><meta name="viewport" content="width=device-width, initial-scale=1">'
                    . '<meta http-equiv="refresh" content="0;url=/"><title>正在打开在线商店…</title></head>'
                    . '<body><p>正在打开在线商店…<a href="/">继续</a></p>'
                    . '<script>location.replace("/");</script></body></html>';
                file_put_contents($dest, $redirect);
                file_put_contents($entry, $redirect);
            }
            $bytes = filesize($dest) ?: 0;
            $total += $bytes;
            hash_update($hashCtx, $rel . ':' . hash_file('sha256', $dest) . "\n");
        }
        $sha256 = hash_final($hashCtx);
        $stamp = [
            ...$this->siteStamp($cfg),
            'build_id' => $buildId,
            'platform' => 'web',
            'version' => (string)$cfg['version'],
            'build_ts' => time(),
            'sha256' => $sha256,
            'size_bytes' => $total,
        ];
        // L7：构建期戳，只在此处生成
        file_put_contents($artifactRoot . DS . 'build.json', json_encode($stamp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return [
            'path' => 'static/weline-app/' . $buildId,
            'sha256' => $sha256,
            'size' => $total,
            'build_ts' => $stamp['build_ts'],
        ];
    }

    private function collectIpa(string $workDir, array $cfg, string $buildId): array
    {
        $artifactRoot = PUB . 'static' . DS . 'weline-app' . DS . $buildId;
        if (!is_dir($artifactRoot)) {
            @mkdir($artifactRoot, 0755, true);
        }
        $app = $workDir . '/build/ios/iphoneos/Runner.app';
        if (!is_dir($app)) {
            throw new \RuntimeException('Runner.app 未产出');
        }
        // IPA = Payload/Runner.app 的 zip；用 ditto 保留符号链接与权限
        $payload = $workDir . '/build/ipa/Payload';
        @mkdir($payload, 0755, true);
        $dest = $artifactRoot . DS . 'app.ipa';
        @unlink($dest);
        $copy = 'ditto ' . escapeshellarg($app) . ' ' . escapeshellarg($payload . '/Runner.app');
        exec($copy . ' 2>&1', $copyOut, $copyExit);
        if ($copyExit !== 0) {
            throw new \RuntimeException('打包 Payload 失败：' . implode("\n", $copyOut));
        }
        $zip = 'cd ' . escapeshellarg($workDir . '/build/ipa') . ' && zip -qry ' . escapeshellarg($dest) . ' Payload';
        exec($zip . ' 2>&1', $zipOut, $zipExit);
        if ($zipExit !== 0 || !is_file($dest)) {
            throw new \RuntimeException('生成 app.ipa 失败：' . implode("\n", $zipOut));
        }
        $sha256 = (string)hash_file('sha256', $dest);
        $total = filesize($dest) ?: 0;
        $stamp = [
            ...$this->siteStamp($cfg),
            'build_id' => $buildId,
            'platform' => 'ios',
            'version' => (string)$cfg['version'],
            'build_ts' => time(),
            'sha256' => $sha256,
            'size_bytes' => $total,
            'signed' => false,
        ];
        file_put_contents($artifactRoot . DS . 'build.json', json_encode($stamp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return [
            'path' => 'static/weline-app/' . $buildId,
            'sha256' => $sha256,
            'size' => $total,
            'build_ts' => $stamp['build_ts'],
        ];
    }

    private function stampArtifact(AppArtifact $artifact, array $result): void
    {
        // L7：回写由构建器完成（同请求内 artifact 实例即登记行），字段全取构建期结果
        $artifact->setData(AppArtifact::schema_fields_sha256, $result['sha256']);
        $artifact->setData(AppArtifact::schema_fields_size_bytes, $result['size']);
        $artifact->setData(AppArtifact::schema_fields_build_ts, $result['build_ts']);
        $artifact->setData(AppArtifact::schema_fields_status, AppArtifact::STATUS_RELEASED);
        $artifact->setData(AppArtifact::schema_fields_updated_at, date('Y-m-d H:i:s'));
        $artifact->save();
    }

    private function cleanup(string $dir): void
    {
        if (!str_contains($dir, '/weline-app-')) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
    private function collectApk(string $workDir, array $cfg, string $buildId): array
    {
        $artifactRoot = PUB . 'static' . DS . 'weline-app' . DS . $buildId;
        if (!is_dir($artifactRoot)) {
            @mkdir($artifactRoot, 0755, true);
        }
        $apk = $workDir . '/build/app/outputs/flutter-apk/app-release.apk';
        if (!is_file($apk)) {
            throw new \RuntimeException('app-release.apk 未产出');
        }
        $dest = $artifactRoot . DS . 'app.apk';
        copy($apk, $dest);
        $sha256 = (string)hash_file('sha256', $dest);
        $total = filesize($dest) ?: 0;
        $stamp = [
            ...$this->siteStamp($cfg),
            'build_id' => $buildId,
            'platform' => 'android',
            'version' => (string)$cfg['version'],
            'build_ts' => time(),
            'sha256' => $sha256,
            'size_bytes' => $total,
        ];
        file_put_contents($artifactRoot . DS . 'build.json', json_encode($stamp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return [
            'path' => 'static/weline-app/' . $buildId,
            'sha256' => $sha256,
            'size' => $total,
            'build_ts' => $stamp['build_ts'],
        ];
    }
}
