<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * 冷启动性能架构契约（源自 2026-11-03 全冷穿章 debug 会话）。
 *
 * 每条契约对应一类「已实测造成全冷请求 1–3s 且已修复」的问题；用源码扫描
 * 断言把修复固化为结构约束——回归时测试阶段即爆，而不是等线上慢了再查。
 *
 * 证据基线（当次会话观测值）：
 *  - 全冷 /products 穿章 3351ms → 修复后 104–1327ms（DB 1919→216–465ms）
 *  - attribute_value 6 次/854.7ms → db_top 0 条
 *  - promotion_theme_deal 38.4ms → 0.7ms；媒体 snapshot.media_url 194→9.3ms
 */
final class ColdStartArchitectureContractTest extends TestCase
{
    /** 项目根：本测试文件位于 <root>/app/code/Weline/Framework/Test/Unit/Architecture/（上 8 级）。 */
    private const BP = __DIR__ . '/../../../../../../..';

    /** @var array<string, string> */
    private array $sourceCache = [];

    private function source(string $relative): string
    {
        $key = $relative;
        if (!isset($this->sourceCache[$key])) {
            $path = self::BP . '/' . $relative;
            if (!is_file($path)) {
                self::fail('契约目标文件缺失: ' . $relative . ' (解析为 ' . $path . ')');
            }
            $this->sourceCache[$key] = (string)file_get_contents($path);
        }

        return $this->sourceCache[$key];
    }

    public function testAllColdStartContractsPass(): void
    {
        $failures = [];
        foreach ($this->contracts() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ': ' . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contracts(): array
    {
        return [
            // C1：常驻 Worker 的引导守卫必须按进程判定。静态布尔会被 fork 的
            // 子进程继承，导致 Worker 跳过引导、启动即退出（Nginx 503）。
            'wls bootstrap guard is pid-scoped' => function (): void {
                $src = $this->source('app/code/Weline/Server/Runtime/WlsRuntime.php');
                self::assertStringContainsString('getmypid()', $src,
                    'WlsRuntime 引导守卫必须用 getmypid() 判定进程（静态布尔会被 fork 继承）');
                self::assertStringNotContainsString('private static bool $processBootstrapped', $src,
                    '禁止静态布尔引导守卫（fork 后子进程继承 true 跳过引导）');
            },

            // C2：库存可用性查询禁止 SELECT *（可用性计算只读 7 个数值/枚举列）。
            'inventory availability query selects explicit columns' => function (): void {
                $src = $this->source('app/code/Weline/Inventory/Service/InventoryService.php');
                self::assertStringContainsString('->fields([', $src,
                    'getAvailabilities 的库存查询必须 fields([...]) 列裁剪（禁止 SELECT * 读宽行）');
            },

            // C3：CMS 页按 identifier 查找必须有请求级 memo（同页多处按同一
            // identifier 取 banner/内容块，冷态单条 find 105ms）。
            'cms page identifier lookup is request-memoized' => function (): void {
                $src = $this->source('app/code/Weline/Cms/Service/PageService.php');
                self::assertStringContainsString('cms.page.by_identifier.', $src,
                    'getPageByIdentifier 必须带请求级 memo（cms.page.by_identifier. 前缀）');
            },

            // C4：活动主题本地化文案查找必须有请求级 memo（每主题一次 find，
            // 冷态单条 130ms；header 导航/活动卡片会重复取）。
            'promotion theme localized copy is request-memoized' => function (): void {
                $src = $this->source('app/code/Weline/Promotion/Service/PromotionActivityThemeService.php');
                self::assertStringContainsString('promotion.theme_local_copy.', $src,
                    'resolveLocalizedCopy 必须带请求级 memo（promotion.theme_local_copy. 前缀）');
            },

            // C5：attribute_value 行集合查询必须有请求级超集复用（卡片快照、
            // facet 计数、定价对同一批 entity_id 各查一次，全冷 6 次/855ms）。
            'attribute value rows support request superset reuse' => function (): void {
                $src = $this->source('app/code/Weline/Product/Repository/AttributeValueRepository.php');
                self::assertStringContainsString("eav.superset.", $src,
                    'listExplicitRows 必须维护请求级超集行集合（eav.superset. 前缀），子集调用 PHP 过滤');
            },

            // C6：列表页媒体解析必须走批量入口（逐条 resolveListingOffer 会对
            // 每个卡片各发一次批量资源查询，实测 194ms）。
            'listing media hydration uses batch resolver' => function (): void {
                $src = $this->source('app/code/Weline/Product/Service/StorefrontCatalogViewService.php');
                self::assertStringContainsString('resolveListingOffers($offers', $src,
                    'hydrateListingMedia 必须调用 resolveListingOffers（整页 prime 一次）');
                self::assertStringNotContainsString('$this->mediaUrls->resolveListingOffer($offer, $scope, $locale);', $src,
                    'hydrateListingMedia 禁止逐条 resolveListingOffer（每卡一次批量解析）');
            },

            // C7：目录构建的分页取行与全流程处理必须分离（分页循环内递归完整
            // build 会把 snapshot/定价/surface 按页重复跑，实测占全冷 98%）。
            'catalog pagination only pages rows then builds once' => function (): void {
                $src = $this->source('app/code/Weline/Product/Service/StorefrontCatalogViewService.php');
                $paginationPos = strpos($src, 'listPublishedRepresentativePage');
                self::assertNotFalse($paginationPos, '分页取行入口必须存在');
                $nextBuildPos = strpos($src, '$this->buildPublishedOffers(', $paginationPos);
                self::assertNotFalse($nextBuildPos, '分页之后必须调用一次 build');
                $segment = substr($src, $paginationPos, $nextBuildPos - $paginationPos);
                self::assertStringNotContainsString('$this->buildPublishedOffers(', $segment,
                    '分页循环体内禁止递归完整 build（会按页重复 snapshot/定价/surface）');
            },

            // C8：请求级 trace 账本的 phase 名上限不得低于 128——低于此值会把
            // 列表页后段（filters.panel/footer/summary）整批挤掉，形成「无打点
            // 黑盒」假象，debug 时误判为无埋点代码。
            'request trace phase ledger holds full listing-page breakdown' => function (): void {
                $src = $this->source('app/code/Weline/Framework/Runtime/RequestLifecycleTrace.php');
                if (preg_match('/MAX_SUMMARY_PHASES\s*=\s*(\d+)/', $src, $m) === 1) {
                    self::assertGreaterThanOrEqual(128, (int)$m[1],
                        'MAX_SUMMARY_PHASES >= 128（48 会截断列表页后段 phase，制造无打点黑盒）');
                }
            },

            // C9：跨模块类引用必须写 use/FQCN——裸引用会在运行期解析到错误
            // 命名空间并 fatal（ThemeUpdateRuntime 曾因裸写 ThemeUpdateBatchService
            // 炸掉 CLI 缓存键路径：Class "Weline\Framework\Deploy\ThemeUpdateBatchService" not found）。
            'theme update runtime imports cross-module batch service' => function (): void {
                $src = $this->source('app/code/Weline/Theme/Service/ThemeUpdate/ThemeUpdateRuntime.php');
                self::assertStringContainsString('use Weline\\Theme\\Service\\ThemeUpdate\\ThemeUpdateBatchService;', $src,
                    'ThemeUpdateRuntime 必须显式 use 跨模块的 ThemeUpdateBatchService（裸引用解析到不存在的 Framework\\Deploy\\ 下）');
                // 代码里（剔除注释行）不得出现裸引用形式
                $codeLines = array_filter(
                    preg_split('/\r?\n/', $src),
                    static fn(string $line): bool => strpos(ltrim($line), '//') !== 0
                        && strpos(ltrim($line), '*') !== 0
                        && strpos(ltrim($line), '/*') !== 0,
                );
                $bareUse = array_filter(
                    $codeLines,
                    static fn(string $line): bool => preg_match('/(?<!use )Weline\\\\Framework\\\\Deploy\\\\ThemeUpdateBatchService/', $line) === 1,
                );
                self::assertSame([], array_values($bareUse),
                    '代码行禁止裸写 Weline\\Framework\\Deploy\\ThemeUpdateBatchService（该类不存在）');
            },

            // C10：预热命令必须识别维护模式（否则会把整份清单跑成一片 FAIL，
            // 且掩盖真正的 503 原因）。
            'fpc prewarm refuses to run under maintenance mode' => function (): void {
                $src = $this->source('app/code/Weline/Theme/Service/Warmup/StorefrontFpcPrewarmer.php');
                self::assertStringContainsString('x-weline-maintenance', $src,
                    '预热前必须探测维护模式（x-weline-maintenance 头），命中即跳过并提示');
            },

            // C11：预热命令必须先清空布局固化队列（固化 pending 时页面走回退
            // 且被禁止进共享缓存，预热等于白跑）。
            'fpc prewarm drains solidify queue first' => function (): void {
                $src = $this->source('app/code/Weline/Theme/Console/Theme/FpcPrewarm.php');
                self::assertStringContainsString('drainPendingJobs(', $src,
                    '预热前必须 drainPendingJobs（固化 pending 时预热无效）');
            },

            // C12：列表定价（fromCatalogMinor）禁止枚举 eligible campaign choices。
            // Observed /products 穿章对 334 SKU 各调一次 listEligible + measurePhase
            // （promotion_theme_deal 38–100ms）；网格不渲染选择器，PDP 才需要。
            'listing price context skips eligible campaign choices' => function (): void {
                $ctx = $this->source('app/code/Weline/Product/Api/Data/StorefrontPriceContext.php');
                self::assertStringContainsString('includeEligibleCampaignChoices: false',
                    $ctx,
                    'fromCatalogMinor 必须关闭 includeEligibleCampaignChoices');
                $assembler = $this->source('app/code/Weline/Product/Service/Storefront/StorefrontOfferPriceAssembler.php');
                self::assertStringContainsString('includeEligibleCampaignChoices',
                    $assembler,
                    'Assembler 必须尊重 includeEligibleCampaignChoices 门闩');
                self::assertStringContainsString('listEligibleCampaignChoices',
                    $assembler,
                    'Assembler 仍须保留 PDP 的 listEligibleCampaignChoices 分支');
            },

            // C13：Worker READY 路径上的 process FPC hydrate 路径数必须有硬上限，
            // 长尾分类/PDP 走异步 theme:fpc-prewarm，避免 bootstrap OOM/超时。
            'worker bootstrap fpc warmup path cap is bounded' => function (): void {
                $src = $this->source('app/code/Weline/Theme/Observer/WorkerBootstrapWarmup.php');
                self::assertStringContainsString(
                    "fpc_warmup_max_paths', 24)",
                    $src,
                    'READY 路径 FPC hydrate 默认上限须 ≤24（长尾交给异步预热）',
                );
                self::assertStringContainsString(
                    'min($maxPaths, 64)',
                    $src,
                    'READY 路径 FPC hydrate 硬顶须 ≤64',
                );
            },
        ];
    }
}
