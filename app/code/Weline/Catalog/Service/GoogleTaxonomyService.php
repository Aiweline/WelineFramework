<?php

declare(strict_types=1);

namespace Weline\Catalog\Service;

use Weline\Catalog\Model\GoogleTaxonomy;
use Weline\Framework\Manager\ObjectManager;

/**
 * Import / read Google product taxonomy reference rows.
 */
final class GoogleTaxonomyService
{
    /**
     * Seed or upsert a small S1 sample taxonomy (structure only; no admin delete).
     *
     * @return array{imported:int,updated:int}
     */
    public function importSample(): array
    {
        $imported = 0;
        $updated = 0;
        foreach ($this->sampleRows() as $row) {
            if ($this->upsertRow($row)) {
                ++$imported;
            } else {
                ++$updated;
            }
        }

        return ['imported' => $imported, 'updated' => $updated];
    }

    /**
     * Import Google's official taxonomy-with-ids file bundled under module data/.
     *
     * @return array{imported:int,updated:int,total:int,source:string,version:?string}
     */
    public function importOfficial(?string $filePath = null): array
    {
        $filePath ??= $this->defaultOfficialFilePath();
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException((string)__(
                '未找到 Google 官方分类文件：%{1}',
                [$filePath],
            ));
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            throw new \RuntimeException((string)__('Google 分类文件读取失败'));
        }

        $version = null;
        $pathToId = [];
        $parsedRows = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                if (preg_match('/Google_Product_Taxonomy_Version:\s*(.+)$/i', $line, $matches)) {
                    $version = trim((string)$matches[1]);
                }
                continue;
            }
            if (!preg_match('/^(\d+)\s+-\s+(.+)$/', $line, $matches)) {
                continue;
            }
            $googleId = trim((string)$matches[1]);
            $path = trim((string)$matches[2]);
            if ($googleId === '' || $path === '') {
                continue;
            }
            $segments = array_values(array_filter(
                array_map('trim', explode(' > ', $path)),
                static fn(string $segment): bool => $segment !== '',
            ));
            $nameEn = (string)($segments[array_key_last($segments)] ?? $path);
            $parentPath = count($segments) > 1
                ? implode(' > ', array_slice($segments, 0, -1))
                : '';
            $parsedRows[] = [
                'google_id' => $googleId,
                'parent_google_id' => $parentPath !== '' ? (string)($pathToId[$parentPath] ?? null) : null,
                'path' => $path,
                'name_en' => $nameEn,
                'depth' => max(0, count($segments) - 1),
            ];
            $pathToId[$path] = $googleId;
        }

        if ($parsedRows === []) {
            throw new \RuntimeException((string)__('Google 分类文件为空或格式无效'));
        }

        $imported = 0;
        $updated = 0;
        foreach ($parsedRows as $row) {
            if ($this->upsertRow($row)) {
                ++$imported;
            } else {
                ++$updated;
            }
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'total' => count($parsedRows),
            'source' => $filePath,
            'version' => $version,
        ];
    }

    public function countRows(): int
    {
        /** @var GoogleTaxonomy $model */
        $model = ObjectManager::getInstance(GoogleTaxonomy::class, [], false);

        return (int)$model->clear()->total();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRoots(): array
    {
        return array_values(array_filter(
            $this->listFlat(),
            static fn(array $row): bool => (int)($row['depth'] ?? 0) === 0,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listChildren(string $parentGoogleId): array
    {
        $parentGoogleId = trim($parentGoogleId);
        if ($parentGoogleId === '') {
            return $this->listRoots();
        }

        return array_values(array_filter(
            $this->listFlat(),
            static fn(array $row): bool => (string)($row['parent_google_id'] ?? '') === $parentGoogleId,
        ));
    }

    public function find(string $googleId): ?array
    {
        $googleId = trim($googleId);
        if ($googleId === '') {
            return null;
        }
        foreach ($this->listFlat() as $row) {
            if ((string)($row['google_id'] ?? '') === $googleId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function breadcrumb(string $googleId): array
    {
        $trail = [];
        $cursor = trim($googleId);
        $guard = 0;
        while ($cursor !== '' && $guard < 32) {
            $row = $this->find($cursor);
            if ($row === null) {
                break;
            }
            array_unshift($trail, $row);
            $cursor = (string)($row['parent_google_id'] ?? '');
            ++$guard;
        }

        return $trail;
    }

    public function defaultOfficialFilePath(): string
    {
        return dirname(__DIR__) . '/data/taxonomy-with-ids.en-US.txt';
    }

    /**
     * @return list<array{google_id:string,parent_google_id:?string,path:string,name_en:string,depth:int}>
     */
    public function listFlat(): array
    {
        /** @var GoogleTaxonomy $model */
        $model = ObjectManager::getInstance(GoogleTaxonomy::class, [], false);
        $rows = $model->clear()
            ->order(GoogleTaxonomy::schema_fields_DEPTH, 'ASC')
            ->order(GoogleTaxonomy::schema_fields_PATH, 'ASC')
            ->select()
            ->fetchArray();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'google_id' => (string)($row[GoogleTaxonomy::schema_fields_GOOGLE_ID] ?? ''),
                'parent_google_id' => ($row[GoogleTaxonomy::schema_fields_PARENT_GOOGLE_ID] ?? null) !== null
                    ? (string)$row[GoogleTaxonomy::schema_fields_PARENT_GOOGLE_ID]
                    : null,
                'path' => (string)($row[GoogleTaxonomy::schema_fields_PATH] ?? ''),
                'name_en' => (string)($row[GoogleTaxonomy::schema_fields_NAME_EN] ?? ''),
                'depth' => (int)($row[GoogleTaxonomy::schema_fields_DEPTH] ?? 0),
                'label' => (string)__('google_taxonomy.' . (string)($row[GoogleTaxonomy::schema_fields_GOOGLE_ID] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTree(): array
    {
        $byParent = [];
        foreach ($this->listFlat() as $row) {
            $parent = (string)($row['parent_google_id'] ?? '');
            $byParent[$parent] ??= [];
            $byParent[$parent][] = $row;
        }

        $walk = function (string $parentId) use (&$walk, $byParent): array {
            $nodes = [];
            foreach ($byParent[$parentId] ?? [] as $row) {
                $id = (string)$row['google_id'];
                $row['nodes'] = $walk($id);
                $nodes[] = $row;
            }

            return $nodes;
        };

        return $walk('');
    }

    public function exists(string $googleId): bool
    {
        $googleId = trim($googleId);
        if ($googleId === '') {
            return false;
        }
        /** @var GoogleTaxonomy $model */
        $model = ObjectManager::getInstance(GoogleTaxonomy::class, [], false);
        $model->clear()
            ->where(GoogleTaxonomy::schema_fields_GOOGLE_ID, $googleId)
            ->find()
            ->fetch();

        return (int)$model->getId() > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return array_slice($this->listFlat(), 0, max(1, $limit));
        }
        $matches = [];
        foreach ($this->listFlat() as $row) {
            $hay = mb_strtolower($row['google_id'] . ' ' . $row['name_en'] . ' ' . $row['path']);
            if (str_contains($hay, $query)) {
                $matches[] = $row;
            }
            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @param array{google_id:string,parent_google_id:?string,path:string,name_en:string,depth:int} $row
     */
    private function upsertRow(array $row): bool
    {
        /** @var GoogleTaxonomy $model */
        $model = ObjectManager::getInstance(GoogleTaxonomy::class, [], false);
        $model->clear()
            ->where(GoogleTaxonomy::schema_fields_GOOGLE_ID, $row['google_id'])
            ->find()
            ->fetch();
        $payload = [
            GoogleTaxonomy::schema_fields_GOOGLE_ID => $row['google_id'],
            GoogleTaxonomy::schema_fields_PARENT_GOOGLE_ID => $row['parent_google_id'],
            GoogleTaxonomy::schema_fields_PATH => $row['path'],
            GoogleTaxonomy::schema_fields_NAME_EN => $row['name_en'],
            GoogleTaxonomy::schema_fields_DEPTH => $row['depth'],
            GoogleTaxonomy::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ];
        if ((int)$model->getId() > 0) {
            $model->setData($payload)->save();

            return false;
        }
        $model->clear()->setData($payload)->save();

        return true;
    }

    /**
     * @return list<array{google_id:string,parent_google_id:?string,path:string,name_en:string,depth:int}>
     */
    private function sampleRows(): array
    {
        return [
            ['google_id' => '1', 'parent_google_id' => null, 'path' => 'Animals & Pet Supplies', 'name_en' => 'Animals & Pet Supplies', 'depth' => 0],
            ['google_id' => '2', 'parent_google_id' => '1', 'path' => 'Animals & Pet Supplies > Pet Supplies', 'name_en' => 'Pet Supplies', 'depth' => 1],
            ['google_id' => '3', 'parent_google_id' => '2', 'path' => 'Animals & Pet Supplies > Pet Supplies > Dog Supplies', 'name_en' => 'Dog Supplies', 'depth' => 2],
            ['google_id' => '8', 'parent_google_id' => null, 'path' => 'Arts & Entertainment', 'name_en' => 'Arts & Entertainment', 'depth' => 0],
            ['google_id' => '166', 'parent_google_id' => null, 'path' => 'Apparel & Accessories', 'name_en' => 'Apparel & Accessories', 'depth' => 0],
            ['google_id' => '1604', 'parent_google_id' => '166', 'path' => 'Apparel & Accessories > Clothing', 'name_en' => 'Clothing', 'depth' => 1],
            ['google_id' => '187', 'parent_google_id' => '1604', 'path' => 'Apparel & Accessories > Clothing > Dresses', 'name_en' => 'Dresses', 'depth' => 2],
            ['google_id' => '212', 'parent_google_id' => null, 'path' => 'Electronics', 'name_en' => 'Electronics', 'depth' => 0],
            ['google_id' => '222', 'parent_google_id' => '212', 'path' => 'Electronics > Communications', 'name_en' => 'Communications', 'depth' => 1],
            ['google_id' => '270', 'parent_google_id' => '222', 'path' => 'Electronics > Communications > Telephony', 'name_en' => 'Telephony', 'depth' => 2],
            ['google_id' => '267', 'parent_google_id' => '270', 'path' => 'Electronics > Communications > Telephony > Mobile Phones', 'name_en' => 'Mobile Phones', 'depth' => 3],
            ['google_id' => '469', 'parent_google_id' => null, 'path' => 'Home & Garden', 'name_en' => 'Home & Garden', 'depth' => 0],
            ['google_id' => '536', 'parent_google_id' => '469', 'path' => 'Home & Garden > Kitchen & Dining', 'name_en' => 'Kitchen & Dining', 'depth' => 1],
            ['google_id' => '783', 'parent_google_id' => null, 'path' => 'Media', 'name_en' => 'Media', 'depth' => 0],
            ['google_id' => '784', 'parent_google_id' => '783', 'path' => 'Media > Books', 'name_en' => 'Books', 'depth' => 1],
        ];
    }
}
