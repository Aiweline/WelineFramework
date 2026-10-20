<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Skill;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Api\AgentSkillBindingInterface;
use Weline\Ai\Extends\Module\Weline_Ai\Skill\OpsSocialScopeSkillProvider;
use Weline\Ai\Service\Skill\AdapterSkillResolver;
use Weline\Ai\Service\Skill\SkillRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Extends\Module\Weline_Ai\Agent\WebsiteOpsAgent;
use Weline\Social\Extends\Module\Weline_Ai\Agent\SocialOpsAgent;

final class OpsSocialScopeSkillTest extends TestCase
{
    public function testProviderRegistersSiteSocialScopeSkill(): void
    {
        $skill = (new OpsSocialScopeSkillProvider())->listSkills()[0];

        self::assertSame(OpsSocialScopeSkillProvider::CODE, $skill['code']);
        self::assertStringContainsString('融媒体', (string)$skill['body']);
        self::assertStringContainsString('list_website_social_accounts', (string)$skill['body']);
        self::assertStringContainsString('fake_browser', (string)$skill['body']);
    }

    public function testOpsAgentsLockTheSkill(): void
    {
        $social = new SocialOpsAgent();
        $website = new WebsiteOpsAgent();

        self::assertInstanceOf(AgentSkillBindingInterface::class, $social);
        self::assertInstanceOf(AgentSkillBindingInterface::class, $website);
        self::assertSame([OpsSocialScopeSkillProvider::CODE], $social->getDefaultSkillCodes());
        self::assertSame([OpsSocialScopeSkillProvider::CODE], $website->getDefaultSkillCodes());
        self::assertSame(
            'list_website_social_accounts',
            $social->getTools()[array_key_last($social->getTools())]->getName()
        );
    }

    public function testRegistryResolvesLockedAgentSkill(): void
    {
        /** @var SkillRegistry $registry */
        $registry = ObjectManager::getInstance(SkillRegistry::class);
        $skill = $registry->getSkill(OpsSocialScopeSkillProvider::CODE);
        self::assertTrue((bool)($skill['exists'] ?? false), 'module skill was not discovered');
        self::assertSame('active', (string)($skill['status'] ?? ''));

        /** @var AdapterSkillResolver $resolver */
        $resolver = ObjectManager::getInstance(AdapterSkillResolver::class);
        $resolved = $resolver->resolveExplicitSkillBindings(
            (new SocialOpsAgent())->getDefaultSkillCodes(),
            [],
            'social_ops',
        );

        self::assertSame([OpsSocialScopeSkillProvider::CODE], $resolved['codes']);
        self::assertNotSame('', $resolver->buildPromptGuideText($resolved['items']));
    }
}
