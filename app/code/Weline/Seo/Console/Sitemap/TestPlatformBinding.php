<?php

declare(strict_types=1);

namespace Weline\Seo\Console\Sitemap;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoWebsiteAccount;
use Weline\Seo\Service\WebSitemapData;
use Weline\Websites\Model\Website;

/**
 * 测试 SEO 平台绑定逻辑
 * 
 * 演示：
 * 1. 创建/更新 SEO 账户（关联平台）
 * 2. 绑定站点与账户
 * 3. 验证只生成绑定平台的 sitemap
 */
class TestPlatformBinding implements CommandInterface
{
    private SeoAccount $seoAccountModel;
    private SeoWebsiteAccount $seoWebsiteAccountModel;
    private WebSitemapData $webSitemapData;
    private Website $websiteModel;

    public function __construct(
        SeoAccount $seoAccountModel,
        SeoWebsiteAccount $seoWebsiteAccountModel,
        WebSitemapData $webSitemapData,
        Website $websiteModel
    ) {
        $this->seoAccountModel = $seoAccountModel;
        $this->seoWebsiteAccountModel = $seoWebsiteAccountModel;
        $this->webSitemapData = $webSitemapData;
        $this->websiteModel = $websiteModel;
    }

    public function execute(array $args = [], array $options = []): string
    {
        $this->printHeader();
        
        // 步骤 1：创建测试账户
        $accounts = $this->createTestAccounts();
        
        // 步骤 2：测试不同的绑定场景
        $this->testScenarios($accounts);
        
        // 步骤 3：验证生成逻辑
        $this->verifyGeneration();
        
        $this->printSummary();
        
        return '';
    }

    private function printHeader(): void
    {
        echo "\n╔════════════════════════════════════════════════════════════════╗\n";
        echo "║          SEO 平台绑定测试                                      ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";
    }

    /**
     * 创建测试账户
     */
    private function createTestAccounts(): array
    {
        echo "┌─────────────────────────────────────────────────────────────┐\n";
        echo "│ 步骤 1: 创建/更新 SEO 测试账户                       │\n";
        echo "└─────────────────────────────────────────────────────────────┘\n";
        
        $accounts = [];
        $platforms = [
            [
                'platform' => 'google',
                'provider' => 'google_search_console',
                'name' => 'Google Search Console 测试账户',
                'description' => '用于测试 Google Sitemap 生成'
            ],
            [
                'platform' => 'bing',
                'provider' => 'bing_webmaster',
                'name' => 'Bing Webmaster 测试账户',
                'description' => '用于测试 Bing Sitemap 生成'
            ],
            [
                'platform' => 'baidu',
                'provider' => 'baidu_zhanzhang',
                'name' => '百度站长平台测试账户',
                'description' => '用于测试百度 Sitemap 生成'
            ],
        ];
        
        foreach ($platforms as $data) {
            // 检查是否已存在
            $existing = $this->seoAccountModel->reset()
                ->where(SeoAccount::fields_PLATFORM, $data['platform'])
                ->where(SeoAccount::fields_PROVIDER, $data['provider'])
                ->find()
                ->fetch();
            
            if ($existing->getId()) {
                // 更新现有账户
                $existing->setPlatform($data['platform'])
                    ->setData(SeoAccount::fields_NAME, $data['name'])
                    ->setData(SeoAccount::fields_IS_ACTIVE, 1)
                    ->save();
                $account = $existing;
                echo "  ✓ 更新账户: {$data['name']} (ID: {$account->getId()}, Platform: {$data['platform']})\n";
            } else {
                // 创建新账户
                $account = $this->seoAccountModel->reset()->setData([
                    SeoAccount::fields_PLATFORM => $data['platform'],
                    SeoAccount::fields_PROVIDER => $data['provider'],
                    SeoAccount::fields_NAME => $data['name'],
                    SeoAccount::fields_DESCRIPTION => $data['description'],
                    SeoAccount::fields_IS_ACTIVE => 1,
                    SeoAccount::fields_ENABLE_CRON_SITEMAP => 1,
                ])->save();
                echo "  ✓ 创建账户: {$data['name']} (ID: {$account->getId()}, Platform: {$data['platform']})\n";
            }
            
            $accounts[$data['platform']] = $account;
        }
        
        echo "\n";
        return $accounts;
    }

    /**
     * 测试不同的绑定场景
     */
    private function testScenarios(array $accounts): void
    {
        echo "┌─────────────────────────────────────────────────────────────┐\n";
        echo "│ 步骤 2: 测试站点绑定场景                             │\n";
        echo "└─────────────────────────────────────────────────────────────┘\n";
        
        // 获取所有站点
        $websites = $this->websiteModel->reset()->select()->fetchArray();
        
        if (empty($websites)) {
            echo "  ⚠ 没有找到站点，跳过测试\n\n";
            return;
        }
        
        // 场景 1：第一个站点只绑定 Google
        if (isset($websites[0])) {
            $websiteId = (int)$websites[0]['website_id'];
            $websiteName = $websites[0]['name'] ?? "站点{$websiteId}";
            
            echo "\n  场景 1: {$websiteName} 只绑定 Google\n";
            
            // 清除现有绑定
            $this->clearWebsiteBindings($websiteId);
            
            // 绑定 Google
            if (isset($accounts['google'])) {
                $this->seoWebsiteAccountModel->reset()->bindWebsiteAccount(
                    $websiteId,
                    $accounts['google']->getId(),
                    true
                );
                echo "    ✓ 已绑定 Google (预期：只生成 Google 平台的 sitemap)\n";
            }
        }
        
        // 场景 2：第二个站点绑定 Google + Bing
        if (isset($websites[1])) {
            $websiteId = (int)$websites[1]['website_id'];
            $websiteName = $websites[1]['name'] ?? "站点{$websiteId}";
            
            echo "\n  场景 2: {$websiteName} 绑定 Google + Bing\n";
            
            // 清除现有绑定
            $this->clearWebsiteBindings($websiteId);
            
            // 绑定 Google 和 Bing
            if (isset($accounts['google'])) {
                $this->seoWebsiteAccountModel->reset()->bindWebsiteAccount(
                    $websiteId,
                    $accounts['google']->getId(),
                    true
                );
                echo "    ✓ 已绑定 Google\n";
            }
            
            if (isset($accounts['bing'])) {
                $this->seoWebsiteAccountModel->reset()->bindWebsiteAccount(
                    $websiteId,
                    $accounts['bing']->getId(),
                    true
                );
                echo "    ✓ 已绑定 Bing\n";
            }
            
            echo "    预期：生成 Google 和 Bing 平台的 sitemap\n";
        }
        
        echo "\n";
    }

    /**
     * 清除站点的所有账户绑定
     */
    private function clearWebsiteBindings(int $websiteId): void
    {
        $bindings = $this->seoWebsiteAccountModel->getByWebsiteId($websiteId);
        foreach ($bindings as $binding) {
            $accountId = (int)($binding[SeoWebsiteAccount::fields_ACCOUNT_ID] ?? 0);
            if ($accountId > 0) {
                $this->seoWebsiteAccountModel->reset()->unbindWebsiteAccount($websiteId, $accountId);
            }
        }
    }

    /**
     * 验证生成逻辑
     */
    private function verifyGeneration(): void
    {
        echo "┌─────────────────────────────────────────────────────────────┐\n";
        echo "│ 步骤 3: 验证 Sitemap 生成逻辑                         │\n";
        echo "└─────────────────────────────────────────────────────────────┘\n";
        
        $websites = $this->websiteModel->reset()->select()->fetchArray();
        
        foreach ($websites as $website) {
            $websiteId = (int)$website['website_id'];
            $websiteName = $website['name'] ?? "站点{$websiteId}";
            $websiteCode = $website['code'] ?? "website_{$websiteId}";
            
            echo "\n  {$websiteName} ({$websiteCode}):\n";
            
            // 获取绑定的适配器
            $adapters = $this->webSitemapData->getWebsiteAdapters($websiteId);
            
            if (empty($adapters)) {
                echo "    ⚠ 未绑定任何平台，不会生成 sitemap\n";
                continue;
            }
            
            echo "    绑定的平台：\n";
            foreach ($adapters as $platformCode => $adapter) {
                $platformName = $adapter->getPlatformName();
                $platformColor = $adapter->getPlatformColor();
                echo "      • {$platformName} ({$platformCode}) - {$platformColor}\n";
            }
            
            // 获取绑定的平台代码
            $platformCodes = $this->seoWebsiteAccountModel->reset()->getWebsitePlatforms($websiteId);
            echo "    数据库中的平台：" . implode(', ', $platformCodes) . "\n";
        }
        
        echo "\n";
    }

    private function printSummary(): void
    {
        echo "╔════════════════════════════════════════════════════════════════╗\n";
        echo "║                    测试完成                                    ║\n";
        echo "╚════════════════════════════════════════════════════════════════╝\n\n";
        
        echo "下一步：\n";
        echo "  1. 运行 sitemap 生成：php bin/w sitemap:test\n";
        echo "  2. 检查生成的文件是否只包含绑定的平台\n";
        echo "  3. 未绑定账户的站点不应该生成任何 sitemap\n\n";
    }

    public function tip(): string
    {
        return '测试 SEO 平台绑定逻辑';
    }

    public function help(): string
    {
        return <<<'HELP'
测试 SEO 平台绑定逻辑

用法：
  php bin/w sitemap:test-binding

功能：
  1. 创建测试 SEO 账户（Google, Bing, 百度）
  2. 模拟不同的站点绑定场景
  3. 验证 WebSitemapData 只返回绑定平台的适配器

场景说明：
  - 场景 1：站点只绑定 Google → 只生成 Google sitemap
  - 场景 2：站点绑定 Google + Bing → 生成 Google 和 Bing sitemap
  - 未绑定：站点未绑定任何账户 → 不生成任何 sitemap

验证方法：
  运行本命令后，再运行 php bin/w sitemap:test
  检查生成的文件目录结构是否符合预期
HELP;
    }
}
