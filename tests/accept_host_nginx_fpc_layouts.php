<?php

declare(strict_types=1);

/**
 * Host Nginx layout acceptance: simulate Debian/CentOS/BaoTa/Homebrew paths
 * and verify discovery + install (map/location/project includes) still works.
 *
 * Run: php tests/accept_host_nginx_fpc_layouts.php
 */

$root = \dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Weline\Server\Service\Edge\Nginx\HostNginxFpcDiskPublisher;

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$base = \sys_get_temp_dir() . '/wls-host-fpc-layouts-' . \bin2hex(\random_bytes(4));
$project = $base . '/project';
$fakeNginx = $base . '/bin/nginx';
$fakeLog = $base . '/nginx.log';

\mkdir($project . '/var/server/nginx/conf', 0777, true);
\mkdir(\dirname($fakeNginx), 0777, true);

/**
 * @return string main conf path
 */
function layout(string $base, string $name, string $mainInclude, string $vhostRelative, string $serverBody): string
{
    $dir = $base . '/' . $name;
    \mkdir($dir . '/conf', 0777, true);
    \mkdir(\dirname($dir . '/' . $vhostRelative), 0777, true);
    $main = $dir . '/conf/nginx.conf';
    \file_put_contents($main, "events {}\nhttp {\n    include " . $mainInclude . ";\n}\n");
    \file_put_contents($dir . '/' . $vhostRelative, $serverBody);

    return $main;
}

function fakeNginx(string $path, string $log, string $mainConf): void
{
    \file_put_contents($path, "#!/bin/sh\n"
        . "echo \"\$@\" >> " . \escapeshellarg($log) . "\n"
        . "case \"\$1\" in\n"
        . "  -V) echo 'configure arguments: --conf-path=" . $mainConf . "'; exit 0;;\n"
        . "  *) exit 0;;\n"
        . "esac\n");
    \chmod($path, 0755);
}

try {
    $publisher = new HostNginxFpcDiskPublisher();
    $server = <<<NGINX
server {
    listen 80;
    server_name shop.test;
    location / {
        proxy_pass http://127.0.0.1:9555;
    }
}
NGINX;

    $layouts = [
        'debian' => ['<ROOT>/sites-enabled/*;', 'sites-enabled/shop.conf', 'sites-enabled'],
        'centos' => ['<ROOT>/conf.d/*.conf;', 'conf.d/shop.conf', 'conf.d'],
        'baota' => ['<ROOT>/vhost/nginx/*.conf;', 'vhost/nginx/shop.conf', 'vhost/nginx'],
        'homebrew' => ['<ROOT>/servers/*;', 'servers/shop', 'servers'],
    ];

    foreach ($layouts as $name => $layoutDef) {
        [$includeTemplate, $vhostRelative, $expectedMapDir] = $layoutDef;
        $dir = $base . '/' . $name;
        $include = \str_replace('<ROOT>', $dir, $includeTemplate);
        $main = layout($base, $name, $include, $vhostRelative, $server);
        fakeNginx($fakeNginx, $fakeLog, $main);

        $target = $publisher->discover($project, 'shop.test', 9555, $fakeNginx, $main);
        check($target !== null, $name . ': discover returned null');
        check(\str_ends_with($target->locationFile, $vhostRelative), $name . ': wrong location file ' . $target->locationFile);
        check(\str_ends_with($target->mapDirectory, $expectedMapDir), $name . ': wrong map dir ' . $target->mapDirectory);

        $result = $publisher->install($target, false);
        check(($result['ok'] ?? false) === true, $name . ': install failed ' . ($result['message'] ?? ''));
        $mapFile = $target->mapDirectory . '/00-wls-fpc-page-gen.conf';
        check(\is_file($mapFile), $name . ': map not written');
        check(\str_contains((string)\file_get_contents($mapFile), 'map $arg_w_pg $wls_fpc_page_gen'), $name . ': wrong map');
        check(
            \str_contains((string)\file_get_contents($target->locationFile), 'pg/$wls_fpc_page_gen'),
            $name . ': location not rewritten',
        );
        echo "  [ok] {$name} -> " . $target->locationFile . "\n";
    }

    // BaoTa often keeps the proxy location in a separate included file.
    $baota = $base . '/baota-split';
    \mkdir($baota . '/conf', 0777, true);
    \mkdir($baota . '/vhost/nginx/locations', 0777, true);
    $main = $baota . '/conf/nginx.conf';
    \file_put_contents($main, "events {}\nhttp { include " . $baota . "/vhost/nginx/*.conf; }\n");
    \file_put_contents(
        $baota . '/vhost/nginx/shop.conf',
        "server {\n    listen 80;\n    server_name shop.test;\n    include " . $baota . "/vhost/nginx/locations/proxy.conf;\n}\n",
    );
    \file_put_contents(
        $baota . '/vhost/nginx/locations/proxy.conf',
        "location / {\n    proxy_pass http://127.0.0.1:9555;\n}\n",
    );
    fakeNginx($fakeNginx, $fakeLog, $main);
    $target = $publisher->discover($project, 'shop.test', 9555, $fakeNginx, $main);
    check($target !== null, 'baota-split: discover returned null');
    check(\str_ends_with($target->locationFile, 'locations/proxy.conf'), 'baota-split: location include not found');
    $result = $publisher->install($target, false);
    check(($result['ok'] ?? false) === true, 'baota-split: install failed');
    check(
        \str_contains((string)\file_get_contents($target->locationFile), 'pg/$wls_fpc_page_gen'),
        'baota-split: separate location not rewritten',
    );
    echo "  [ok] baota-split -> " . $target->locationFile . "\n";

    $debianMain = $base . '/debian/conf/nginx.conf';
    $debianVhost = $base . '/debian/sites-enabled/shop.conf';
    // nginx -V --conf-path discovery (no explicit --nginx-conf).
    fakeNginx($fakeNginx, $fakeLog, $debianMain);
    $viaVersion = $publisher->discover($project, 'shop.test', 9555, $fakeNginx, null);
    check($viaVersion !== null, 'nginx -V --conf-path discovery failed');
    echo "  [ok] nginx -V --conf-path discovery\n";

    // Real command path: server:nginx:host-fpc with explicit host Nginx paths.
    @\unlink($base . '/debian/sites-enabled/00-wls-fpc-page-gen.conf');
    \file_put_contents($debianVhost, $server);
    fakeNginx($fakeNginx, $fakeLog, $debianMain);
    $cmd = \PHP_BINARY . ' bin/w server:nginx:host-fpc --host=shop.test -p=9555'
        . ' --nginx-bin=' . \escapeshellarg($fakeNginx)
        . ' --nginx-conf=' . \escapeshellarg($debianMain) . ' 2>&1';
    \exec($cmd, $cmdOut, $cmdCode);
    check($cmdCode === 0, 'command failed: ' . \implode("\n", $cmdOut));
    check(\str_contains((string)\file_get_contents($debianVhost), 'pg/$wls_fpc_page_gen'), 'command did not rewrite vhost');
    check(\is_file($base . '/debian/sites-enabled/00-wls-fpc-page-gen.conf'), 'command did not write map');
    echo "  [ok] command server:nginx:host-fpc\n";

    // No matching server must fail closed, not write a random vhost.
    \file_put_contents($baota . '/vhost/nginx/other.conf', "server { server_name other.test; }\n");
    check(
        $publisher->discover($project, 'missing.test', 9555, $fakeNginx, $main) === null,
        'missing host must not discover',
    );
    echo "  [ok] missing host fails closed\n";

    echo "ACCEPT PASS\n";
} catch (Throwable $e) {
    \fwrite(STDERR, 'ACCEPT FAIL: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
    }
    @\rmdir($base);
}
