# Websites AI建站工作台接口草图

本文件给出一版偏实现导向的接口草图，目标是：

1. 让 `Weline_Websites` 只依赖抽象

3. 让 Theme 通过独立 theme source 能力接入
4. 避免核心模块知道 provider 私有字段

## 1. 设计原则

1. 核心会话模型只保存平台共识字段
2. provider 私有状态进入 `provider_state_json` 或 artifact payload
3. conversation、domain、theme、draft、materialize、preview 分离成小接口
4. provider 只是 capabilities 的组合入口

## 2. DTO 草图

```php
<?php

namespace Weline\Websites\Api\Data;

final class AiSiteBuilderSessionContext
{
    public function __construct(
        public readonly int $sessionId,
        public readonly string $publicId,
        public readonly int $adminUserId,
        public readonly string $providerCode,
        public readonly string $currentStage,
        public readonly array $scope,
        public readonly array $providerState,
        public readonly int $websiteId = 0,
        public readonly string $selectedDomain = '',
        public readonly int $registrarAccountId = 0,
    ) {}
}

final class SiteProfileDraft
{
    public function __construct(
        public readonly string $siteTitle,
        public readonly string $brandTone,
        public readonly string $targetAudience,
        public readonly string $industry,
        public readonly string $heroMessage,
        public readonly array $pageSuggestions = [],
        public readonly array $extra = [],
    ) {}
}

final class DomainCandidate
{
    public function __construct(
        public readonly string $domain,
        public readonly bool $available,
        public readonly string $reason = '',
        public readonly array $extra = [],
    ) {}
}

final class ThemeCandidate
{
    public function __construct(
        public readonly string $sourceCode,
        public readonly string $themeCode,
        public readonly string $name,
        public readonly string $description,
        public readonly string $previewImage = '',
        public readonly array $pageTypes = [],
        public readonly array $bindingPayload = [],
    ) {}
}

final class PageTypeDefinition
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $description,
        public readonly bool $defaultSelected = false,
        public readonly array $promptHints = [],
        public readonly array $extra = [],
    ) {}
}

final class PageDraftDefinition
{
    public function __construct(
        public readonly string $pageTypeCode,
        public readonly string $title,
        public readonly array $content,
        public readonly array $providerBindings = [],
    ) {}
}

final class MaterializationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $websiteId = 0,
        public readonly string $message = '',
        public readonly array $providerBindings = [],
    ) {}
}

final class PreviewDescriptor
{
    public function __construct(
        public readonly bool $available,
        public readonly string $url = '',
        public readonly string $label = '',
        public readonly array $extra = [],
    ) {}
}
```

## 3. Registry 抽象

```php
<?php

namespace Weline\Websites\Api;

interface AiSiteBuilderProviderRegistryInterface
{
    public function get(string $providerCode): AiSiteBuilderProviderInterface;

    /**
     * @return AiSiteBuilderProviderInterface[]
     */
    public function all(bool $onlyEnabled = true): array;
}

interface WebsiteThemeSourceRegistryInterface
{
    public function get(string $sourceCode): WebsiteThemeSourceInterface;

    /**
     * @return WebsiteThemeSourceInterface[]
     */
    public function all(bool $onlyEnabled = true): array;
}
```

## 4. Provider 顶层接口

```php
<?php

namespace Weline\Websites\Api;

interface AiSiteBuilderProviderInterface
{
    public function getCode(): string;

    public function getName(): string;

    public function getDescription(): string;

    public function isEnabled(): bool;

    public function getSortOrder(): int;

    public function conversation(): AiSiteBuilderConversationInterface;

    public function domainWorkflow(): AiSiteBuilderDomainWorkflowInterface;

    public function themeWorkflow(): AiSiteBuilderThemeWorkflowInterface;

    public function draftWorkflow(): AiSiteBuilderDraftWorkflowInterface;

    public function materializer(): AiSiteBuilderMaterializerInterface;

    public function previewResolver(): AiSiteBuilderPreviewResolverInterface;
}
```

说明：

1. provider 只返回能力接口

3. `websites_default` provider 可在内部组合 ThemeSourceRegistry

## 5. 分能力接口

### 5.1 对话

```php
<?php

namespace Weline\Websites\Api;

use Weline\Websites\Api\Data\AiSiteBuilderSessionContext;
use Weline\Websites\Api\Data\SiteProfileDraft;

interface AiSiteBuilderConversationInterface
{
    public function getInitialStage(): string;

    /**
     * @return array<int, array{role:string, content:string, type?:string}>
     */
    public function bootstrapMessages(AiSiteBuilderSessionContext $context): array;

    public function handleUserMessage(
        AiSiteBuilderSessionContext $context,
        string $message
    ): SiteProfileDraft;
}
```

### 5.2 域名流程

```php
<?php

namespace Weline\Websites\Api;

use Weline\Websites\Api\Data\AiSiteBuilderSessionContext;
use Weline\Websites\Api\Data\DomainCandidate;

interface AiSiteBuilderDomainWorkflowInterface
{
    /**
     * @return DomainCandidate[]
     */
    public function suggestDomains(AiSiteBuilderSessionContext $context): array;

    /**
     * 执行前只做校验，不产生真实购买动作
     */
    public function validateDomainSelection(
        AiSiteBuilderSessionContext $context,
        string $domain,
        int $registrarAccountId
    ): void;

    /**
     * 真正的购买动作，必须在 UI 显式确认后调用
     */
    public function purchaseDomain(
        AiSiteBuilderSessionContext $context,
        string $domain,
        int $registrarAccountId
    ): void;

    /**
     * 返回用于顶部状态条的标准化状态
     */
    public function getLifecycleSnapshot(
        AiSiteBuilderSessionContext $context
    ): array;
}
```

### 5.3 主题流程

```php
<?php

namespace Weline\Websites\Api;

use Weline\Websites\Api\Data\AiSiteBuilderSessionContext;
use Weline\Websites\Api\Data\ThemeCandidate;
use Weline\Websites\Api\Data\PageTypeDefinition;

interface AiSiteBuilderThemeWorkflowInterface
{
    /**
     * @return ThemeCandidate[]
     */
    public function listThemeCandidates(AiSiteBuilderSessionContext $context): array;

    /**
     * @return PageTypeDefinition[]
     */
    public function listPageTypesForTheme(
        AiSiteBuilderSessionContext $context,
        ThemeCandidate $themeCandidate
    ): array;
}
```

### 5.4 草稿生成

```php
<?php

namespace Weline\Websites\Api;

use Weline\Websites\Api\Data\AiSiteBuilderSessionContext;
use Weline\Websites\Api\Data\ThemeCandidate;
use Weline\Websites\Api\Data\PageDraftDefinition;
use Weline\Websites\Api\Data\PageTypeDefinition;

interface AiSiteBuilderDraftWorkflowInterface
{
    /**
     * @param PageTypeDefinition[] $pageTypes
     * @return PageDraftDefinition[]
     */
    public function generateDrafts(
        AiSiteBuilderSessionContext $context,
        ThemeCandidate $themeCandidate,
        array $pageTypes,
        string $prompt = ''
    ): array;

    public function refineDraft(
        AiSiteBuilderSessionContext $context,
        string $pageTypeCode,
        array $patch,
        string $prompt = ''
    ): PageDraftDefinition;
}
```

### 5.5 物料化

```php
<?php

namespace Weline\Websites\Api;

use Weline\Websites\Api\Data\AiSiteBuilderSessionContext;
use Weline\Websites\Api\Data\MaterializationResult;

interface AiSiteBuilderMaterializerInterface
{
    public function materialize(AiSiteBuilderSessionContext $context): MaterializationResult;
}
```

### 5.6 预览

```php
<?php

namespace Weline\Websites\Api;

use Weline\Websites\Api\Data\AiSiteBuilderSessionContext;
use Weline\Websites\Api\Data\PreviewDescriptor;

interface AiSiteBuilderPreviewResolverInterface
{
    public function resolvePreview(AiSiteBuilderSessionContext $context): PreviewDescriptor;
}
```

## 6. Theme Source 接口

```php
<?php

namespace Weline\Websites\Api;

use Weline\Websites\Api\Data\AiSiteBuilderSessionContext;
use Weline\Websites\Api\Data\ThemeCandidate;

interface WebsiteThemeSourceInterface
{
    public function getCode(): string;

    public function getName(): string;

    public function getDescription(): string;

    public function isEnabled(): bool;

    public function getSortOrder(): int;

    /**
     * @return ThemeCandidate[]
     */
    public function listThemes(AiSiteBuilderSessionContext $context): array;
}
```

说明：

1. `Weline_Theme` 实现这个接口，为默认 provider 暴露主题方案

3. `bindingPayload` 用来携带 theme 侧私有引用

## 7. Repository 抽象

```php
<?php

namespace Weline\Websites\Api;

use Weline\Websites\Api\Data\AiSiteBuilderSessionContext;

interface AiSiteBuilderSessionRepositoryInterface
{
    public function create(string $providerCode, int $adminUserId, array $scope = []): AiSiteBuilderSessionContext;

    public function getByPublicId(string $publicId, int $adminUserId): ?AiSiteBuilderSessionContext;

    public function saveScope(int $sessionId, array $scope): void;

    public function saveProviderState(int $sessionId, array $providerState): void;

    public function setStage(int $sessionId, string $stage): void;

    public function bindWebsite(int $sessionId, int $websiteId): void;

    public function bindDomain(int $sessionId, string $domain, int $registrarAccountId): void;

    public function setPreviewUrl(int $sessionId, string $previewUrl): void;
}

interface AiSiteBuilderMessageRepositoryInterface
{
    public function append(int $sessionId, string $role, string $content, string $type = 'message', array $toolPayload = []): void;

    public function listForSession(int $sessionId, int $limit = 200): array;
}

interface AiSiteBuilderArtifactRepositoryInterface
{
    public function upsert(int $sessionId, string $artifactType, string $artifactCode, array $payload, string $title = '', string $status = 'ready'): void;

    public function listByType(int $sessionId, string $artifactType): array;

    public function getOne(int $sessionId, string $artifactType, string $artifactCode): ?array;
}

interface AiSiteBuilderEventRepositoryInterface
{
    public function append(int $sessionId, string $stageCode, string $eventType, array $payload = [], string $level = 'info'): int;

    public function listAfterId(int $sessionId, int $afterEventId, int $limit = 100): array;
}
```

## 8. 工作台 API 草图

建议 API：

1. `GET websites/backend/site-builder-agent/index`
   - 工作台首页
2. `POST websites/backend/site-builder-agent/create-session`
   - 创建 session，必须带 `provider_code`
3. `GET websites/backend/site-builder-agent/state-json`
   - 获取 session 全量状态
4. `POST websites/backend/site-builder-agent/post-message`
   - 用户发送消息
5. `POST websites/backend/site-builder-agent/post-select-domain`
   - 选择域名与账号
6. `POST websites/backend/site-builder-agent/post-confirm-domain-purchase`
   - 真正购买
7. `GET websites/backend/site-builder-agent/domain-status-json`
   - 获取域名生命周期快照
8. `GET websites/backend/site-builder-agent/theme-candidates-json`
9. `POST websites/backend/site-builder-agent/post-select-theme`
10. `GET websites/backend/site-builder-agent/page-types-json`
11. `POST websites/backend/site-builder-agent/post-generate-drafts`
12. `POST websites/backend/site-builder-agent/post-update-draft`
13. `POST websites/backend/site-builder-agent/post-materialize`
14. `GET websites/backend/site-builder-agent/preview-json`
15. `GET websites/backend/site-builder-agent/stream-sse`

## 9. 事件类型草图

建议 `event_type`：

1. `session_created`
2. `user_message`
3. `assistant_message`
4. `site_profile_updated`
5. `domain_candidates_generated`
6. `domain_selected`
7. `domain_purchase_started`
8. `domain_purchase_finished`
9. `domain_lifecycle_changed`
10. `theme_candidates_loaded`
11. `theme_selected`
12. `page_types_selected`
13. `draft_generation_started`
14. `draft_generated`
15. `draft_refined`
16. `materialization_started`
17. `website_created`
18. `preview_ready`
19. `completed`

## 10. 关键隔离点

### 10.1 核心禁止知道的东西

`Weline_Websites` 核心层禁止直接出现：

1. `weline_theme_id`
2. `preview_page_id`



这些数据只能出现在：

1. `provider_state_json`
2. provider 自己的 artifact payload
3. provider 自己的实现类内部

### 10.2 默认 provider 与 Theme 的关系

1. 默认 provider 不直接扫描 Theme 文件系统
2. 默认 provider 只调用 `WebsiteThemeSourceRegistryInterface`
3. Theme 自己决定如何把布局扫描结果映射为页面类型

## 11. 推荐落地顺序

1. 先落 repository + registry 接口
2. 再落 `websites_default` provider
3. 再落 ThemeSource
4. 再落 materializer / preview
