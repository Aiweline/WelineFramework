<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Model\Acl;
use Weline\Backend\Config\MenuXmlReader;
use Weline\Backend\Model\Menu;
use Weline\Backend\Service\MenuCollector;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Service\SetupSourceFingerprint;

/**
 * 菜单收集：增量 scoped 同步与指纹延后提交的数据库对比验证（沙盒 SQLite 自建表）。
 */
class MenuCollectorTest extends TestCase
{
    private const MODULE_A = 'Weline_MenuCollectTestA';
    private const MODULE_B = 'Weline_MenuCollectTestB';

    private MenuXmlReader $menuReader;
    private MenuCollector $menuCollector;
    private Menu $menuModel;
    private Acl $aclModel;

    /** @var list<string> */
    private array $seededLegacySources = [];

    /** @var list<string> */
    private array $seededAclSources = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSandboxSchema();
        $this->menuReader = ObjectManager::getInstance(MenuXmlReader::class);
        $this->menuCollector = ObjectManager::getInstance(MenuCollector::class);
        $this->menuModel = ObjectManager::getInstance(Menu::class);
        $this->aclModel = ObjectManager::getInstance(Acl::class);
        MenuXmlReader::resetForceFullState();
        $this->menuReader->discardPendingFingerprints();
        $this->purgeSeededRows();
        $this->seedBaselineMenus();
    }

    protected function tearDown(): void
    {
        $this->purgeSeededRows();
        $this->menuReader->discardPendingFingerprints();
        MenuXmlReader::resetForceFullState();
        parent::tearDown();
    }

    /**
     * scoped legacy 同步：只传模块 A 的部分 source 时，模块 B 的 DB 行集合对比前后必须一致。
     */
    public function testScopedLegacySyncDoesNotWipeOtherModuleRows(): void
    {
        $before = $this->snapshotLegacyMenusByModule();
        $this->assertSame(
            ['TestA_Drop', 'TestA_Keep'],
            $before[self::MODULE_A] ?? [],
            '基线模块 A legacy source'
        );
        $this->assertSame(
            ['TestB_Keep1', 'TestB_Keep2'],
            $before[self::MODULE_B] ?? [],
            '基线模块 B legacy source'
        );

        $partialFileMenus = [
            'TestA_Keep' => $this->legacyFileMenu('TestA_Keep', self::MODULE_A),
        ];

        $method = new \ReflectionMethod(MenuCollector::class, 'syncLegacyMenuTable');
        $method->setAccessible(true);
        $method->invoke($this->menuCollector, $partialFileMenus, [self::MODULE_A]);

        $after = $this->snapshotLegacyMenusByModule();
        $this->assertSame(
            $before[self::MODULE_B],
            $after[self::MODULE_B] ?? [],
            '增量 scoped 同步后模块 B legacy 不得被误删'
        );
        $this->assertSame(
            ['TestA_Keep'],
            $after[self::MODULE_A] ?? [],
            '模块 A 仅应保留本轮 fileMenus 中的 source（TestA_Drop 可删）'
        );
        $this->assertNotContains('TestA_Drop', $after[self::MODULE_A] ?? []);
    }

    /**
     * 无 modulesFilter 且 fileMenus 为空：禁止整表清空（对比：种子行仍在）。
     */
    public function testEmptyUnscopedLegacySyncDoesNotTruncateTable(): void
    {
        $before = $this->snapshotLegacyMenusByModule();
        $this->assertNotEmpty($before);

        $method = new \ReflectionMethod(MenuCollector::class, 'syncLegacyMenuTable');
        $method->setAccessible(true);
        $method->invoke($this->menuCollector, [], []);

        $after = $this->snapshotLegacyMenusByModule();
        $this->assertSame($before, $after, '空 fileMenus 且无模块过滤时不得整表删除');
    }

    /**
     * ACL 增量 collect(modulesFilter)：仅目标模块可变化，其它模块 source 集合对比不变。
     */
    public function testIncrementalAclCollectPreservesOtherModulesByDatabaseDiff(): void
    {
        $beforeAcl = $this->snapshotAclMenusByModule();
        $this->assertSame(['TestA_Drop', 'TestA_Keep'], $beforeAcl[self::MODULE_A] ?? []);
        $this->assertSame(['TestB_Keep1', 'TestB_Keep2'], $beforeAcl[self::MODULE_B] ?? []);

        // 通过 registry 直接执行与 collect 相同的 scoped 删除语义：模拟 file 端只剩 TestA_Keep。
        $registry = ObjectManager::getInstance(\Weline\Acl\Api\Resource\MenuRegistryInterface::class);
        $dbRows = $registry->listManagedMenus([self::MODULE_A]);
        $removed = [];
        foreach ($dbRows as $row) {
            $sid = (string)($row['source_id'] ?? '');
            if ($sid !== '' && $sid !== 'TestA_Keep') {
                $removed[] = $sid;
            }
        }
        $registry->deleteManagedMenus($removed);

        $afterAcl = $this->snapshotAclMenusByModule();
        $this->assertSame(
            $beforeAcl[self::MODULE_B],
            $afterAcl[self::MODULE_B] ?? [],
            '仅处理模块 A 时模块 B 的 ACL source 集合必须不变'
        );
        $this->assertSame(['TestA_Keep'], $afterAcl[self::MODULE_A] ?? []);
    }

    /**
     * 指纹延后：pending 在 commit 前不得进磁盘仓；commit 后才可对比命中。
     */
    public function testFingerprintsCommitOnlyAfterExplicitCommit(): void
    {
        $fp = new SetupSourceFingerprint();
        $key = 'menu:Weline_MenuCollectFingerprintProbe';
        $value = \hash('sha256', 'menu-collect-fp-probe-' . \uniqid('', true));
        $fp->mergeUpdates([$key => null]);
        SetupSourceFingerprint::resetMemoryStore();

        $reader = $this->menuReader;
        $prop = new \ReflectionProperty(MenuXmlReader::class, 'pendingFingerprints');
        $prop->setAccessible(true);
        $prop->setValue($reader, [$key => $value, 'menu:active_set' => \hash('sha256', 'active-probe')]);

        SetupSourceFingerprint::resetMemoryStore();
        $storeBefore = $fp->loadStore();
        $this->assertArrayNotHasKey($key, $storeBefore, '仅设 pending 时磁盘仓不应有该键');

        $reader->commitPendingFingerprints();
        SetupSourceFingerprint::resetMemoryStore();
        $storeAfter = $fp->loadStore();
        $this->assertSame($value, $storeAfter[$key] ?? null, 'commit 后磁盘仓应写入 pending 指纹');
        $this->assertSame([], $reader->peekPendingFingerprints());

        $fp->mergeUpdates([$key => null]);
    }

    /**
     * MenuCollector 源码契约：成功路径 commit，失败路径 discard；双条件产物指纹与 renamed_from。
     */
    public function testCollectorCommitsFingerprintsOnlyOnSuccessPath(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Backend/Service/MenuCollector.php'
        );
        $this->assertStringContainsString('commitPendingFingerprints', $src);
        $this->assertStringContainsString('discardPendingFingerprints', $src);
        $this->assertStringContainsString('syncLegacyMenuTable($file_menus, $effectiveFilter)', $src);
        $this->assertStringContainsString('queueDestinationFingerprints', $src);
        $this->assertStringContainsString('inferAutoRenameMap', $src);
        $this->assertStringContainsString('collectXmlRenameMap', $src);
        $this->assertStringContainsString('migrateRenamedMenuRows', $src);

        $readerSrc = (string)\file_get_contents(
            BP . 'app/code/Weline/Backend/Config/MenuXmlReader.php'
        );
        $this->assertStringContainsString('pendingFingerprints', $readerSrc);
        $this->assertStringContainsString('menu:dest:', $readerSrc);
        $this->assertStringContainsString('destinationFingerprintForModule', $readerSrc);
        $this->assertDoesNotMatchRegularExpression(
            '/unset\(\$batch,\s*\$fileList\);\s*if\s*\(\$fpPending\s*!==\s*\[\]\)\s*\{\s*\$fpService->mergeUpdates/s',
            $readerSrc,
            'read() 不得在解析后立即 mergeUpdates 落盘指纹'
        );
    }

    /**
     * 产物指纹：同库内容稳定；删行后指纹变化（供双条件跳过对比）。
     */
    public function testDestinationFingerprintChangesWhenAclRowsChange(): void
    {
        $registry = ObjectManager::getInstance(\Weline\Acl\Api\Resource\MenuRegistryInterface::class);
        $before = $registry->destinationFingerprint(self::MODULE_A);
        $this->assertNotSame('', $before);
        $this->assertSame($before, $registry->destinationFingerprint(self::MODULE_A), '同内容产物指纹应稳定');

        $registry->deleteManagedMenus(['TestA_Drop']);
        $after = $registry->destinationFingerprint(self::MODULE_A);
        $this->assertNotSame($before, $after, 'ACL 产物变化后指纹必须变化');
    }

    /**
     * 跨模块父级：本轮 file/seen 不含父级时，批量读库对比——库有则通过，库无则断层。
     */
    public function testValidateParentChainComparesMissingParentsAgainstDatabase(): void
    {
        $fileMenus = [
            'Weline_ThemeFancy::system_frontend_template_fancy' => [
                'source' => 'Weline_ThemeFancy::system_frontend_template_fancy',
                'module' => 'Weline_ThemeFancy',
                'parent_source' => 'Weline_Backend::template_management',
                'route' => '',
                'title' => 'Fancy',
            ],
        ];
        $seenDbSources = [
            'Weline_ThemeFancy::system_frontend_template_fancy' => true,
        ];
        $method = new \ReflectionMethod(MenuCollector::class, 'validateMenuParentChain');
        $method->setAccessible(true);

        try {
            $method->invoke($this->menuCollector, $fileMenus, $seenDbSources);
            $this->fail('父级不在库时应抛断层');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Weline_Backend::template_management', $e->getMessage());
            $this->assertStringContainsString('文件与数据库均未找到', $e->getMessage());
        }

        $this->insertAclMenuRow('Weline_Backend::template_management', 'Weline_Backend');
        $method->invoke($this->menuCollector, $fileMenus, $seenDbSources);
        $this->assertTrue(true, '读库对比确认父级存在后不应抛断层');
    }

    /**
     * 自动搬家：同模块同 route、source 不同时一对一推断 old=>new（无需 renamed_from）。
     */
    public function testInferAutoRenameMapPairsSameModuleRoute(): void
    {
        $this->insertAclMenuRow('TestA_OldRouteId', self::MODULE_A, '', 'backend/test-auto-rename');
        $fileMenus = [
            'TestA_NewRouteId' => [
                'source' => 'TestA_NewRouteId',
                'module' => self::MODULE_A,
                'route' => 'backend/test-auto-rename',
                'title' => 'Auto Rename',
                'name' => 'Auto Rename',
                'parent_source' => '',
            ],
            'TestA_Keep' => [
                'source' => 'TestA_Keep',
                'module' => self::MODULE_A,
                'route' => '',
                'title' => 'TestA_Keep',
                'name' => 'TestA_Keep',
                'parent_source' => '',
            ],
            'TestA_Drop' => [
                'source' => 'TestA_Drop',
                'module' => self::MODULE_A,
                'route' => '',
                'title' => 'TestA_Drop',
                'name' => 'TestA_Drop',
                'parent_source' => '',
            ],
        ];

        $method = new \ReflectionMethod(MenuCollector::class, 'inferAutoRenameMap');
        $method->setAccessible(true);
        /** @var array<string, string> $map */
        $map = $method->invoke($this->menuCollector, $fileMenus, [self::MODULE_A]);

        $this->assertSame(
            ['TestA_OldRouteId' => 'TestA_NewRouteId'],
            $map,
            '同模块同 route 应自动推断为搬家，无需 renamed_from'
        );
    }

    /**
     * renamed_from 语义：ACL source 改名 + 子节点 parent_source 跟随；对比旧 id 消失、新 id 与子引用一致。
     */
    public function testRenameManagedMenuSourceMigratesIdAndParentRefs(): void
    {
        $registry = ObjectManager::getInstance(\Weline\Acl\Api\Resource\MenuRegistryInterface::class);
        $child = 'TestA_ChildOfKeep';
        $this->insertAclMenuRow($child, self::MODULE_A, 'TestA_Keep');
        $this->insertLegacyRow($child, self::MODULE_A, 'TestA_Keep');

        $before = $this->snapshotAclMenusByModule();
        $this->assertContains('TestA_Keep', $before[self::MODULE_A]);
        $this->assertContains($child, $before[self::MODULE_A]);

        $newId = 'TestA_Keep_Renamed';
        $registry->renameManagedMenuSource('TestA_Keep', $newId);
        $this->seededAclSources[] = $newId;

        $method = new \ReflectionMethod(MenuCollector::class, 'renameLegacyMenuSource');
        $method->setAccessible(true);
        $method->invoke($this->menuCollector, 'TestA_Keep', $newId);
        $this->seededLegacySources[] = $newId;

        $afterAcl = $this->snapshotAclMenusByModule();
        $this->assertNotContains('TestA_Keep', $afterAcl[self::MODULE_A] ?? []);
        $this->assertContains($newId, $afterAcl[self::MODULE_A] ?? []);
        $this->assertContains($child, $afterAcl[self::MODULE_A] ?? []);

        $childRow = $this->aclModel->reset()->clearData()
            ->where(Acl::schema_fields_SOURCE_ID, $child)
            ->find()
            ->fetch();
        $this->assertSame(
            $newId,
            (string)$childRow->getData(Acl::schema_fields_PARENT_SOURCE),
            '子菜单 parent_source 应跟随 rename'
        );

        $afterLegacy = $this->snapshotLegacyMenusByModule();
        $this->assertNotContains('TestA_Keep', $afterLegacy[self::MODULE_A] ?? []);
        $this->assertContains($newId, $afterLegacy[self::MODULE_A] ?? []);
        $legacyChild = $this->menuModel->reset()->clearData()
            ->where(Menu::schema_fields_SOURCE, $child)
            ->find()
            ->fetch();
        $this->assertSame(
            $newId,
            (string)$legacyChild->getData(Menu::schema_fields_PARENT_SOURCE),
            'legacy 子菜单 parent_source 应跟随 rename'
        );
    }

    private function ensureSandboxSchema(): void
    {
        /** @var ConnectionFactory $factory */
        $factory = ObjectManager::getInstance(ConnectionFactory::class);
        $pdo = $factory->getConnector()->getLink();

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS weline_cache_namespace_version (
                namespace_hash CHAR(64) PRIMARY KEY NOT NULL,
                namespace VARCHAR(512) NOT NULL,
                generation BIGINT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS menu (
                menu_id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(60) NOT NULL DEFAULT "",
                title VARCHAR(60) NOT NULL DEFAULT "",
                pid INTEGER DEFAULT 0,
                source VARCHAR(128) NOT NULL,
                level INTEGER DEFAULT 0,
                path VARCHAR(255) DEFAULT "",
                parent_source VARCHAR(255) NOT NULL DEFAULT "",
                action VARCHAR(255) NOT NULL DEFAULT "",
                module VARCHAR(255) NOT NULL DEFAULT "",
                icon VARCHAR(60) NOT NULL DEFAULT "",
                "order" INTEGER NOT NULL DEFAULT 0,
                is_system INTEGER DEFAULT 0,
                is_enable INTEGER DEFAULT 1,
                is_backend INTEGER DEFAULT 1
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_menu_source ON menu(source)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS acl (
                acl_id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_id VARCHAR(127) NOT NULL,
                source_name VARCHAR(255) NOT NULL DEFAULT "",
                document TEXT NOT NULL DEFAULT "",
                parent_source VARCHAR(255) NOT NULL DEFAULT "",
                router VARCHAR(60) NOT NULL DEFAULT "",
                route VARCHAR(255) DEFAULT "",
                method VARCHAR(6) DEFAULT "",
                rewrite VARCHAR(255) DEFAULT "",
                module VARCHAR(255) NOT NULL DEFAULT "",
                class VARCHAR(255) NOT NULL DEFAULT "",
                type VARCHAR(120) NOT NULL DEFAULT "",
                icon VARCHAR(255) NOT NULL DEFAULT "",
                is_enable INTEGER DEFAULT 1,
                is_backend INTEGER DEFAULT 1,
                acl_origin VARCHAR(32) NOT NULL DEFAULT "menu_xml",
                access_mode VARCHAR(16) NOT NULL DEFAULT "read",
                scope_group VARCHAR(127) NOT NULL DEFAULT "",
                api_exposable INTEGER NOT NULL DEFAULT 0,
                resource_metadata TEXT,
                "order" INTEGER DEFAULT 0
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uk_acl_source_id ON acl(source_id)');
    }

    private function seedBaselineMenus(): void
    {
        $legacy = [
            ['TestA_Keep', self::MODULE_A],
            ['TestA_Drop', self::MODULE_A],
            ['TestB_Keep1', self::MODULE_B],
            ['TestB_Keep2', self::MODULE_B],
        ];
        foreach ($legacy as [$source, $module]) {
            $this->insertLegacyRow($source, $module);
            $this->insertAclMenuRow($source, $module);
        }
    }

    private function insertLegacyRow(string $source, string $module, string $parentSource = ''): void
    {
        $row = [
            Menu::schema_fields_NAME => $source,
            Menu::schema_fields_TITLE => $source,
            Menu::schema_fields_SOURCE => $source,
            Menu::schema_fields_PID => 0,
            Menu::schema_fields_LEVEL => 1,
            Menu::schema_fields_PATH => '1',
            Menu::schema_fields_PARENT_SOURCE => $parentSource,
            Menu::schema_fields_ACTION => '',
            Menu::schema_fields_MODULE => $module,
            Menu::schema_fields_ICON => '',
            Menu::schema_fields_ORDER => 0,
            Menu::schema_fields_IS_SYSTEM => 1,
            Menu::schema_fields_IS_ENABLE => 1,
            Menu::schema_fields_IS_BACKEND => 1,
        ];
        /** @var Menu $creator */
        $creator = ObjectManager::getInstance(Menu::class, [], false);
        $creator->reset()->clearData()->setData($row)->save();
        $this->seededLegacySources[] = $source;
    }

    private function insertAclMenuRow(
        string $source,
        string $module,
        string $parentSource = '',
        string $route = ''
    ): void {
        $row = [
            Acl::schema_fields_SOURCE_ID => $source,
            Acl::schema_fields_SOURCE_NAME => $source,
            Acl::schema_fields_DOCUMENT => 'unit',
            Acl::schema_fields_PARENT_SOURCE => $parentSource,
            Acl::schema_fields_ROUTER => '',
            Acl::schema_fields_ROUTE => $route,
            Acl::schema_fields_METHOD => 'GET',
            Acl::schema_fields_REWRITE => '',
            Acl::schema_fields_MODULE => $module,
            Acl::schema_fields_CLASS => '',
            Acl::schema_fields_TYPE => Acl::type_MENUS,
            Acl::schema_fields_ICON => '',
            Acl::schema_fields_IS_ENABLE => 1,
            Acl::schema_fields_IS_BACKEND => 1,
            Acl::schema_fields_ACL_ORIGIN => Acl::acl_origin_menu_xml,
            Acl::schema_fields_ACCESS_MODE => Acl::ACCESS_MODE_READ,
            Acl::schema_fields_SCOPE_GROUP => '',
            Acl::schema_fields_API_EXPOSABLE => 0,
            Acl::schema_fields_ORDER => 0,
        ];
        /** @var Acl $creator */
        $creator = ObjectManager::getInstance(Acl::class, [], false);
        $creator->reset()->clearData()->setData($row)->save(true, Acl::schema_fields_SOURCE_ID);
        $this->seededAclSources[] = $source;
    }

    private function purgeSeededRows(): void
    {
        $sources = \array_values(\array_unique(\array_merge(
            $this->seededLegacySources,
            $this->seededAclSources,
            [
                'TestA_Keep',
                'TestA_Drop',
                'TestB_Keep1',
                'TestB_Keep2',
                'TestA_ChildOfKeep',
                'TestA_Keep_Renamed',
                'TestA_OldRouteId',
                'TestA_NewRouteId',
                'Weline_Backend::template_management',
                'Weline_ThemeFancy::system_frontend_template_fancy',
            ]
        )));
        if ($sources === []) {
            return;
        }
        try {
            $this->menuModel->reset()
                ->where(Menu::schema_fields_SOURCE, $sources, 'in')
                ->delete()
                ->fetch();
        } catch (\Throwable) {
        }
        try {
            $this->aclModel->reset()
                ->where(Acl::schema_fields_SOURCE_ID, $sources, 'in')
                ->delete()
                ->fetch();
        } catch (\Throwable) {
        }
        $this->seededLegacySources = [];
        $this->seededAclSources = [];
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyFileMenu(string $source, string $module): array
    {
        return [
            'name' => $source,
            'title' => $source,
            'source' => $source,
            'parent_source' => '',
            'route' => '',
            'module' => $module,
            'icon' => '',
            'order' => 0,
            'is_system' => 1,
            'is_enable' => 1,
            'is_backend' => 1,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function snapshotAclMenusByModule(): array
    {
        $rows = $this->aclModel->reset()->clearData()
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->where(Acl::schema_fields_MODULE, [self::MODULE_A, self::MODULE_B], 'in')
            ->select()
            ->fetchArray();

        return $this->groupSortedSources($rows, Acl::schema_fields_MODULE, Acl::schema_fields_SOURCE_ID);
    }

    /**
     * @return array<string, list<string>>
     */
    private function snapshotLegacyMenusByModule(): array
    {
        $rows = $this->menuModel->reset()->clearData()
            ->where(Menu::schema_fields_MODULE, [self::MODULE_A, self::MODULE_B], 'in')
            ->select()
            ->fetchArray();

        return $this->groupSortedSources($rows, Menu::schema_fields_MODULE, Menu::schema_fields_SOURCE);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<string>>
     */
    private function groupSortedSources(array $rows, string $moduleKey, string $sourceKey): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $module = (string)($row[$moduleKey] ?? '');
            $source = (string)($row[$sourceKey] ?? '');
            if ($module === '' || $source === '') {
                continue;
            }
            $grouped[$module][] = $source;
        }
        foreach ($grouped as $module => $sources) {
            $sources = \array_values(\array_unique($sources));
            \sort($sources, \SORT_STRING);
            $grouped[$module] = $sources;
        }
        \ksort($grouped, \SORT_STRING);

        return $grouped;
    }
}
