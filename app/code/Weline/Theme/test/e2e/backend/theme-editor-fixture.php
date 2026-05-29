<?php
declare(strict_types=1);

require dirname(__DIR__, 7) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function read_payload(): array
{
    $raw = stream_get_contents(STDIN);
    $payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
    return is_array($payload) ? $payload : [];
}

function output_json(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function cleanup_theme_editor_fixture(ThemeLayout $layout, ThemeLayoutVersion $version, int $themeId, string $pageType): void
{
    $layout->clearQuery()
        ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
        ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
        ->delete()
        ->fetch();

    $version->clearQuery()
        ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
        ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
        ->delete()
        ->fetch();
}

function snapshot_theme_editor_fixture(ThemeLayout $layout, ThemeLayoutVersion $version, int $themeId, string $pageType): array
{
    $layoutRows = $layout->clearQuery()
        ->where(ThemeLayout::schema_fields_THEME_ID, $themeId)
        ->where(ThemeLayout::schema_fields_PAGE_TYPE, $pageType)
        ->order(ThemeLayout::schema_fields_STATUS, 'ASC')
        ->order(ThemeLayout::schema_fields_AREA, 'ASC')
        ->order(ThemeLayout::schema_fields_SLOT_ID, 'ASC')
        ->order(ThemeLayout::schema_fields_SORT_ORDER, 'ASC')
        ->order(ThemeLayout::schema_fields_ID, 'ASC')
        ->select()
        ->fetchArray();

    $versionRows = $version->clearQuery()
        ->where(ThemeLayoutVersion::schema_fields_THEME_ID, $themeId)
        ->where(ThemeLayoutVersion::schema_fields_PAGE_TYPE, $pageType)
        ->order(ThemeLayoutVersion::schema_fields_VERSION_NUMBER, 'ASC')
        ->order(ThemeLayoutVersion::schema_fields_ID, 'ASC')
        ->select()
        ->fetchArray();

    return [
        'success' => true,
        'layout' => is_array($layoutRows) ? array_values($layoutRows) : [],
        'versions' => is_array($versionRows) ? array_values($versionRows) : [],
    ];
}

$payload = read_payload();
$action = (string)($payload['action'] ?? '');
$themeId = (int)($payload['theme_id'] ?? 0);
$pageType = trim((string)($payload['page_type'] ?? ''));

if ($action === '') {
    fail('Missing fixture action.');
}
if ($themeId <= 0) {
    fail('Missing theme_id.');
}
if ($pageType === '') {
    fail('Missing page_type.');
}

$layout = clone ObjectManager::getInstance(ThemeLayout::class);
$version = clone ObjectManager::getInstance(ThemeLayoutVersion::class);

try {
    if ($action === 'cleanup') {
        cleanup_theme_editor_fixture($layout, $version, $themeId, $pageType);
        output_json(['success' => true]);
        exit(0);
    }

    if ($action === 'snapshot') {
        output_json(snapshot_theme_editor_fixture($layout, $version, $themeId, $pageType));
        exit(0);
    }

    fail('Unsupported fixture action: ' . $action);
} catch (Throwable $throwable) {
    fail($throwable->getMessage());
}
