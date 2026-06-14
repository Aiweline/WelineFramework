<?php

declare(strict_types=1);

/**
 * 由 run.php 负责安装 PostgreSQL：若 extend/server/pgsql 不存在则按环境下载或调用包管理器安装。
 *
 * 若后续增加 PostgreSQL 实例初始化（initdb、启动服务、设置 postgres 超管密码），须读取 weline.env 的
 * PGSQL_INIT_USER / PGSQL_INIT_PASSWORD 并用该信息初始化，以便 SetupPgsqlDatabase 能以超管身份连接并创建 DB_* 用户与库。
 */
final class EnsurePgsql
{
    private string $projectRoot;
    private string $serverDir;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = $projectRoot;
        $this->serverDir = $projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server';
    }

    /**
     * 若 extend/server/pgsql 已有 psql 则返回 true；否则尝试安装并返回是否成功。
     */
    public function ensure(array $env): bool
    {
        $pgsqlDir = $this->serverDir . DIRECTORY_SEPARATOR . 'pgsql';
        $psqlExe = (DIRECTORY_SEPARATOR === '\\')
            ? $pgsqlDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'psql.exe'
            : $pgsqlDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'psql';
        if ($this->hasValidPgsqlInstall($pgsqlDir)) {
            return true;
        }
        if (is_file($psqlExe)) {
            echo "PostgreSQL binary set is incomplete or invalid; reinstalling project PostgreSQL binaries...\n";
        }

        $version = trim($env['INSTALL_PGSQL_VERSION'] ?? '16');
        if (DIRECTORY_SEPARATOR === '\\') {
            return $this->installWindows($pgsqlDir, $version);
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            return $this->installMac($version);
        }
        return $this->installLinux($version);
    }

    /** EDB 官方 Windows x64 二进制 fileid（按主版本），见 https://www.enterprisedb.com/download-postgresql-binaries */
    private const EDB_WINDOWS_FILEIDS = [
        '18' => '1259986',
        '17' => '1259988',
        '16' => '1259993',
        '15' => '1259996',
        '14' => '1259900',
        '13' => '1259854',
    ];

    private function installWindows(string $pgsqlDir, string $major): bool
    {
        $zipPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'weline-pgsql-' . $major . '.zip';
        $dir = dirname($zipPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        echo "Installing PostgreSQL $major...\n";

        // 1) 优先 winget（微软源，国内通常可访问，无需手动下载 zip）
        if ($this->commandExists('winget')) {
            echo "Trying winget (PostgreSQL.PostgreSQL.$major) ...\n";
            if ($this->installWindowsViaWinget($major, $pgsqlDir)) {
                echo "PostgreSQL installed to $pgsqlDir\n";
                return true;
            }
        }

        // 2) EDB 官方 zip
        $fileid = self::EDB_WINDOWS_FILEIDS[$major] ?? null;
        if ($fileid !== null) {
            $url = 'https://sbp.enterprisedb.com/getfile.jsp?fileid=' . $fileid;
            echo "Trying EDB binaries (fileid=$fileid) ...\n";
            if ($this->download($url, $zipPath) && $this->extractZipToPgsql($zipPath, $pgsqlDir)) {
                @unlink($zipPath);
                echo "PostgreSQL installed to $pgsqlDir\n";
                return true;
            }
            @unlink($zipPath);
        }

        // 3) 备选 SourceForge postgres-binaries（7z，需系统已装 7z）
        if ($this->commandExists('7z')) {
            $url7z = 'https://sourceforge.net/projects/postgres-binaries/files/postgres-' . $major . '.0-x64-full-windows.7z/download';
            $path7z = $this->projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'weline-pgsql-' . $major . '.7z';
            echo "Trying SourceForge postgres-binaries (7z) ...\n";
            if ($this->download7z($url7z, $path7z) && $this->extract7zToPgsql($path7z, $pgsqlDir)) {
                @unlink($path7z);
                echo "PostgreSQL installed to $pgsqlDir\n";
                return true;
            }
            @unlink($path7z);
        }

        // 4) 备选 SourceForge pgsqlportable（zip lite/full）
        $baseUrl = 'https://sourceforge.net/projects/pgsqlportable/files';
        $suffixes = ['-lite', ''];
        foreach ($suffixes as $suf) {
            $nameSuf = $suf ?: '-full';
            for ($minor = 15; $minor >= 1; $minor--) {
                $ver = $major . '.' . $minor;
                $url = $baseUrl . '/' . $ver . '/postgresql-' . $ver . '-1-windows-x64' . $nameSuf . '.zip/download';
                echo "Trying SourceForge pgsqlportable $ver ($nameSuf) ...\n";
                $zipPathVer = $this->projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'weline-pgsql-' . $ver . '.zip';
                if (!$this->download($url, $zipPathVer)) {
                    continue;
                }
                if ($this->extractZipToPgsql($zipPathVer, $pgsqlDir)) {
                    @unlink($zipPathVer);
                    echo "PostgreSQL installed to $pgsqlDir\n";
                    return true;
                }
                @unlink($zipPathVer);
            }
        }

        $this->printWindowsManualMessage($pgsqlDir);
        return false;
    }

    /**
     * 通过 winget 安装 PostgreSQL，或使用 Program Files 中已有安装，复制到 extend/server/pgsql。
     * winget 使用微软源，国内通常可访问；若已用 winget 装过，直接复制不重复安装。
     */
    private function installWindowsViaWinget(string $major, string $pgsqlDir): bool
    {
        $found = $this->findPgsqlInProgramFiles();
        if ($found === null) {
            $id = 'PostgreSQL.PostgreSQL.' . $major;
            $cmd = 'winget install ' . $id . ' --silent --accept-package-agreements --accept-source-agreements 2>&1';
            exec($cmd, $out, $code);
            $found = $this->findPgsqlInProgramFiles();
        }
        if ($found === null) {
            return false;
        }
        if (!is_dir($pgsqlDir)) {
            @mkdir($pgsqlDir, 0755, true);
        }
        $this->copyRecursive($found, $pgsqlDir, ['data']);
        if (!$this->hasValidPgsqlInstall($pgsqlDir)) {
            echo "Copied PostgreSQL from Program Files, but required binaries are invalid. Trying next source...\n";
            return false;
        }
        return true;
    }

    private function findPgsqlInProgramFiles(): ?string
    {
        $roots = [
            getenv('ProgramFiles') ?: 'C:\\Program Files',
            getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)',
        ];
        foreach ($roots as $progFiles) {
            $pgsqlRoot = $progFiles . '\\PostgreSQL';
            if (!is_dir($pgsqlRoot)) {
                continue;
            }
            foreach (scandir($pgsqlRoot) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $dir = $pgsqlRoot . '\\' . $name;
                if (!is_dir($dir)) {
                    continue;
                }
                if (is_file($dir . '\\bin\\psql.exe')) {
                    return $dir;
                }
            }
        }
        return null;
    }

    private function printWindowsManualMessage(string $pgsqlDir): void
    {
        echo "PostgreSQL auto-install failed.\n";
        echo "Easiest on Windows: open PowerShell as Administrator, run:\n";
        echo "  winget install PostgreSQL.PostgreSQL.16 --accept-package-agreements\n";
        echo "Then re-run this installer; it will copy from Program Files to $pgsqlDir\n";
        echo "Or manually: download from https://www.enterprisedb.com/download-postgresql-binaries , extract to: $pgsqlDir\n";
    }

    private function download(string $url, string $outPath): bool
    {
        $minSize = 500_000; // 约 500KB，避免把 HTML 当 zip
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/octet-stream, */*',
            'Referer: https://www.enterprisedb.com/',
        ];
        if (function_exists('curl_init')) {
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
                CURLOPT_TIMEOUT => 600,
                CURLOPT_FILE => $fp,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_ENCODING => '', // 接受 gzip 等
            ]);
            $ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
            curl_close($ch);
            fclose($fp);
            if (!$ok || !is_file($outPath) || filesize($outPath) < $minSize || !$this->isZipFile($outPath)) {
                @unlink($outPath);
                return false;
            }
            return true;
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 600,
                'header' => implode("\r\n", $headers) . "\r\n",
            ],
            'ssl' => ['verify_peer' => true],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || strlen($data) < $minSize || !$this->isZipContent($data)) {
            return false;
        }
        return file_put_contents($outPath, $data) !== false;
    }

    private function isZipFile(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if (!$h) {
            return false;
        }
        $head = fread($h, 4);
        fclose($h);
        return $head === "PK\x03\x04" || $head === "PK\x05\x06";
    }

    private function isZipContent(string $data): bool
    {
        $head = substr($data, 0, 4);
        return $head === "PK\x03\x04" || $head === "PK\x05\x06";
    }

    /** 下载 7z 等任意二进制（不校验 zip 魔数），最小 500KB */
    private function download7z(string $url, string $outPath): bool
    {
        $minSize = 500_000;
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: application/octet-stream, */*',
            'Referer: https://sourceforge.net/',
        ];
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
            CURLOPT_TIMEOUT => 600,
            CURLOPT_FILE => $fp,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
        ]);
        $ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        fclose($fp);
        if (!$ok || !is_file($outPath) || filesize($outPath) < $minSize) {
            @unlink($outPath);
            return false;
        }
        return true;
    }

    private function extract7zToPgsql(string $path7z, string $pgsqlDir): bool
    {
        $extractRoot = dirname($path7z) . DIRECTORY_SEPARATOR . 'weline-pgsql-7z-ext';
        if (is_dir($extractRoot)) {
            $this->rmdirRecursive($extractRoot);
        }
        @mkdir($extractRoot, 0755, true);
        $cmd = '7z x ' . escapeshellarg($path7z) . ' -o' . escapeshellarg($extractRoot) . ' -y';
        if (DIRECTORY_SEPARATOR === '\\') {
            $cmd = '7z x ' . escapeshellarg($path7z) . ' -o' . escapeshellarg($extractRoot) . ' -y 2>nul';
        } else {
            $cmd .= ' 2>/dev/null';
        }
        exec($cmd, $out, $code);
        if ($code !== 0) {
            $this->rmdirRecursive($extractRoot);
            return false;
        }
        $psqlInExt = null;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $fi) {
            if (!$fi->isFile()) {
                continue;
            }
            if (strtolower($fi->getFilename()) === 'psql.exe' || $fi->getFilename() === 'psql') {
                $parent = $fi->getPathInfo()->getPathInfo();
                if ($parent !== null && strtolower($fi->getPathInfo()->getFilename()) === 'bin') {
                    $psqlInExt = $parent->getPathname();
                    break;
                }
            }
        }
        if ($psqlInExt === null || !is_dir($psqlInExt)) {
            $this->rmdirRecursive($extractRoot);
            return false;
        }
        if (!is_dir($pgsqlDir)) {
            @mkdir($pgsqlDir, 0755, true);
        }
        $this->copyRecursive($psqlInExt, $pgsqlDir, ['data']);
        $this->rmdirRecursive($extractRoot);
        return $this->hasValidPgsqlInstall($pgsqlDir);
    }

    private function extractZipToPgsql(string $zipPath, string $pgsqlDir): bool
    {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return false;
        }
        $extractRoot = dirname($zipPath) . DIRECTORY_SEPARATOR . 'weline-pgsql-ext';
        if (!is_dir($extractRoot)) {
            @mkdir($extractRoot, 0755, true);
        }
        $zip->extractTo($extractRoot);
        $zip->close();

        $psqlInZip = null;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $fi) {
            if (!$fi->isFile()) {
                continue;
            }
            if (strtolower($fi->getFilename()) === 'psql.exe' || $fi->getFilename() === 'psql') {
                $parent = $fi->getPathInfo()->getPathInfo();
                if (strtolower($fi->getPathInfo()->getFilename()) === 'bin' && $parent !== null) {
                    $psqlInZip = $parent->getPathname();
                    break;
                }
            }
        }
        if ($psqlInZip === null || !is_dir($psqlInZip)) {
            $this->rmdirRecursive($extractRoot);
            return false;
        }
        if (!is_dir($pgsqlDir)) {
            @mkdir($pgsqlDir, 0755, true);
        }
        $this->copyRecursive($psqlInZip, $pgsqlDir, ['data']);
        $this->rmdirRecursive($extractRoot);
        return $this->hasValidPgsqlInstall($pgsqlDir);
    }

    private function copyRecursive(string $src, string $dst, array $skipTopLevelNames = []): void
    {
        $dir = opendir($src);
        if ($dir === false) {
            return;
        }
        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }
        while (($f = readdir($dir)) !== false) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            if (in_array($f, $skipTopLevelNames, true)) {
                continue;
            }
            $s = $src . DIRECTORY_SEPARATOR . $f;
            $d = $dst . DIRECTORY_SEPARATOR . $f;
            if (is_dir($s)) {
                $this->copyRecursive($s, $d);
            } else {
                $this->copyFileWithSizeCheck($s, $d);
            }
        }
        closedir($dir);
    }

    private function copyFileWithSizeCheck(string $src, string $dst): void
    {
        @copy($src, $dst);
        clearstatcache(true, $src);
        clearstatcache(true, $dst);
        $srcSize = is_file($src) ? @filesize($src) : false;
        $dstSize = is_file($dst) ? @filesize($dst) : false;
        if ($srcSize !== false && $srcSize > 0 && $dstSize === $srcSize) {
            return;
        }
        @unlink($dst);
        @copy($src, $dst);
    }

    private function hasValidPgsqlInstall(string $pgsqlDir): bool
    {
        $suffix = DIRECTORY_SEPARATOR === '\\' ? '.exe' : '';
        $binDir = $pgsqlDir . DIRECTORY_SEPARATOR . 'bin';
        foreach (['psql', 'initdb', 'postgres'] as $name) {
            $path = $binDir . DIRECTORY_SEPARATOR . $name . $suffix;
            clearstatcache(true, $path);
            if (!is_file($path) || (int)@filesize($path) < 1024) {
                return false;
            }
        }
        return true;
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $fi) {
            if ($fi->isDir()) {
                @rmdir($fi->getPathname());
            } else {
                @unlink($fi->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function installMac(string $version): bool
    {
        if (!$this->commandExists('brew')) {
            echo "Homebrew not found. Install PostgreSQL manually, e.g. extract to extend/server/pgsql\n";
            return false;
        }
        $cmd = 'brew install postgresql@' . $version . ' 2>/dev/null || brew install postgresql';
        echo "Running: $cmd\n";
        passthru($cmd, $code);
        return $code === 0;
    }

    private function installLinux(string $version): bool
    {
        if (is_file('/etc/debian_version')) {
            $cmd = 'apt-get update -qq && apt-get install -y postgresql-' . $version . ' postgresql-client-' . $version . ' 2>/dev/null';
        } elseif (is_file('/etc/redhat-release')) {
            $cmd = 'dnf install -y postgresql' . $version . '-server postgresql' . $version . ' 2>/dev/null';
        } else {
            echo "Linux: install PostgreSQL manually, e.g. sudo apt install postgresql-" . $version . " or extract to extend/server/pgsql\n";
            return false;
        }
        echo "Running: $cmd\n";
        passthru($cmd, $code);
        if ($code !== 0) {
            echo "If permission denied, run with sudo or install manually.\n";
        }
        return $code === 0;
    }

    private function commandExists(string $cmd): bool
    {
        $wrap = (DIRECTORY_SEPARATOR === '\\') ? 'where %s 2>nul' : 'command -v %s 2>/dev/null';
        $line = @shell_exec(sprintf($wrap, escapeshellarg($cmd)));
        return $line !== null && trim($line) !== '';
    }
}
