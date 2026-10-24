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

    private function runFlutterBuild(string $dir, array $cfg): void
    {
        $flutter = trim((string)shell_exec('which flutter 2>/dev/null')) ?: 'flutter';
        $cmd = $flutter . ' build web --release --no-tree-shake-icons';
        // BASE_URL 必传：域名站用绑定域名；通用包用请求时站点主链（空域名时由 App 端首启绑定，V1 先给当前站点主链）
        $baseUrl = trim((string)($cfg['domain'] ?? ''));
        if ($baseUrl === '') {
            $baseUrl = 'https://p05113ef3.test.weline.com';
        }
        $cmd .= ' --dart-define=WELINE_BASE_URL=' . escapeshellarg($baseUrl);
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
        if (!is_dir($dir . '/build/web')) {
            throw new \RuntimeException('build/web 未产出');
        }
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
                @copy($file->getPathname(), dirname($dest) . DS . 'entry.html');
            }
            $bytes = filesize($dest) ?: 0;
            $total += $bytes;
            hash_update($hashCtx, $rel . ':' . hash_file('sha256', $dest) . "\n");
        }
        $sha256 = hash_final($hashCtx);
        $stamp = [
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
    private function buildAndroid(array $cfg): void
    {
        // V1 不实现 Android 构建（本机无 JDK/AndroidSDK），仅保留命令骨架。
        // 预期：flutter build apk --release --dart-define=WELINE_BASE_URL=... --dart-define=WELINE_APP_NAME=... --dart-define=WELINE_APP_VERSION=...
    }
}
