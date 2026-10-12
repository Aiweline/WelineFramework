#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 为 destinations.json 补 Nominatim WGS-84 lat/lng（主题脚本，不进 app/code）。
 *
 * 用法:
 *   php app/design/Weline/playinginchina/scripts/geocode-destinations.php [--limit=N] [--ids=a,b] [--force] [--approx-seed]
 *
 * - flock 防并发写穿；每次写盘前重读合并 lat/lng。
 * - --approx-seed：无 lat/lng 时用示意图 x/y 写入 coord=approx（全站立刻可出真图）。
 * - Nominatim：≥1.1s/请求 + 可识别 User-Agent；会把 approx 精修为 wgs84。
 */

$root = \dirname(__DIR__, 4);
$themeData = \dirname(__DIR__) . '/data/destinations.json';
$cabinetData = $root . '/websites/playinginchina.com/data/destinations.json';
$lockPath = \dirname($themeData) . '/.destinations-geocode.lock';

require_once \dirname(__DIR__) . '/frontend/includes/PlayingInChinaGeo.php';

$limit = 0;
$force = false;
$approxSeed = false;
$onlyIds = [];
foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
        continue;
    }
    if ($arg === '--approx-seed') {
        $approxSeed = true;
        continue;
    }
    if (\str_starts_with($arg, '--limit=')) {
        $limit = \max(0, (int)\substr($arg, 8));
        continue;
    }
    if (\str_starts_with($arg, '--ids=')) {
        $onlyIds = \array_values(\array_filter(\array_map('trim', \explode(',', \substr($arg, 6)))));
        continue;
    }
    \fwrite(\STDERR, "Unknown arg: {$arg}\n");
    \exit(2);
}

if (!\is_file($themeData)) {
    \fwrite(\STDERR, "Missing {$themeData}\n");
    \exit(1);
}

$lock = \fopen($lockPath, 'c+');
if ($lock === false || !\flock($lock, \LOCK_EX | \LOCK_NB)) {
    \fwrite(\STDERR, "Another geocode is running (lock busy)\n");
    \exit(3);
}

/** @var list<array<string,mixed>> $items */
$items = \json_decode((string)\file_get_contents($themeData), true);
if (!\is_array($items)) {
    \fwrite(\STDERR, "Invalid destinations JSON\n");
    \flock($lock, \LOCK_UN);
    \fclose($lock);
    \exit(1);
}

if ($approxSeed) {
    $seeded = 0;
    foreach ($items as $i => $d) {
        if (!\is_array($d)) {
            continue;
        }
        $map = \is_array($d['map'] ?? null) ? $d['map'] : [];
        $hasReal = isset($map['lat'], $map['lng']) && ($map['coord'] ?? '') !== 'approx'
            && $map['lat'] !== '' && $map['lng'] !== '';
        if ($hasReal) {
            continue;
        }
        if (!isset($map['x'], $map['y'])) {
            continue;
        }
        $approx = \PlayingInChinaGeo::approxWgs84FromSchematic((float)$map['x'], (float)$map['y']);
        if ($approx === null) {
            continue;
        }
        $map['lat'] = $approx[0];
        $map['lng'] = $approx[1];
        $map['coord'] = 'approx';
        $items[$i]['map'] = $map;
        $seeded++;
    }
    persistLocked($themeData, $cabinetData, $items);
    \fwrite(\STDOUT, "approx-seeded={$seeded}\n");
    if ($onlyIds === [] && $limit === 0 && !$force) {
        /* 只做 seed 时可直接退出；若同时带 limit/ids 则继续 Nominatim */
        $continue = false;
        foreach (\array_slice($argv, 1) as $arg) {
            if (\str_starts_with($arg, '--limit=') || \str_starts_with($arg, '--ids=') || $arg === '--force') {
                $continue = true;
            }
        }
        if (!$continue) {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
            \exit(0);
        }
    }
}

$ua = 'PlayingInChinaGeocoder/1.0 (Weline theme asset; contact: local-dev@playinginchina)';
$done = 0;
$skipped = 0;
$failed = 0;

foreach ($items as $i => $d) {
    if (!\is_array($d)) {
        continue;
    }
    $id = (string)($d['id'] ?? '');
    if ($onlyIds !== [] && !\in_array($id, $onlyIds, true)) {
        continue;
    }
    $map = \is_array($d['map'] ?? null) ? $d['map'] : [];
    $coord = (string)($map['coord'] ?? '');
    $hasPrecise = isset($map['lat'], $map['lng']) && $map['lat'] !== '' && $map['lng'] !== '' && $coord !== 'approx';
    if ($hasPrecise && !$force) {
        $skipped++;
        continue;
    }

    $name = \html_entity_decode((string)($d['name'] ?? ''), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    $cn = \html_entity_decode((string)($d['cn'] ?? ''), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    $city = \html_entity_decode((string)($d['city'] ?? ''), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    $province = \html_entity_decode((string)($d['province'] ?? ''), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

    $queries = [];
    if ($cn !== '') {
        $queries[] = \trim($cn . ' ' . \preg_replace('/\s*→\s*/u', ' ', $city) . ' 中国');
    }
    if ($name !== '') {
        $queries[] = \trim($name . ', ' . \str_replace('→', ',', $city) . ', ' . $province . ', China');
    }
    $queries = \array_values(\array_unique(\array_filter($queries)));

    $hit = null;
    foreach ($queries as $q) {
        $hit = nominatimSearch($q, $ua);
        if ($hit !== null) {
            break;
        }
        \usleep(1_100_000);
    }

    if ($hit === null) {
        $failed++;
        \fwrite(\STDERR, "[fail] {$id}\n");
        if ($limit > 0 && ($done + $failed) >= $limit) {
            break;
        }
        continue;
    }

    /* 重读合并，避免并发/中断丢已有精修 */
    $fresh = \json_decode((string)\file_get_contents($themeData), true);
    if (\is_array($fresh)) {
        $items = $fresh;
    }
    $map = \is_array($items[$i]['map'] ?? null) ? $items[$i]['map'] : [];
    $map['lat'] = $hit['lat'];
    $map['lng'] = $hit['lng'];
    $map['coord'] = 'wgs84';
    $items[$i]['map'] = $map;
    persistLocked($themeData, $cabinetData, $items);
    $done++;
    \fwrite(\STDOUT, "[ok] {$id} {$hit['lat']},{$hit['lng']} ← {$hit['display']}\n");

    if ($limit > 0 && ($done + $failed) >= $limit) {
        break;
    }
    \usleep(1_100_000);
}

\fwrite(\STDOUT, "done={$done} skipped={$skipped} failed={$failed}\n");
\flock($lock, \LOCK_UN);
\fclose($lock);
\exit($failed > 0 && $done === 0 ? 1 : 0);

/**
 * @param list<array<string,mixed>> $items
 */
function persistLocked(string $themeData, string $cabinetData, array $items): void
{
    $json = \json_encode($items, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new \RuntimeException('json_encode failed');
    }
    $json .= "\n";
    $tmp = $themeData . '.tmp.' . \getmypid();
    if (\file_put_contents($tmp, $json) === false) {
        throw new \RuntimeException('write tmp failed');
    }
    if (!\rename($tmp, $themeData)) {
        @\unlink($tmp);
        throw new \RuntimeException('rename theme data failed');
    }
    if (\is_dir(\dirname($cabinetData))) {
        \file_put_contents($cabinetData, $json);
    }
}

/**
 * @return array{lat: float, lng: float, display: string}|null
 */
function nominatimSearch(string $q, string $ua): ?array
{
    $url = 'https://nominatim.openstreetmap.org/search?' . \http_build_query([
        'q' => $q,
        'format' => 'jsonv2',
        'limit' => 1,
        'countrycodes' => 'cn',
    ], '', '&', \PHP_QUERY_RFC3986);

    $ctx = \stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: {$ua}\r\nAccept: application/json\r\n",
            'timeout' => 20,
        ],
    ]);
    $raw = @\file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }
    $rows = \json_decode($raw, true);
    if (!\is_array($rows) || $rows === []) {
        return null;
    }
    $row = $rows[0];
    $lat = isset($row['lat']) ? (float)$row['lat'] : null;
    $lng = isset($row['lon']) ? (float)$row['lon'] : null;
    if ($lat === null || $lng === null) {
        return null;
    }

    return [
        'lat' => $lat,
        'lng' => $lng,
        'display' => (string)($row['display_name'] ?? $q),
    ];
}
