<?php

declare(strict_types=1);

namespace Weline\Ai\Extends\Module\Weline_Ai\Skill;

use Weline\Ai\Api\SkillProviderInterface;
use Weline\Ai\Model\AiSkill;

/**
 * 运营智能体自带技能：某站要发的社媒平台只来自该站融媒体已绑定账户。
 */
final class OpsSocialScopeSkillProvider implements SkillProviderInterface
{
    public const CODE = 'ops-social-scope';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSkills(): array
    {
        return [[
            'code' => self::CODE,
            'name' => '按站读取融媒体平台',
            'description' => '运营某个网站时，社媒平台只使用该站融媒体里已经绑定的账户，不另编平台。',
            'body' => $this->body(),
            'source_type' => AiSkill::SOURCE_MODULE,
            'source_module' => 'Weline_Ai',
            'source' => 'module:Weline_Ai',
            'version' => '1.0.0',
            'tags' => ['ops', 'social', 'website', '融媒体'],
            'local_path' => 'app/code/Weline/Ai/extends/module/Weline_Ai/Skill/OpsSocialScopeSkillProvider.php',
            'readonly' => true,
        ]];
    }

    private function body(): string
    {
        return <<<'MD'
# 按站读取融媒体平台

运营某个网站时，这个站要对接的社媒平台已经记在融媒体（Weline_Social）里。先读该站，再决定发哪些平台。

## 怎么读

- 范围是当前任务的 `website_id`。没有站点编号就先问站，不要猜。
- 调用工具 `list_website_social_accounts`，或等价读取 `SocialWebsiteAccountService::getWebsiteDefaultAccounts(websiteId)`。
- 只用返回里的默认账户。平台代号取 `platform_code`，展示名取 `account_name`。
- `publish_enabled=1` 的账户才可以进入发布草稿。未开发布的账户可以汇报，不能拿去发。
- 账户为空：直接说明该站融媒体还没有绑定平台，并停在社媒车道。不要改去编造 YouTube、Facebook、Instagram、X、TikTok 或 `fake_browser`。

## 不要做

- 不要注册新平台账号，不要开通社媒邮箱，不要改融媒体绑定。
- 不要把凭据、令牌、密码写进回复或创意正文。
- 不要调用 `createPublishBatch`，不要把内容送进发布队列。公开外发仍须运营审批。
- 博客草稿不使用这份平台清单，除非任务明确是把同一内容转到社媒。

## 回复

先列出该站已绑定的平台（代号、账户名、是否允许发布）。没有绑定时只说明没有，并停止社媒草稿。
MD;
    }
}
