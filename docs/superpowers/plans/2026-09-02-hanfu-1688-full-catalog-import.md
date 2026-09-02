# Hanfu 1688 Full Catalog Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 核验本地全部汉服品牌与供应商的真实 1688 店铺，完整采集每个已核验店铺当前公开且归属明确的全部商品，并以 1688 `offerId` 幂等导入 `website_id=0`，同时补齐或禁用供应商店铺地址。

**Architecture:** 来源阶段以本地品牌、供应商和关系为动态基线，内置浏览器只读核验店铺主体并生成带摘要的来源清单；采集阶段只请求公开 HTTPS 页面，严格沿页面返回的下一页地址遍历并保存不可变快照；导入阶段先 preview，再以快照摘要 apply，通过现有 Product Admin Command、EAV、分类、FileManager 和媒体仓储写入草稿商品。

**Tech Stack:** PHP 8.x、Weline Product Admin Command/Repository、Weline FileManager、PHPUnit、公开 1688 HTML/嵌入 JSON、JSON 运行产物、内置浏览器只读核验和本地后台真实验收。

**Spec:** [汉服 1688 全量商品导入与测试目录清理设计](../specs/2026-09-01-hanfu-1688-full-catalog-design.md)

## Global Constraints

- 只有清理计划的真实 verify 返回 `status=verified` 后，才能执行正式 import apply。
- 来源基线覆盖 `website_id=0` 的全部本地品牌；每个品牌最终状态只能是 `verified`、`unmatched` 或 `duplicate_alias`。
- 店铺主体、品牌归属、供应商店铺 URL、工厂页 URL 和公司名只能来自可回看的公开证据，不得猜测或补造。
- 本地重复品牌 `醉欢楼` 的 `zhl` 与 `zuihuanlou` 在来源清单中指定 canonical brand 和 alias；不删除品牌实体，不产生重复商品。
- 当前盘点中活动但缺少店铺 URL 的供应商 `yimao-huafu`、`huaqianyuexia`、`chenfei-fushi`、`qingcheng-zhilian`、`ruili-hanyun` 必须重新核验；无法核验者改为 disabled，并保存原因与证据。
- 每个 verified source 必须沿页面给出的自然下一页地址采集至明确终点；循环、验证码、登录墙、结构缺失、模糊空页或页数上限均使来源不完整，禁止 apply。
- 不设每品牌商品数量上限；跨店铺重复 `offerId` 只导入一次并报告所有来源。
- 新商品始终为 draft，脚本不得调用 publish。公开价格缺失时不写 Price 行，商品保持不可售草稿并进入报告。
- 产品标题、规格、价格、图片和来源 URL 只保存公开页面可验证的值；不得推断材质、库存、销量、品牌或精细分类。
- 图片保存到本地 FileManager 管理的 `catalog/hanfu/1688/<source-code>/<offerId>/`，禁止把远程热链作为最终媒体。
- 不读取 Cookie、localStorage、sessionStorage、浏览器配置或登录令牌；不下单、不发消息、不收藏、不关注、不联系供应商。
- 网络只允许公开 HTTPS 读取，限速至少 800ms/请求，重定向逐跳校验；媒体只允许受信 1688/阿里 CDN 主机。
- 不修改 `generated/`，不覆盖工作区既有改动。只新增或修改 Service、Repository、脚本和文档，不新增 Controller、Model、Event、Hook、Interface 或注册入口，因此不触发模块版本号变更。
- 每个实现任务遵循红灯测试 → 最小实现 → 绿灯测试 → 中文提交；运行产物只写入 `var/hanfu-1688/<run-id>/`，不提交实时抓取内容。

---

## File Responsibility Map

| File | Responsibility |
|---|---|
| `Service/Hanfu1688/VerifiedSourceManifest.php` | 动态品牌/供应商基线、来源终态和稳定摘要 |
| `Service/Hanfu1688/RunArtifactStore.php` | 运行目录、原子 JSON 读写和权限 |
| `Service/Hanfu1688/FactoryPageParser.php` | 店铺/工厂列表页和自然下一页解析 |
| `Service/Hanfu1688/OfferDetailParser.php` | 商品详情、价格、规格和图片解析 |
| `Service/Hanfu1688/PublicHttpClient.php` | 公开 HTTPS、SSRF 防护、限速和响应上限 |
| `Service/Hanfu1688/CatalogCollector.php` | 全分页采集、详情抓取、终点证明和快照 |
| `Service/Hanfu1688/SourceMapResolver.php` | canonical 品牌、供应商和现有分类归属 |
| `Service/Hanfu1688/MediaImporter.php` | 图片校验、哈希和 FileManager 本地化 |
| `Service/Hanfu1688/ProductPayloadFactory.php` | ProductAdminCommand 的确定性草稿 payload |
| `Service/Hanfu1688/CatalogImportService.php` | preview/apply/verify 和 offerId 幂等 |
| `Service/Hanfu1688/SupplierSourceReconciler.php` | 店铺 URL 补齐、无法核验供应商禁用 |
| `Service/Hanfu1688/CatalogImportRunner.php` | CLI 各阶段的前置产物、摘要和服务编排 |
| `scripts/import-1688-hanfu-catalog.php` | source/collect/preview/apply/verify 分阶段入口 |

## Runtime Contracts

### Source manifest

`verified-sources.json` uses `contract=hanfu.1688.sources.v1` and contains live brand/supplier/link baselines, one terminal `brand_outcomes` record per local brand, verified sources, alias decisions, supplier update decisions, evidence URLs, revision history and `source_digest`.

### Crawl snapshot

`crawl-snapshot.json` uses `contract=hanfu.1688.snapshot.v1` and contains source digest, every visited page URL/fingerprint, per-source terminal proof, normalized offers sorted by numeric `offer_id`, dedupe conflicts, errors, timestamps and `snapshot_digest`.

### Import report

`import-report.json` uses `contract=hanfu.1688.import.v1` and contains source/offer totals, create/update/unchanged/conflict counts, missing prices, root-category fallbacks, media results, supplier updates, postconditions and terminal status.

### CLI

```bash
php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=source-seed --run-id=hanfu-1688-20260902

php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=source-record --run-id=hanfu-1688-20260902 \
  --brand-code="$BRAND_CODE" --supplier-code="$SUPPLIER_CODE" \
  --outcome="$OUTCOME" --shop-url="$SHOP_URL" \
  --factory-url="$FACTORY_URL" --company-name="$COMPANY_NAME" \
  --evidence-url="$EVIDENCE_URL"

php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=source-check --run-id=hanfu-1688-20260902

SOURCE_DIGEST="$(php -r '$d=json_decode(file_get_contents("var/hanfu-1688/hanfu-1688-20260902/verified-sources.json"),true,512,JSON_THROW_ON_ERROR); echo $d["source_digest"];')"
php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=collect --run-id=hanfu-1688-20260902 \
  --source-digest="$SOURCE_DIGEST"

SNAPSHOT_DIGEST="$(php -r '$d=json_decode(file_get_contents("var/hanfu-1688/hanfu-1688-20260902/crawl-snapshot.json"),true,512,JSON_THROW_ON_ERROR); echo $d["snapshot_digest"];')"
php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=preview --run-id=hanfu-1688-20260902 \
  --snapshot-digest="$SNAPSHOT_DIGEST"

php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=apply --run-id=hanfu-1688-20260902 \
  --snapshot-digest="$SNAPSHOT_DIGEST"

php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=verify --run-id=hanfu-1688-20260902 \
  --snapshot-digest="$SNAPSHOT_DIGEST"
```

For `source-record`, assign the six shell variables from the current live baseline record and visible-page evidence immediately before each invocation; never reuse values across brands.

---

### Task 1: Define the complete source manifest and atomic artifacts

**Files:**

- Create: `app/code/Weline/Product/Service/Hanfu1688/VerifiedSourceManifest.php`
- Create: `app/code/Weline/Product/Service/Hanfu1688/RunArtifactStore.php`
- Create: `app/code/Weline/Product/Test/Unit/Service/Hanfu1688/VerifiedSourceManifestTest.php`
- Create: `app/code/Weline/Product/Test/Unit/Service/Hanfu1688/RunArtifactStoreTest.php`

**Interfaces:**

- Consumes: `BrandRepository::listAll(int, ?string): array`, `SupplierRepository::listAll(int, ?string): array` and `SupplierBrandRepository` link lists.
- Produces: `VerifiedSourceManifest::seed()`, `validate()`, `digest()` and `RunArtifactStore::readJson()/writeJson()` used by every later task.

```php
final class VerifiedSourceManifest
{
    public function seed(int $websiteId, array $brands, array $suppliers, array $links): array;
    public function validate(int $websiteId, array $document, array $liveBaseline): array;
    public function digest(array $document): string;
}

final class RunArtifactStore
{
    public function runDirectory(string $runId): string;
    public function readJson(string $runId, string $name): array;
    public function writeJson(string $runId, string $name, array $document): string;
}
```

- [ ] **Step 1: Write the failing manifest tests**

```php
public function testEveryLiveBrandHasExactlyOneTerminalOutcome(): void
{
    $baseline = [
        'brands' => [
            ['brand_id' => 10, 'code' => 'zhl', 'name' => '醉欢楼'],
            ['brand_id' => 11, 'code' => 'zuihuanlou', 'name' => '醉欢楼'],
        ],
        'suppliers' => [['supplier_id' => 20, 'code' => 'zhl-shop', 'status' => 'active']],
        'links' => [['brand_id' => 10, 'supplier_id' => 20]],
    ];
    $document = $this->validDocument($baseline);
    $document['brand_outcomes'] = [
        ['brand_code' => 'zhl', 'outcome' => 'verified'],
        ['brand_code' => 'zuihuanlou', 'outcome' => 'duplicate_alias', 'canonical_brand_code' => 'zhl'],
    ];

    self::assertSame('hanfu.1688.sources.v1', $this->manifest->validate(0, $document, $baseline)['contract']);
}

public function testMissingLiveBrandFailsClosed(): void
{
    $this->expectExceptionMessage('hanfu_1688_brand_coverage_incomplete');
    $this->manifest->validate(0, $this->documentMissingOneBrand(), $this->liveBaseline());
}
```

- [ ] **Step 2: Run tests and verify red**

Run:

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/VerifiedSourceManifestTest.php
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/RunArtifactStoreTest.php
```

Expected: both fail because the classes are absent.

- [ ] **Step 3: Implement canonical digest and atomic write**

```php
public function digest(array $document): string
{
    unset($document['source_digest'], $document['revision_history']);
    $canonical = $this->canonicalize($document);
    return hash(
        'sha256',
        json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );
}

public function writeJson(string $runId, string $name, array $document): string
{
    $path = $this->allowedPath($runId, $name);
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
    $bytes = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
        throw new \RuntimeException('hanfu_1688_artifact_write_failed');
    }
    chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new \RuntimeException('hanfu_1688_artifact_rename_failed');
    }
    return $path;
}
```

Validation requires HTTPS 1688 evidence, acyclic aliases, verified source for each verified outcome and zero unclassified live brands. The artifact allowlist is exactly `verified-sources.json`, `source-evidence.json`, `crawl-snapshot.json`, `collect-report.json`, `import-preview.json`, `import-report.json` and `verification.json`.

- [ ] **Step 4: Run tests and verify green**

Expected: both commands exit `0`.

- [ ] **Step 5: Commit**

```bash
git add app/code/Weline/Product/Service/Hanfu1688/VerifiedSourceManifest.php \
  app/code/Weline/Product/Service/Hanfu1688/RunArtifactStore.php \
  app/code/Weline/Product/Test/Unit/Service/Hanfu1688/VerifiedSourceManifestTest.php \
  app/code/Weline/Product/Test/Unit/Service/Hanfu1688/RunArtifactStoreTest.php
git commit -m "feat: 定义1688品牌来源清单契约"
```

---

### Task 2: Parse listing pages and offer details without inferred fields

**Files:**

- Create: `app/code/Weline/Product/Service/Hanfu1688/FactoryPageParser.php`
- Create: `app/code/Weline/Product/Service/Hanfu1688/OfferDetailParser.php`
- Create: `app/code/Weline/Product/Test/Unit/Service/Hanfu1688/FactoryPageParserTest.php`
- Create: `app/code/Weline/Product/Test/Unit/Service/Hanfu1688/OfferDetailParserTest.php`
- Create: `app/code/Weline/Product/Test/Unit/_files/hanfu1688/factory-page-1.html`
- Create: `app/code/Weline/Product/Test/Unit/_files/hanfu1688/factory-page-last.html`
- Create: `app/code/Weline/Product/Test/Unit/_files/hanfu1688/offer-detail-variants.html`
- Create: `app/code/Weline/Product/Test/Unit/_files/hanfu1688/offer-detail-no-price.html`

**Interfaces:**

```php
final class FactoryPageParser
{
    /** @return array{company_name:string,shop_url:string,offers:list<array<string,mixed>>,next_page_url:?string,terminal_marker:bool} */
    public function parse(string $html, string $pageUrl): array;
}

final class OfferDetailParser
{
    /** @return array<string,mixed> */
    public function parse(string $html, string $detailUrl, string $expectedOfferId): array;
}
```

- [ ] **Step 1: Add minimal synthetic fixtures and failing parser tests**

```php
public function testListingReturnsExactOffersAndNaturalNextPage(): void
{
    $result = (new FactoryPageParser())->parse(
        file_get_contents(__DIR__ . '/../../_files/hanfu1688/factory-page-1.html'),
        'https://www.1688.com/factory/b2b-example.html',
    );

    self::assertSame(['431000001', '431000002'], array_column($result['offers'], 'offer_id'));
    self::assertSame('https://www.1688.com/factory/b2b-example.html?cursor=next-token', $result['next_page_url']);
    self::assertFalse($result['terminal_marker']);
}

public function testMissingPriceRemainsNull(): void
{
    $result = (new OfferDetailParser())->parse(
        file_get_contents(__DIR__ . '/../../_files/hanfu1688/offer-detail-no-price.html'),
        'https://detail.1688.com/offer/431000003.html',
        '431000003',
    );

    self::assertNull($result['min_price']);
    self::assertSame('431000003', $result['offer_id']);
}
```

Fixtures contain only required HTML and embedded JSON shapes, not full copied pages.

- [ ] **Step 2: Run tests and verify red**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/FactoryPageParserTest.php
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/OfferDetailParserTest.php
```

- [ ] **Step 3: Implement structured-state extraction**

```php
private function decodeState(string $html): array
{
    $dom = new \DOMDocument();
    @$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $next = $dom->getElementById('__NEXT_DATA__');
    if ($next !== null) {
        $decoded = json_decode((string)$next->textContent, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    $marker = 'window.__INIT_DATA__';
    $markerOffset = strpos($html, $marker);
    if ($markerOffset !== false) {
        $braceOffset = strpos($html, '{', $markerOffset + strlen($marker));
        if ($braceOffset !== false) {
            $decoded = json_decode($this->extractBalancedJsonObject($html, $braceOffset), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    throw new \RuntimeException('hanfu_1688_structured_state_missing');
}

private function extractBalancedJsonObject(string $html, int $start): string
{
    $depth = 0;
    $quoted = false;
    $escaped = false;
    for ($index = $start, $length = strlen($html); $index < $length; $index++) {
        $char = $html[$index];
        if ($quoted) {
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $quoted = false;
            }
            continue;
        }
        if ($char === '"') {
            $quoted = true;
        } elseif ($char === '{') {
            $depth++;
        } elseif ($char === '}' && --$depth === 0) {
            return substr($html, $start, $index - $start + 1);
        }
    }
    throw new \RuntimeException('hanfu_1688_structured_state_unbalanced');
}
```

The parser rejects login/captcha markers, wrong offer IDs, duplicate variant keys, non-CNY price, non-HTTPS URLs and ambiguous empty pages. Money stays decimal string; null stays null. Listing completion requires an explicit public terminal marker.

- [ ] **Step 4: Run tests and verify green**

Expected: both commands exit `0`.

- [ ] **Step 5: Commit**

```bash
git add app/code/Weline/Product/Service/Hanfu1688/FactoryPageParser.php \
  app/code/Weline/Product/Service/Hanfu1688/OfferDetailParser.php \
  app/code/Weline/Product/Test/Unit/Service/Hanfu1688/FactoryPageParserTest.php \
  app/code/Weline/Product/Test/Unit/Service/Hanfu1688/OfferDetailParserTest.php \
  app/code/Weline/Product/Test/Unit/_files/hanfu1688
git commit -m "feat: 解析1688店铺与商品公开数据"
```

---

### Task 3: Crawl every natural page with bounded public HTTP

**Files:**

- Create: `app/code/Weline/Product/Service/Hanfu1688/PublicHttpClient.php`
- Create: `app/code/Weline/Product/Service/Hanfu1688/CatalogCollector.php`
- Create: `app/code/Weline/Product/Test/Unit/Service/Hanfu1688/PublicHttpClientTest.php`
- Create: `app/code/Weline/Product/Test/Unit/Service/Hanfu1688/CatalogCollectorTest.php`

**Interfaces:**

```php
final class PublicHttpClient
{
    /** @return array{status:int,final_url:string,headers:array<string,string>,body:string,fetched_at:string} */
    public function get(string $url, int $maxBytes): array;
}

final class CatalogCollector
{
    public function __construct(
        PublicHttpClient $http,
        FactoryPageParser $factoryParser,
        OfferDetailParser $detailParser,
        ?\Closure $sleepMilliseconds = null,
    );

    public function collect(array $source, int $requestDelayMs = 800, int $pageLimit = 500): array;
}
```

- [ ] **Step 1: Write failing HTTP safety tests**

```php
#[DataProvider('blockedUrls')]
public function testBlockedTargetsNeverReachTransport(string $url): void
{
    $transport = new RecordingTransport();
    $client = $this->client($transport);

    try {
        $client->get($url, 1_000_000);
        self::fail('blocked URL was accepted');
    } catch (\RuntimeException $exception) {
        self::assertSame('hanfu_1688_url_blocked', $exception->getMessage());
    }
    self::assertSame([], $transport->requests);
}

public static function blockedUrls(): array
{
    return [
        ['http://www.1688.com/factory/example.html'],
        ['https://127.0.0.1/'],
        ['https://user:pass@www.1688.com/'],
        ['https://example.com/offer/1'],
    ];
}
```

Revalidate every redirect, cap redirects at three, enforce connection/read timeouts and compressed/decompressed limits, and assert headers contain no Cookie or Authorization.

- [ ] **Step 2: Write failing full-pagination tests**

```php
public function testCollectorFollowsOnlyReturnedNextUrlsUntilTerminal(): void
{
    $collector = $this->collectorWithPages([
        'https://shop.1688.com/page-a' => $this->page(['1', '2'], 'https://shop.1688.com/cursor-b', false),
        'https://shop.1688.com/cursor-b' => $this->page(['3'], null, true),
    ]);

    $snapshot = $collector->collect($this->source('https://shop.1688.com/page-a'), 800, 500);

    self::assertSame(['1', '2', '3'], array_column($snapshot['offers'], 'offer_id'));
    self::assertSame(
        ['https://shop.1688.com/page-a', 'https://shop.1688.com/cursor-b'],
        array_column($snapshot['pages'], 'url'),
    );
    self::assertTrue($snapshot['complete']);
}
```

Add failures for repeated next URL, fingerprint loop, captcha, ambiguous empty result and page limit. Prove each detail URL is fetched once and no per-brand offer cap exists.

- [ ] **Step 3: Run tests and verify red**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/PublicHttpClientTest.php
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/CatalogCollectorTest.php
```

- [ ] **Step 4: Implement the collector loop**

```php
while ($nextUrl !== null) {
    if (count($pages) >= $pageLimit || isset($visitedUrls[$nextUrl])) {
        throw new \RuntimeException('hanfu_1688_pagination_incomplete');
    }
    ($this->sleepMilliseconds)($requestDelayMs);
    $response = $this->http->get($nextUrl, self::MAX_HTML_BYTES);
    $parsed = $this->factoryParser->parse($response['body'], $response['final_url']);
    $fingerprint = hash('sha256', $response['body']);
    if (isset($visitedFingerprints[$fingerprint])) {
        throw new \RuntimeException('hanfu_1688_page_loop');
    }
    $visitedUrls[$nextUrl] = true;
    $visitedFingerprints[$fingerprint] = true;
    $pages[] = $this->pageEvidence($response, $parsed, $fingerprint);
    $this->mergeOffers($offersById, $parsed['offers']);
    $nextUrl = $parsed['next_page_url'];
    $terminal = $parsed['terminal_marker'];
}
if (!$terminal) {
    throw new \RuntimeException('hanfu_1688_terminal_marker_missing');
}
```

Fetch every unique offer detail once, sort by numeric offer ID, preserve cross-source conflict evidence and digest the canonical source snapshot.

- [ ] **Step 5: Run tests and commit**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/PublicHttpClientTest.php
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/CatalogCollectorTest.php
git add app/code/Weline/Product/Service/Hanfu1688/PublicHttpClient.php \
  app/code/Weline/Product/Service/Hanfu1688/CatalogCollector.php \
  app/code/Weline/Product/Test/Unit/Service/Hanfu1688/PublicHttpClientTest.php \
  app/code/Weline/Product/Test/Unit/Service/Hanfu1688/CatalogCollectorTest.php
git commit -m "feat: 完整分页采集1688公开商品"
```

---

### Task 4: Resolve canonical brand, supplier and existing category

**Files:**

- Create: `app/code/Weline/Product/Service/Hanfu1688/SourceMapResolver.php`
- Create: `app/code/Weline/Product/Test/Unit/Service/Hanfu1688/SourceMapResolverTest.php`

**Interfaces:**

```php
final class SourceMapResolver
{
    /** @return array{brand_id:int,supplier_id:int,category_id:int,classification_status:string,reasons:list<string>} */
    public function resolve(int $websiteId, array $offer, array $source, array $manifest): array;
}
```

- [ ] **Step 1: Write failing resolution tests**

```php
public function testVerifiedSourceIdentityWinsOverTitleBrandText(): void
{
    $resolved = $this->resolver->resolve(
        0,
        ['offer_id' => '431', 'title' => '另一品牌关键词 明制马面裙'],
        $this->verifiedSource('zhl', 'zhl-shop'),
        $this->manifestWithAlias('zuihuanlou', 'zhl'),
    );

    self::assertSame(10, $resolved['brand_id']);
    self::assertSame(20, $resolved['supplier_id']);
    self::assertContains('title_brand_conflict_ignored', $resolved['reasons']);
}

public function testUnknownFineCategoryFallsBackToExistingHanfuRoot(): void
{
    $resolved = $this->resolver->resolve(0, $this->unclassifiedOffer(), $this->verifiedSource(), $this->manifest());
    self::assertSame($this->hanfuRootId, $resolved['category_id']);
    self::assertSame('needs_review', $resolved['classification_status']);
}
```

Missing Hanfu root, unverified source or broken supplier-brand link fails.

- [ ] **Step 2: Run and verify red**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/SourceMapResolverTest.php
```

- [ ] **Step 3: Implement deterministic resolution**

```php
$brandCode = $manifest['aliases'][$source['brand_code']] ?? $source['brand_code'];
$brand = $this->brands->findByCode($websiteId, $brandCode);
$supplier = $this->suppliers->findByCode($websiteId, $source['supplier_code']);
if ($brand === null || $supplier === null) {
    throw new \RuntimeException('hanfu_1688_source_entity_missing');
}
if (!in_array((int)$brand->getId(), $this->supplierBrands->listBrandIdsForSupplier($websiteId, (int)$supplier->getId()), true)) {
    throw new \RuntimeException('hanfu_1688_supplier_brand_link_missing');
}
$category = $this->mostSpecificExistingCategory($websiteId, (string)$offer['title'])
    ?? $this->requireHanfuRoot($websiteId);
```

Do not create categories. Specific mapping uses ordered existing Hanfu paths; fallback imports the product as draft at the Hanfu root with a review flag.

- [ ] **Step 4: Run and commit**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/SourceMapResolverTest.php
git add app/code/Weline/Product/Service/Hanfu1688/SourceMapResolver.php \
  app/code/Weline/Product/Test/Unit/Service/Hanfu1688/SourceMapResolverTest.php
git commit -m "feat: 解析1688商品品牌供应商与分类"
```

---

### Task 5: Download and register product media locally

**Files:**

- Create: `app/code/Weline/Product/Service/Hanfu1688/MediaImporter.php`
- Create: `app/code/Weline/Product/Test/Unit/Service/Hanfu1688/MediaImporterTest.php`

**Interfaces:**

```php
final class MediaImporter
{
    public function import(string $sourceCode, string $offerId, array $imageUrls, bool $preview): array;
}
```

- [ ] **Step 1: Write failing media tests**

```php
public function testImagesAreContentAddressedAndUploadedOnce(): void
{
    $result = $this->importer->import('zhl-shop', '431', [
        'https://cbu01.alicdn.com/img/ibank/valid.jpg',
        'https://cbu01.alicdn.com/img/ibank/valid.jpg',
    ], false);

    self::assertCount(1, $result['assets']);
    self::assertSame(
        'catalog/hanfu/1688/zhl-shop/431/' . hash('sha256', $this->jpegBytes) . '.jpg',
        $result['assets'][0]['object_key'],
    );
    self::assertSame(1, $this->fileLibrary->uploadCount);
}

public function testPreviewDoesNotDownloadOrUpload(): void
{
    $result = $this->importer->import('zhl-shop', '431', ['https://cbu01.alicdn.com/img/ibank/valid.jpg'], true);
    self::assertSame(0, $this->http->requestCount);
    self::assertSame(0, $this->fileLibrary->uploadCount);
    self::assertSame(1, $result['planned_count']);
}
```

Add rejections for host spoofing, redirect escape, MIME/byte mismatch, SVG/script, content over 20 MiB and dimensions over 12000×12000.

- [ ] **Step 2: Run and verify red**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/MediaImporterTest.php
```

- [ ] **Step 3: Implement through FileManager**

```php
$hash = hash('sha256', $bytes);
$objectKey = sprintf(
    'catalog/hanfu/1688/%s/%s/%s.%s',
    $this->safeSegment($sourceCode),
    $this->numericOfferId($offerId),
    $hash,
    $extension,
);
$existing = $this->files->describe('media', $objectKey, $this->access);
$asset = $existing ?? $this->files->upload(
    'media',
    $objectKey,
    $bytes,
    basename(parse_url($url, PHP_URL_PATH)),
    $mimeType,
    'zh_Hans_CN',
    $this->access,
    ['zh_Hans_CN' => ['alt' => '']],
    FileAssetLibraryInterface::VISIBILITY_PUBLIC,
    ['source_platform' => '1688', 'source_offer_id' => $offerId, 'source_url' => $url, 'sha256' => $hash],
    $width,
    $height,
);
```

Only `*.alicdn.com`, `*.1688.com` and `*.tbcdn.cn` media hosts are accepted after DNS and redirect checks.

- [ ] **Step 4: Run and commit**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/MediaImporterTest.php
git add app/code/Weline/Product/Service/Hanfu1688/MediaImporter.php \
  app/code/Weline/Product/Test/Unit/Service/Hanfu1688/MediaImporterTest.php
git commit -m "feat: 本地化1688汉服商品媒体"
```

---

### Task 6: Build deterministic draft payloads and idempotent import

**Files:**

- Modify: `app/code/Weline/Product/Service/ProductCatalogEavBootstrap.php`
- Create: `app/code/Weline/Product/Service/Hanfu1688/ProductPayloadFactory.php`
- Create: `app/code/Weline/Product/Service/Hanfu1688/CatalogImportService.php`
- Create: `app/code/Weline/Product/Test/Unit/Service/Hanfu1688/ProductPayloadFactoryTest.php`
- Create: `app/code/Weline/Product/Test/Unit/Service/Hanfu1688/CatalogImportServiceTest.php`

**Interfaces:**

```php
final class ProductPayloadFactory
{
    public function build(array $offer, array $resolved, array $mediaAssets, string $snapshotDigest): array;
}

final class CatalogImportService
{
    public function preview(int $websiteId, array $snapshot): array;
    public function apply(int $websiteId, array $snapshot, string $snapshotDigest): array;
    public function verify(int $websiteId, array $snapshot, string $snapshotDigest): array;
}
```

- [ ] **Step 1: Write failing payload tests**

```php
public function testPayloadIsDraftAndUsesExactSourceFields(): void
{
    $payload = $this->factory->build(
        $this->offer('431', '128.50'),
        ['brand_id' => 10, 'supplier_id' => 20, 'category_id' => 30, 'classification_status' => 'exact', 'reasons' => []],
        [['asset_id' => 40, 'mime_type' => 'image/jpeg']],
        str_repeat('a', 64),
    );

    self::assertSame('1688-431', $payload['sku']);
    self::assertSame(12850, $payload['prices'][0]['amount']);
    self::assertSame(10, $payload['brand_id']);
    self::assertSame(20, $payload['primary_supplier']['supplier_id']);
    self::assertSame(30, $payload['category_assignments'][0]['category_id']);
    self::assertSame('1688', $this->attribute($payload, 'source_platform'));
    self::assertSame('431', $this->attribute($payload, 'source_offer_id'));
    self::assertArrayNotHasKey('publish', $payload);
}

public function testMissingPriceCreatesNoPriceRow(): void
{
    $payload = $this->factory->build($this->offer('432', null), $this->resolved(), [], str_repeat('b', 64));
    self::assertSame([], $payload['prices']);
}
```

Variant SKUs append the first 12 hex characters of SHA-256 over sorted attributes. The first valid image becomes base/small/thumbnail; all valid images become gallery.

- [ ] **Step 2: Write failing replay tests**

```php
public function testApplyingSameSnapshotTwiceCreatesNothingOnReplay(): void
{
    $first = $this->service->apply(0, $this->snapshot, $this->snapshot['snapshot_digest']);
    $second = $this->service->apply(0, $this->snapshot, $this->snapshot['snapshot_digest']);

    self::assertSame(2, $first['created']);
    self::assertSame(0, $second['created']);
    self::assertSame(0, $second['updated']);
    self::assertSame(2, $second['unchanged']);
    self::assertSame($first['postcondition_counts'], $second['postcondition_counts']);
}
```

Add conflicts for one source ID bound to two products, stable SKU bound to another source, and locally edited published product.

- [ ] **Step 3: Run and verify red**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/ProductPayloadFactoryTest.php
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/CatalogImportServiceTest.php
```

- [ ] **Step 4: Bootstrap exact source attributes**

Register product text attributes `source_platform`, `source_offer_id`, `source_url`, `source_shop_url` and `source_snapshot_digest` through `ProductCatalogEavBootstrap`. Preserve compatible definitions and fail with `hanfu_1688_source_attribute_type_conflict` on an incompatible existing type.

```php
private function hanfu1688SourceAttributes(): array
{
    return [
        ['code' => 'source_platform', 'type' => 'text', 'required' => false],
        ['code' => 'source_offer_id', 'type' => 'text', 'required' => false],
        ['code' => 'source_url', 'type' => 'text', 'required' => false],
        ['code' => 'source_shop_url', 'type' => 'text', 'required' => false],
        ['code' => 'source_snapshot_digest', 'type' => 'text', 'required' => false],
    ];
}
```

- [ ] **Step 5: Implement integer money and command execution**

```php
private function yuanToFen(?string $amount): ?int
{
    if ($amount === null) {
        return null;
    }
    if (preg_match('/^(0|[1-9]\d*)(?:\.(\d{1,2}))?$/', $amount, $match) !== 1) {
        throw new \InvalidArgumentException('hanfu_1688_price_invalid');
    }
    return ((int)$match[1] * 100) + (int)str_pad($match[2] ?? '', 2, '0');
}

private function executeCreate(int $websiteId, array $payload, string $requestHash): ProductAdminResult
{
    return $this->commands->execute(ProductAdminCommand::fromArray([
        'action' => ProductAdminCommand::ACTION_CREATE,
        'website_id' => $websiteId,
        'request_hash' => $requestHash,
        'payload' => $payload,
    ]));
}
```

Find existing products by exact `source_offer_id` EAV index first and `1688-<offerId>` second. Preview is write-free. Apply recomputes and compares the canonical snapshot digest using `hash_equals()`. Create/update only draft source-backed fields and never call publish.

- [ ] **Step 6: Run focused and adjacent tests**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/ProductAdminReadServiceCategoryCatalogContractTest.php
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Controller/Backend/ProductAdminSurfaceContractTest.php
```

Expected: all commands exit `0`.

- [ ] **Step 7: Commit**

```bash
git add app/code/Weline/Product/Service/ProductCatalogEavBootstrap.php \
  app/code/Weline/Product/Service/Hanfu1688/ProductPayloadFactory.php \
  app/code/Weline/Product/Service/Hanfu1688/CatalogImportService.php \
  app/code/Weline/Product/Test/Unit/Service/Hanfu1688/ProductPayloadFactoryTest.php \
  app/code/Weline/Product/Test/Unit/Service/Hanfu1688/CatalogImportServiceTest.php
git commit -m "feat: 幂等导入1688汉服草稿商品"
```

---

### Task 7: Reconcile supplier store URLs and terminal status

**Files:**

- Create: `app/code/Weline/Product/Service/Hanfu1688/SupplierSourceReconciler.php`
- Create: `app/code/Weline/Product/Test/Unit/Service/Hanfu1688/SupplierSourceReconcilerTest.php`

**Interfaces:**

```php
final class SupplierSourceReconciler
{
    public function preview(int $websiteId, array $sourceManifest): array;
    public function apply(int $websiteId, array $sourceManifest): array;
}
```

- [ ] **Step 1: Write failing supplier tests**

```php
public function testVerifiedSourceWritesCanonicalStoreUrlOnly(): void
{
    $result = $this->reconciler->apply(0, $this->manifestWithVerifiedSource(
        'yimao-huafu',
        'https://shop76593h15d75v3.1688.com',
    ));

    self::assertSame(['yimao-huafu'], $result['updated']);
    self::assertSame('https://shop76593h15d75v3.1688.com', $this->suppliers->findByCode(0, 'yimao-huafu')->getData('store_url'));
    self::assertSame('active', $this->suppliers->findByCode(0, 'yimao-huafu')->getData('status'));
}

public function testTerminalUnmatchedDisablesActiveSupplier(): void
{
    $result = $this->reconciler->apply(0, $this->manifestWithUnmatchedEvidence('huaqianyuexia'));
    self::assertSame(['huaqianyuexia'], $result['disabled']);
    self::assertSame('disabled', $this->suppliers->findByCode(0, 'huaqianyuexia')->getData('status'));
}
```

Explicitly classify all five previously missing codes. Reject any company name or URL absent from evidence.

- [ ] **Step 2: Run and verify red**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/SupplierSourceReconcilerTest.php
```

- [ ] **Step 3: Implement through the existing admin service**

```php
$input = [
    'supplier_id' => (int)$supplier->getId(),
    'code' => (string)$supplier->getData('code'),
    'name' => (string)$supplier->getData('name'),
    'status' => $decision['status'],
    'store_url' => $decision['store_url'],
];
$saved = $this->supplierAdmin->save($websiteId, $input);
if ((string)$saved['store_url'] !== (string)$decision['store_url']) {
    throw new \RuntimeException('hanfu_1688_supplier_readback_failed');
}
```

Preview emits exact before/after fields. Apply validates the source digest immediately before writes and preserves unrelated supplier fields.

- [ ] **Step 4: Run and commit**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/SupplierSourceReconcilerTest.php
git add app/code/Weline/Product/Service/Hanfu1688/SupplierSourceReconciler.php \
  app/code/Weline/Product/Test/Unit/Service/Hanfu1688/SupplierSourceReconcilerTest.php
git commit -m "feat: 核验并补齐1688供应商店铺地址"
```

---

### Task 8: Add the staged CLI and contract test

**Files:**

- Create: `app/code/Weline/Product/Service/Hanfu1688/CatalogImportRunner.php`
- Create: `app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php`
- Create: `app/code/Weline/Product/Test/Unit/Service/Hanfu1688/CatalogImportRunnerTest.php`
- Create: `app/code/Weline/Product/Test/Unit/Script/Hanfu1688CatalogImportScriptContractTest.php`

**Interfaces:**

The stages are exactly `source-seed`, `source-record`, `source-check`, `collect`, `preview`, `apply` and `verify`. Exit codes: `0` success, `2` arguments, `3` source drift, `4` incomplete crawl, `5` import conflict, `6` network/media, `7` postcondition.

```php
final class CatalogImportRunner
{
    public function seedSources(int $websiteId, string $runId): array;
    public function recordSource(int $websiteId, string $runId, array $input): array;
    public function checkSources(int $websiteId, string $runId): array;
    public function collect(int $websiteId, string $runId, string $sourceDigest): array;
    public function preview(int $websiteId, string $runId, string $snapshotDigest): array;
    public function apply(int $websiteId, string $runId, string $snapshotDigest): array;
    public function verify(int $websiteId, string $runId, string $snapshotDigest): array;
    private function writeImportReport(
        string $runId,
        string $snapshotDigest,
        array $supplierResult,
        array $importResult,
    ): array;
}
```

- [ ] **Step 1: Write the failing script contract test**

```php
public function testScriptHasAllStagesAndNoExternalWriteBehavior(): void
{
    $source = file_get_contents(__DIR__ . '/../../../scripts/import-1688-hanfu-catalog.php');

    foreach (['source-seed', 'source-record', 'source-check', 'collect', 'preview', 'apply', 'verify'] as $stage) {
        self::assertStringContainsString("'$stage'", $source);
    }
    self::assertStringContainsString('website_id_zero_required', $source);
    self::assertStringNotContainsString('Cookie:', $source);
    self::assertStringNotContainsString('localStorage', $source);
    self::assertStringNotContainsString('ACTION_PUBLISH', $source);
}
```

Also assert apply reads a successful cleanup verification artifact and requires a complete snapshot.

```php
public function testApplyRequiresVerifiedCleanupAndCompleteSnapshot(): void
{
    $runner = $this->runnerWithArtifacts(
        cleanup: ['status' => 'verified'],
        snapshot: ['all_sources_complete' => false, 'snapshot_digest' => str_repeat('a', 64)],
    );

    $this->expectExceptionMessage('hanfu_1688_snapshot_incomplete');
    $runner->apply(0, 'hanfu-1688-20260902', str_repeat('a', 64));
}
```

- [ ] **Step 2: Run and verify red**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/CatalogImportRunnerTest.php
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Script/Hanfu1688CatalogImportScriptContractTest.php
```

- [ ] **Step 3: Implement deterministic stage dispatch**

```php
public function apply(int $websiteId, string $runId, string $snapshotDigest): array
{
    $cleanup = $this->artifacts->readJson('cleanup-20260902', 'verification.json');
    if (($cleanup['status'] ?? '') !== 'verified') {
        throw new \RuntimeException('hanfu_1688_cleanup_not_verified');
    }
    $snapshot = $this->artifacts->readJson($runId, 'crawl-snapshot.json');
    if (($snapshot['all_sources_complete'] ?? false) !== true) {
        throw new \RuntimeException('hanfu_1688_snapshot_incomplete');
    }
    if (!hash_equals($snapshotDigest, (string)$snapshot['snapshot_digest'])) {
        throw new \RuntimeException('hanfu_1688_snapshot_digest_mismatch');
    }
    $sources = $this->artifacts->readJson($runId, 'verified-sources.json');
    $supplierResult = $this->suppliers->apply($websiteId, $sources);
    $importResult = $this->importer->apply($websiteId, $snapshot, $snapshotDigest);
    return $this->writeImportReport($runId, $snapshotDigest, $supplierResult, $importResult);
}
```

```php
$result = match ($options['stage']) {
    'source-seed' => $runner->seedSources(0, $runId),
    'source-record' => $runner->recordSource(0, $runId, $options),
    'source-check' => $runner->checkSources(0, $runId),
    'collect' => $runner->collect(0, $runId, $options['source-digest']),
    'preview' => $runner->preview(0, $runId, $options['snapshot-digest']),
    'apply' => $runner->apply(0, $runId, $options['snapshot-digest']),
    'verify' => $runner->verify(0, $runId, $options['snapshot-digest']),
    default => throw new \InvalidArgumentException('hanfu_1688_stage_invalid'),
};
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
```

`source-record` updates one exact baseline record and appends revision evidence. `collect` requires terminal source coverage. `preview` performs no writes. `apply` reconciles suppliers, imports media/products and writes per-offer results. `verify` compares live source IDs with the snapshot.

- [ ] **Step 4: Lint and run tests**

```bash
php -l app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688/CatalogImportRunnerTest.php
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Script/Hanfu1688CatalogImportScriptContractTest.php
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688
```

Expected: lint succeeds and all tests exit `0`.

- [ ] **Step 5: Commit**

```bash
git add app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  app/code/Weline/Product/Service/Hanfu1688/CatalogImportRunner.php \
  app/code/Weline/Product/Test/Unit/Service/Hanfu1688/CatalogImportRunnerTest.php \
  app/code/Weline/Product/Test/Unit/Script/Hanfu1688CatalogImportScriptContractTest.php
git commit -m "feat: 增加1688汉服全量导入命令"
```

---

### Task 9: Verify every local brand and supplier in the signed-in in-app browser

**Files:**

- Runtime artifact: `var/hanfu-1688/hanfu-1688-20260902/verified-sources.json`
- Runtime evidence: `var/hanfu-1688/hanfu-1688-20260902/source-evidence.json`
- Runtime screenshots: `var/hanfu-1688/hanfu-1688-20260902/evidence/`

**Interfaces:**

- Consumes: `VerifiedSourceManifest::seed()/validate()/digest()`, live brand/supplier/link baselines and visible 1688 evidence.
- Produces: complete `hanfu.1688.sources.v1` with terminal brand outcomes and exact `source_digest`.

- [ ] **Step 1: Seed the live source baseline**

```bash
php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=source-seed --run-id=hanfu-1688-20260902
```

Read back the artifact. The earlier 31 brands, 68 suppliers and 23 active suppliers are comparison evidence, not hardcoded replacements for live results.

- [ ] **Step 2: Use only the signed-in in-app browser**

Load `browser:control-in-app-browser`. Start at `https://www.1688.com/` in the user's existing session. Do not inspect browser storage or switch to Chrome.

- [ ] **Step 3: Resolve every brand outcome**

For each live brand, follow local supplier-brand links, open candidate 1688 shop/factory pages, compare visible company/shop identity, record verified URLs and company name or terminal unmatched evidence, record aliases, and capture visible-page evidence. Use `source-record` for every decision so the manifest retains revision history.

- [ ] **Step 4: Resolve every supplier address outcome**

Check all active suppliers and explicitly classify `yimao-huafu`, `huaqianyuexia`, `chenfei-fushi`, `qingcheng-zhilian` and `ruili-hanyun`. No active supplier leaves this stage without a verified URL or terminal unmatched evidence.

- [ ] **Step 5: Seal the source manifest**

```bash
php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=source-check --run-id=hanfu-1688-20260902
```

Expected: `brand_coverage_percent=100`, `pending_outcome_count=0`, aliases acyclic, every verified source has evidence and `source_digest` is 64 lowercase hexadecimal characters.

---

### Task 10: Collect every verified shop and prove snapshot completeness

**Files:**

- Runtime artifact: `var/hanfu-1688/hanfu-1688-20260902/crawl-snapshot.json`
- Runtime artifact: `var/hanfu-1688/hanfu-1688-20260902/collect-report.json`

**Interfaces:**

- Consumes: the sealed `source_digest` and `CatalogCollector::collect()`.
- Produces: complete `hanfu.1688.snapshot.v1` with unique offers and exact `snapshot_digest`.

- [ ] **Step 1: Run the real collector with the sealed digest**

```bash
SOURCE_DIGEST="$(php -r '$d=json_decode(file_get_contents("var/hanfu-1688/hanfu-1688-20260902/verified-sources.json"),true,512,JSON_THROW_ON_ERROR); echo $d["source_digest"];')"
php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=collect --run-id=hanfu-1688-20260902 \
  --source-digest="$SOURCE_DIGEST"
```

- [ ] **Step 2: Verify terminal evidence for every source**

Require at least one visited page, unique URL/fingerprint chain, parser-returned next-page transitions and explicit terminal marker per source. Offer total equals the union of normalized numeric offer IDs. Captcha, login wall or ambiguous empty result keeps the snapshot incomplete.

- [ ] **Step 3: Cross-check visible listing evidence**

In the in-app browser, open the first and terminal listing pages for each source when visible navigation permits; compare displayed offer IDs/count indicators with the snapshot. Record deviations instead of forcing parser success.

- [ ] **Step 4: Seal the crawl snapshot**

Expected: `all_sources_complete=true`, `unresolved_offer_count=0`, `snapshot_digest` is 64 lowercase hexadecimal characters, and no authenticated request metadata exists.

---

### Task 11: Preview, apply twice and verify the complete import

**Files:**

- Runtime artifact: `var/hanfu-1688/hanfu-1688-20260902/import-preview.json`
- Runtime artifact: `var/hanfu-1688/hanfu-1688-20260902/import-report.json`
- Runtime artifact: `var/hanfu-1688/hanfu-1688-20260902/verification.json`

**Interfaces:**

- Consumes: `CatalogImportService::preview()/apply()/verify()` and the sealed snapshot.
- Produces: one draft product per unique source offer, idempotent replay evidence and real backend browser acceptance.

- [ ] **Step 1: Run preview**

```bash
SNAPSHOT_DIGEST="$(php -r '$d=json_decode(file_get_contents("var/hanfu-1688/hanfu-1688-20260902/crawl-snapshot.json"),true,512,JSON_THROW_ON_ERROR); echo $d["snapshot_digest"];')"
php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=preview --run-id=hanfu-1688-20260902 \
  --snapshot-digest="$SNAPSHOT_DIGEST"
```

Expected: write count `0`; `create + update + unchanged + conflict` equals unique snapshot offer count; every offer has brand, supplier and existing category/root fallback.

- [ ] **Step 2: Resolve conflicts before apply**

Every conflict names an offer ID and exact reason. Repair source mapping, local duplicate or parser defect; when source data changes, create a new immutable snapshot and preview it. Proceed only with `conflict_count=0`.

- [ ] **Step 3: Apply the exact previewed snapshot**

```bash
php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=apply --run-id=hanfu-1688-20260902 \
  --snapshot-digest="$SNAPSHOT_DIGEST"
```

Expected: one local draft product per unique `offerId`, local FileManager media, verified supplier URLs, unmatched active suppliers disabled, and missing-price products without sellable price.

- [ ] **Step 4: Prove idempotency by applying the same snapshot again**

Run the same apply command. Expected: `created=0`, `updated=0`, `unchanged=unique_offer_count`, stable product/offer/media counts and no duplicate source IDs.

- [ ] **Step 5: Run explicit verification**

```bash
php app/code/Weline/Product/scripts/import-1688-hanfu-catalog.php \
  --website=0 --stage=verify --run-id=hanfu-1688-20260902 \
  --snapshot-digest="$SNAPSHOT_DIGEST"
```

Expected: `status=verified`; imported source-ID set equals snapshot offer-ID set; all imported products are draft; supplier outcomes match the source manifest; every asset resolves locally.

- [ ] **Step 6: Verify the real local backend in the in-app browser**

Open the configured product backend for `website_id=0` and inspect representative first/middle/last records, brand filters, category, supplier, draft status, source URL, price/no-price and local gallery. Open the supplier backend and verify corrected store URLs plus disabled unmatched suppliers. Record exact delivery URLs and screenshots.

- [ ] **Step 7: Run regression checks**

```bash
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Service/Hanfu1688
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Script/Hanfu1688CatalogImportScriptContractTest.php
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/Controller/Backend/ProductAdminSurfaceContractTest.php
php vendor/bin/phpunit app/code/Weline/Product/Test/Unit/View/ProductBrandAdminContractTest.php
git diff --check
```

Expected: all tests exit `0` and diff check is empty.

---

### Task 12: Align documentation and close out with runtime evidence

**Files:**

- Modify: `app/code/Weline/Product/doc/需求.md`
- Modify: `app/code/Weline/Product/doc/功能现状.md`
- Modify: `app/code/Weline/Product/doc/开发日志.md`

**Interfaces:**

- Consumes: completed source, crawl, import and verification artifacts.
- Produces: operator contract and observed acceptance record for future maintenance.

- [ ] **Step 1: Document the complete contract**

Document source verification, digests, natural pagination, public HTTP limits, offerId idempotency, draft/no-price behavior, category fallback, local media keys, supplier disabling, artifact paths, commands and recovery.

- [ ] **Step 2: Record observed runtime totals**

Add the completed run ID, verified-source count, unique offer count, create and replay-unchanged counts, missing-price count and browser acceptance URLs using exact artifact values.

- [ ] **Step 3: Run documentation checks**

```bash
rg -n "hanfu.1688.sources.v1|hanfu.1688.snapshot.v1|hanfu.1688.import.v1|offerId|source_digest|snapshot_digest" \
  app/code/Weline/Product/doc
git diff --check
```

Expected: every contract term is found and `git diff --check` emits no output.

- [ ] **Step 4: Commit documentation**

```bash
git add app/code/Weline/Product/doc/需求.md \
  app/code/Weline/Product/doc/功能现状.md \
  app/code/Weline/Product/doc/开发日志.md
git commit -m "docs: 记录1688汉服全量导入运行契约"
```

- [ ] **Step 5: Final import acceptance**

Confirm no `generated/` change, no committed crawl snapshot/media, no credential access, no external write operation and no unrelated worktree file. Run Weline task-plan review and require `closeout_allowed=true` before reporting import complete.

---

## Spec Coverage Matrix

| Design section | Plan coverage |
|---|---|
| 1 confirmed decisions | Global Constraints, Tasks 9-11 |
| 2.2 import scope | Runtime Contracts, Tasks 3-11 |
| 2.3 non-goals | Global Constraints, Tasks 3, 8 and 12 |
| 3 invariants | Tasks 1, 3, 6, 7 and 11 |
| 4 solution choice | Source → snapshot → preview → apply → verify architecture |
| 5.2 store verification/source manifest | Tasks 1, 7 and 9 |
| 5.3 full collector | Tasks 2, 3 and 10 |
| 5.4 brand/category resolution | Task 4 |
| 5.5 idempotent importer | Tasks 5-8 and 11 |
| 6 data flow | Runtime Contracts, Tasks 9-11 |
| 7 runtime artifacts | Runtime Contracts and Tasks 9-11 |
| 8 errors and recovery | Tasks 1-8 and Task 11 conflict handling |
| 9 tests and runtime acceptance | Every task, especially Tasks 10-11 |
| 10 delivery and safety | Global Constraints, Tasks 9, 11 and 12 |
