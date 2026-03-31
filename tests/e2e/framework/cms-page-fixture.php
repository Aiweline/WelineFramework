<?php
declare(strict_types=1);

use WeShop\Cms\Model\Page;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function fixture_fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function parse_cli_args(array $argv): array
{
    $result = [];
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $parts = explode('=', substr($arg, 2), 2);
        $key = trim($parts[0]);
        if ($key === '') {
            continue;
        }
        $result[$key] = $parts[1] ?? '';
    }
    return $result;
}

try {
    $args = parse_cli_args($argv ?? []);
    $suffix = (string) (time() . random_int(1000, 9999));
    $handle = (string) ($args['handle'] ?? "e2e-cms-{$suffix}");
    $name = (string) ($args['name'] ?? "E2E CMS {$suffix}");
    $title = (string) ($args['title'] ?? "E2E CMS Title {$suffix}");
    $content = (string) ($args['content'] ?? "E2E CMS content {$suffix}");
    $status = (int) ($args['status'] ?? Page::STATUS_PUBLISHED);
    $type = (string) ($args['type'] ?? Page::TYPE_CUSTOM);

    /** @var Page $page */
    $page = ObjectManager::getInstance(Page::class);
    $connector = $page->getConnection()->getConnector();
    $columns = $connector->getTableColumns($page->getTable());
    $columnNames = [];
    foreach ($columns as $column) {
        $columnName = (string) ($column['name'] ?? '');
        if ($columnName !== '') {
            $columnNames[$columnName] = true;
        }
    }

    $handleField = null;
    foreach ([Page::schema_fields_HANDLE, 'identifier', 'url_key', 'slug', 'path'] as $candidate) {
        if (isset($columnNames[$candidate])) {
            $handleField = $candidate;
            break;
        }
    }
    if ($handleField === null) {
        fixture_fail('CMS page fixture failed: no handle-like column found in cms page table.');
    }

    $baseData = [
        Page::schema_fields_TYPE => $type,
        Page::schema_fields_NAME => $name,
        Page::schema_fields_TITLE => $title,
        Page::schema_fields_CONTENT => $content,
        Page::schema_fields_PARENT_ID => 0,
        Page::schema_fields_STYLE => '',
        Page::schema_fields_STYLE_SETTING => '{}',
        Page::schema_fields_GA4_ID => '',
        Page::schema_fields_GTM_ID => '',
        Page::schema_fields_FB_PIXEL_ID => '',
        Page::schema_fields_LOGO => '',
        Page::schema_fields_ICON => '',
        Page::schema_fields_LOCALES => '[]',
        Page::schema_fields_DEFAULT_LOCALE => '',
        Page::schema_fields_META_TITLE => $title,
        Page::schema_fields_META_DESCRIPTION => '',
        Page::schema_fields_META_KEYWORDS => '',
        Page::schema_fields_REDIRECT_URL => '',
        Page::schema_fields_STATUS => $status,
    ];

    $saveWithField = static function (Page $targetPage, string $dynamicHandleField) use ($baseData, $columnNames, $handle): void {
        $targetPage->clearData()->setData($dynamicHandleField, $handle);
        foreach ($baseData as $field => $value) {
            if (!isset($columnNames[$field])) {
                continue;
            }
            $targetPage->setData($field, $value);
        }
        $targetPage->save(true);
    };

    try {
        $saveWithField($page, $handleField);
    } catch (Throwable $throwable) {
        // Fallback for mixed schemas where model constants and physical columns are out of sync.
        foreach ([Page::schema_fields_HANDLE, 'identifier'] as $fallbackField) {
            if ($fallbackField === $handleField || !isset($columnNames[$fallbackField])) {
                continue;
            }

            try {
                $legacyPage = ObjectManager::getInstance(Page::class);
                $saveWithField($legacyPage, $fallbackField);
                $page = $legacyPage;
                $handleField = $fallbackField;
                break;
            } catch (Throwable) {
                continue;
            }
        }

        if ((int) $page->getId() <= 0) {
            throw $throwable;
        }
    }

    $pageId = (int) $page->getId();
    if ($pageId <= 0) {
        fixture_fail('Failed to create cms page fixture.');
    }

    echo json_encode([
        'page_id' => $pageId,
        'handle' => $handle,
        'handle_field' => $handleField,
        'name' => $name,
        'title' => $title,
        'content' => $content,
        'status' => $status,
        'type' => $type,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
} catch (Throwable $throwable) {
    fixture_fail('CMS page fixture failed: ' . $throwable->getMessage());
}
