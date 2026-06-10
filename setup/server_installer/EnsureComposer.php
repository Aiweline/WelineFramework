<?php

declare(strict_types=1);

/**
 * 按 composer.json 的 composer 字段，将 composer.phar 下载到 extend/server/（与 php、pgsql 同目录）。
 * 该目录已在 .gitignore 中，无需纳入版本库；本地可生成 composer 包装脚本以便加入 PATH。
 */
final class EnsureComposer
{
    private const VERSIONS_URL = 'https://getcomposer.org/versions';

    private const MIN_PHAR_SIZE = 500_000;

    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
    }

    public static function serverDir(string $projectRoot): string
    {
        return $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server';
    }

    public static function pharPath(string $projectRoot): string
    {
        return self::serverDir($projectRoot) . DIRECTORY_SEPARATOR . 'composer.phar';
    }

    public static function quotedPharPath(string $projectRoot): ?string
    {
        $path = self::pharPath($projectRoot);
        return is_file($path) ? '"' . $path . '"' : null;
    }

    /**
     * 确保 extend/server/composer.phar 存在且满足版本约束；成功返回 true。
     */
    public function ensure(string $phpBin): bool
    {
        self::registerSessionPath($this->projectRoot);
        $this->bootstrapPublicCaBundle();

        $constraint = $this->readComposerConstraint();
        $phar = self::pharPath($this->projectRoot);
        $constraintLabel = $constraint ?? 'latest-stable';

        echo 'Step 0c: Ensuring composer.phar at extend/server/'
            . ' (constraint: ' . $constraintLabel . ")...\n";

        if ($this->isValidPhar($phar) && $this->pharSatisfiesConstraint($phpBin, $phar, $constraint)) {
            $installedVersion = $this->parsePharVersion($phpBin, $phar);
            echo 'composer.phar already present at ' . $phar;
            if ($installedVersion !== null) {
                echo ' (Composer version ' . $installedVersion . ')';
            }
            echo ". Skipping download.\n";
            $this->ensureWrapperScripts();
            return true;
        }

        if ($this->isValidPhar($phar)) {
            $installedVersion = $this->parsePharVersion($phpBin, $phar);
            echo 'Existing composer.phar does not satisfy constraint ' . $constraintLabel;
            if ($installedVersion !== null) {
                echo ' (current: ' . $installedVersion . ')';
            }
            echo ". Downloading replacement...\n";
        } elseif (is_file($phar)) {
            echo "Existing composer.phar at {$phar} is invalid or too small. Downloading replacement...\n";
        } else {
            echo "composer.phar not found. Downloading...\n";
        }

        $version = $this->resolveDownloadVersion($constraint);
        $url = $version !== null
            ? 'https://getcomposer.org/download/' . $version . '/composer.phar'
            : 'https://getcomposer.org/download/latest-stable/composer.phar';

        $serverDir = self::serverDir($this->projectRoot);
        if (!is_dir($serverDir)) {
            @mkdir($serverDir, 0755, true);
        }

        echo "URL: $url\n";

        $tmpPath = $phar . '.download';
        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }

        if (!$this->download($url, $tmpPath) || !$this->isValidPhar($tmpPath)) {
            @unlink($tmpPath);
            $caHint = $this->resolveCaBundlePath() ?? '(unavailable)';
            fwrite(STDERR, "ERROR: composer.phar download failed.\n");
            fwrite(STDERR, "  URL: {$url}\n");
            fwrite(STDERR, "  CA bundle: {$caHint}\n");
            fwrite(STDERR, "  请检查网络连接；若 php.ini 曾指向其他项目的 _local_ca，请重新运行安装脚本以修复 CA 配置。\n");
            return is_file($phar) && $this->isValidPhar($phar);
        }

        if (is_file($phar)) {
            @unlink($phar);
        }
        if (!@rename($tmpPath, $phar)) {
            @unlink($tmpPath);
            fwrite(STDERR, "WARNING: could not save composer.phar to extend/server/.\n");
            return false;
        }

        $this->ensureWrapperScripts();
        echo "composer.phar installed to $phar\n";
        return true;
    }

    public static function registerSessionPath(string $projectRoot): void
    {
        $dir = self::serverDir($projectRoot);
        if (!is_dir($dir)) {
            return;
        }
        $sep = DIRECTORY_SEPARATOR === '\\' ? ';' : ':';
        $current = getenv('PATH') ?: '';
        if (strpos($current, $dir) === false) {
            putenv('PATH=' . $dir . $sep . $current);
        }
    }

    public static function applyEnvCommand(string $projectRoot, string $phpBin): void
    {
        self::registerSessionPath($projectRoot);
        $phar = self::pharPath($projectRoot);
        if (!is_file($phar)) {
            return;
        }
        putenv('WELINE_COMPOSER_COMMAND=' . $phpBin . ' "' . $phar . '"');
    }

    private function readComposerConstraint(): ?string
    {
        $jsonPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($jsonPath)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            return null;
        }
        $constraint = $data['composer'] ?? null;
        return is_string($constraint) && trim($constraint) !== '' ? trim($constraint) : null;
    }

    private function resolveDownloadVersion(?string $constraint): ?string
    {
        if ($constraint === null) {
            return null;
        }
        if (preg_match('/^[\^~<>=!]*1(\.|$)/', $constraint)) {
            return $this->resolveLatestInMajor(1);
        }
        if (preg_match('/(\d+\.\d+\.\d+)/', $constraint, $match)) {
            return $match[1];
        }
        if (preg_match('/^[\^~]?2\.(\d+)/', $constraint, $match)) {
            return $this->resolveLatestPatchVersion(2, (int) $match[1]);
        }
        if (preg_match('/^[\^~]?2/', $constraint)) {
            return $this->resolveLatestInMajor(2);
        }
        return null;
    }

    private function resolveLatestPatchVersion(int $major, int $minor): ?string
    {
        $prefix = $major . '.' . $minor . '.';
        $candidates = array_filter(
            $this->fetchComposerVersions(),
            static fn (string $version): bool => str_starts_with($version, $prefix)
        );
        if ($candidates === []) {
            return null;
        }
        usort($candidates, 'version_compare');
        return $candidates[array_key_last($candidates)] ?? null;
    }

    private function resolveLatestInMajor(int $major): ?string
    {
        $prefix = $major . '.';
        $candidates = array_filter(
            $this->fetchComposerVersions(),
            static fn (string $version): bool => str_starts_with($version, $prefix)
        );
        if ($candidates === []) {
            return null;
        }
        usort($candidates, 'version_compare');
        return $candidates[array_key_last($candidates)] ?? null;
    }

    /** @return list<string> */
    private function fetchComposerVersions(): array
    {
        $raw = $this->httpGet(self::VERSIONS_URL);
        if ($raw === null) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $versions = [];
        foreach (array_keys($data) as $version) {
            if (is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $versions[] = $version;
            }
        }
        return $versions;
    }

    private function isValidPhar(string $path): bool
    {
        if (!is_file($path) || filesize($path) < self::MIN_PHAR_SIZE) {
            return false;
        }
        $head = @file_get_contents($path, false, null, 0, 32);
        if (!is_string($head) || $head === '') {
            return false;
        }

        return str_starts_with($head, '<?php')
            || str_starts_with($head, '#!/usr/bin/env php')
            || str_starts_with($head, '#!/usr/bin/env');
    }

    private function pharSatisfiesConstraint(string $phpBin, string $phar, ?string $constraint): bool
    {
        if ($constraint === null) {
            return true;
        }
        $version = $this->parsePharVersion($phpBin, $phar);
        if ($version === null) {
            return false;
        }
        if (preg_match('/^[\^~<>=!]*1(\.|$)/', $constraint)) {
            return str_starts_with($version, '1.');
        }
        if (preg_match('/^[\^~]?2\.(\d+)/', $constraint, $match)) {
            if (!preg_match('/^2\.(\d+)\./', $version, $versionMatch)) {
                return false;
            }
            return (int) $versionMatch[1] >= (int) $match[1];
        }
        if (preg_match('/(\d+\.\d+\.\d+)/', $constraint, $match)) {
            return version_compare($version, $match[1], '>=');
        }
        return str_starts_with($version, '2.');
    }

    private function parsePharVersion(string $phpBin, string $phar): ?string
    {
        exec($phpBin . ' "' . $phar . '" --version 2>&1', $output, $code);
        if ($code !== 0 || $output === []) {
            return null;
        }
        if (preg_match('/Composer version (\d+\.\d+\.\d+)/i', implode("\n", $output), $match)) {
            return $match[1];
        }
        return null;
    }

    /** 生成本地 composer 包装脚本（同在 extend/server/，不纳入 Git），便于 Linux/Windows PATH 直接调用 composer */
    private function ensureWrapperScripts(): void
    {
        $dir = self::serverDir($this->projectRoot);
        if (DIRECTORY_SEPARATOR === '\\') {
            $bat = $dir . DIRECTORY_SEPARATOR . 'composer.bat';
            $content = "@echo off\r\nphp \"%~dp0composer.phar\" %*\r\n";
            if (!is_file($bat) || file_get_contents($bat) !== $content) {
                file_put_contents($bat, $content);
            }
            return;
        }

        $wrapper = $dir . DIRECTORY_SEPARATOR . 'composer';
        $content = "#!/usr/bin/env bash\n"
            . 'DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"' . "\n"
            . 'exec php "$DIR/composer.phar" "$@"' . "\n";
        if (!is_file($wrapper) || file_get_contents($wrapper) !== $content) {
            file_put_contents($wrapper, $content);
        }
        @chmod($wrapper, 0755);
    }

    private function bootstrapPublicCaBundle(): void
    {
        $phpDir = self::serverDir($this->projectRoot) . DIRECTORY_SEPARATOR . 'php';
        if (!is_dir($phpDir)) {
            return;
        }
        if (!class_exists(ConfigurePhpIni::class, false)) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConfigurePhpIni.php';
        }
        (new ConfigurePhpIni($this->projectRoot, $phpDir))->ensurePublicCaBundle();
    }

    private function resolveCaBundlePath(): ?string
    {
        $phpDir = self::serverDir($this->projectRoot) . DIRECTORY_SEPARATOR . 'php';
        if (!is_dir($phpDir)) {
            return null;
        }
        if (!class_exists(ConfigurePhpIni::class, false)) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConfigurePhpIni.php';
        }
        $cfg = new ConfigurePhpIni($this->projectRoot, $phpDir);
        $path = $cfg->getCaBundlePath();
        if (is_file($path) && filesize($path) >= 100_000) {
            return $path;
        }
        if ($cfg->ensurePublicCaBundle() && is_file($path)) {
            return $path;
        }

        return null;
    }

    /** @param array<string, mixed> $options */
    private function applyCurlSslOptions($ch, array $options = []): void
    {
        $caPath = $this->resolveCaBundlePath();
        if ($caPath !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caPath);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            return;
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool) ($options['allow_insecure_fallback'] ?? false) ? false : true);
        if (!($options['allow_insecure_fallback'] ?? false)) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }
    }

    private function download(string $url, string $outPath): bool
    {
        if ($this->downloadWithCurl($url, $outPath)) {
            return true;
        }

        $this->bootstrapPublicCaBundle();
        if ($this->downloadWithCurl($url, $outPath)) {
            return true;
        }

        $data = $this->httpGet($url);
        if ($data === null || strlen($data) < self::MIN_PHAR_SIZE) {
            return false;
        }

        return file_put_contents($outPath, $data) !== false;
    }

    private function downloadWithCurl(string $url, string $outPath): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $fp = fopen($outPath, 'w');
        if (!$fp) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FILE => $fp,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (compatible; WelineInstaller/1.0)',
                'Accept: application/octet-stream, */*',
            ],
            CURLOPT_ENCODING => '',
        ]);
        $this->applyCurlSslOptions($ch);
        $ok = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $httpCode !== 200 || !is_file($outPath) || filesize($outPath) < self::MIN_PHAR_SIZE) {
            if ($curlError !== '') {
                fwrite(STDERR, "  curl: {$curlError}\n");
            }
            @unlink($outPath);
            return false;
        }

        return true;
    }

    private function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: Mozilla/5.0 (compatible; WelineInstaller/1.0)',
                    'Accept: */*',
                ],
                CURLOPT_ENCODING => '',
            ]);
            $this->applyCurlSslOptions($ch);
            $data = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($data === false || $httpCode !== 200) {
                return null;
            }
            return (string) $data;
        }

        $caPath = $this->resolveCaBundlePath();
        $sslOptions = ['verify_peer' => true];
        if ($caPath !== null) {
            $sslOptions['cafile'] = $caPath;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 120,
                'header' => "User-Agent: Mozilla/5.0 (compatible; WelineInstaller/1.0)\r\n",
            ],
            'ssl' => $sslOptions,
        ]);
        $data = @file_get_contents($url, false, $ctx);
        return $data === false ? null : (string) $data;
    }
}
