<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Locale\Dictionary;
use Weline\Meta\Model\MetaConfig;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeLayoutVersion;
use Weline\Theme\Model\ThemeScopePatch;
use Weline\Theme\Model\ThemeScopeRelease;
use Weline\Theme\Model\ThemeScopeRevision;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\ThemeVirtualLayoutVersion;
use Weline\Theme\Model\ThemeWidgetDefaultInjection;
use Weline\Theme\Model\WelineTheme;

/** Wipes Theme module data for greenfield re-init (scoped-only). */
final class ThemeFactoryResetService
{
    /** @var list<class-string<Model>> */
    private const THEME_MODELS = [
        ThemeScopePatch::class,
        ThemeScopeRevision::class,
        ThemeScopeRelease::class,
        ThemeScopeWorkspace::class,
        ThemeWidgetDefaultInjection::class,
        ThemeLayout::class,
        ThemeLayoutVersion::class,
        ThemeVirtualLayout::class,
        ThemeVirtualLayoutVersion::class,
        WelineTheme::class,
    ];

    public function __construct(
        private readonly ThemeScopePatch $patches,
        private readonly ThemeRuntimeCacheCleaner $cacheCleaner,
    ) {
    }

    /** @return array{tables:list<string>,meta:int,dictionary:int,cache:array<string,mixed>} */
    public function resetAll(string $confirmation): array
    {
        if (\trim($confirmation) !== 'RESET') {
            throw new \InvalidArgumentException('theme_factory_reset_confirmation_invalid');
        }

        $connection = $this->patches->getConnection()->getConnector();
        $cleared = [];
        foreach (self::THEME_MODELS as $modelClass) {
            if (!$connection->tableExist($modelClass::schema_table)) {
                continue;
            }
            /** @var Model $model */
            $model = ObjectManager::getInstance($modelClass);
            $tableSql = $model->getTable();
            $suffix = $connection instanceof PgsqlConnector ? ' RESTART IDENTITY CASCADE' : '';
            $connection->query('TRUNCATE TABLE ' . $tableSql . $suffix)->fetch();
            $cleared[] = $modelClass::schema_table;
        }

        $metaCount = 0;
        if ($connection->tableExist(MetaConfig::schema_table)) {
            /** @var MetaConfig $meta */
            $meta = ObjectManager::getInstance(MetaConfig::class);
            $ns = $connection->quoteIdentifier(MetaConfig::schema_fields_NAMESPACE);
            $sql = 'DELETE FROM ' . $meta->getTable()
                . ' WHERE ' . $ns . " IN ('theme.frontend','theme.backend')";
            $metaCount = (int)$connection->getLink()->exec($sql);
        }

        $dictCount = 0;
        if ($connection->tableExist(Dictionary::schema_table)) {
            /** @var Dictionary $dictionary */
            $dictionary = ObjectManager::getInstance(Dictionary::class);
            $word = $connection->quoteIdentifier(Dictionary::schema_fields_WORD);
            $sql = 'DELETE FROM ' . $dictionary->getTable()
                . ' WHERE ' . $word . " LIKE '@meta::theme.%'";
            $dictCount = (int)$connection->getLink()->exec($sql);
        }

        $cache = $this->cacheCleaner->clearNonGlobalCaches(null, 'theme_factory_reset');

        return [
            'tables' => $cleared,
            'meta' => $metaCount,
            'dictionary' => $dictCount,
            'cache' => $cache,
        ];
    }
}
