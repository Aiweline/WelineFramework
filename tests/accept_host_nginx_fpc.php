<?php

declare(strict_types=1);

/**
 * Host Nginx FPC install acceptance (no root):
 * - writes w_pg map + location include
 * - writes project-owned include files
 * - runs nginx -t then reload
 * - second install is idempotent
 * - unwritable host config returns the root command + include paths
 *
 * Run: php tests/accept_host_nginx_fpc.php
 */

$root = \dirname(__DIR__);
require $root . '/app/bootstrap.php';

use Weline\Server\Service\Edge\Nginx\HostNginxFpcDiskPublisher;
use Weline\Server\Service\Edge\Nginx\HostNginxFpcTarget;

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$base = \sys_get_temp_dir() . '/wls-host-fpc-accept-' . \bin2hex(\random_bytes(4));
$project = $base . '/project';
$vhost = $base . '/vhost';
$snippet = $vhost . '/shop.conf';
$fakeNginx = $base . '/nginx';
$log = $base . '/nginx.log';

\mkdir($project . '/var/server/nginx/conf', 0777, true);
\mkdir($vhost, 0777, true);
\file_put_contents($snippet, <<<NGINX
server {
    listen 80;
    server_name shop.test;
    location / {
        proxy_pass http://127.0.0.1:9555;
    }
}
NGINX);
\file_put_contents(
    $fakeNginx,
    "#!/bin/sh\necho \"\$@\" >> " . \escapeshellarg($log) . "\nexit 0\n",
);
\chmod($fakeNginx, 0755);

try {
    $publisher = new HostNginxFpcDiskPublisher();
    $target = new HostNginxFpcTarget(
        $fakeNginx,
        $vhost,
        $snippet,
        $project,
        'shop.test',
        '127.0.0.1',
        9555,
    );

    $first = $publisher->install($target, true);
    check(($first['ok'] ?? false) === true, 'first install failed: ' . ($first['message'] ?? ''));
    check(($first['reloaded'] ?? false) === true, 'first install did not reload');

    $map = (string)\file_get_contents($vhost . '/00-wls-fpc-page-gen.conf');
    check(\str_contains($map, 'map $arg_w_pg $wls_fpc_page_gen'), 'map missing w_pg selector');
    check(\str_contains($map, 'default           current'), 'map missing current fallback');

    $location = (string)\file_get_contents($snippet);
    check(\str_contains($location, 'pg/$wls_fpc_page_gen'), 'location missing pg generation');
    check(\str_contains($location, 'location @wls_html_origin'), 'location missing origin fallback');
    check(\str_contains($location, 'proxy_pass http://127.0.0.1:9555'), 'location lost loopback proxy');

    $includes = HostNginxFpcDiskPublisher::projectIncludePaths($project);
    check(\is_file($includes['http']), 'project http include missing');
    check(\is_file($includes['server']), 'project server include missing');
    check(\str_contains((string)\file_get_contents($includes['http']), 'map $arg_w_pg'), 'http include body wrong');
    check(\str_contains((string)\file_get_contents($includes['server']), 'pg/$wls_fpc_page_gen'), 'server include body wrong');

    $nginxLog = (string)\file_get_contents($log);
    check(\str_contains($nginxLog, '-t'), 'nginx -t not called');
    check(\str_contains($nginxLog, '-s reload'), 'nginx reload not called');

    $second = $publisher->install($target, true);
    check(($second['message'] ?? '') === 'unchanged', 'second install not idempotent: ' . ($second['message'] ?? ''));

    // Permission failure: no write access to the host map dir.
    \unlink($vhost . '/00-wls-fpc-page-gen.conf');
    \chmod($vhost, 0555);
    try {
        $denied = $publisher->install($target, true);
    } finally {
        \chmod($vhost, 0775);
    }
    check(($denied['ok'] ?? false) === false, 'unwritable host map should fail');
    $notice = (string)($denied['notice'] ?? '');
    check(\str_contains($notice, 'bin/wls-host-nginx-fpc'), 'permission notice missing root wrapper command');
    check(\str_contains($notice, 'host-fpc-http.include.conf'), 'permission notice missing http include');
    check(\str_contains($notice, 'host-fpc-server.include.conf'), 'permission notice missing server include');

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
