<?php

declare(strict_types=1);

namespace GuoLaiRen\Blog\Console\Blog\Import;

use GuoLaiRen\Blog\Model\Category;
use GuoLaiRen\Blog\Model\Post;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

/**
 * 导入博客测试数据命令
 * 
 * 导入测试分类和文章数据，可指定网站ID
 */
class Sample extends CommandAbstract
{
    public const dir = 'Console\\Blog\\Import';
    
    private Category $categoryModel;
    private Post $postModel;
    private Website $websiteModel;

    public function __construct(
        Category $categoryModel, 
        Post $postModel,
        Website $websiteModel
    ) {
        $this->categoryModel = $categoryModel;
        $this->postModel = $postModel;
        $this->websiteModel = $websiteModel;
    }

    public function execute(array $args = [], array $data = []): void
    {
        $this->printer->note('博客测试数据导入工具');
        $this->printer->note('======================');
        
        // 解析参数获取网站ID
        $siteId = $this->parseSiteId($args);
        
        if ($siteId === null) {
            // 交互式选择网站
            $siteId = $this->selectWebsite();
            if ($siteId === null) {
                $this->printer->warning('已取消导入');
                return;
            }
        }
        
        // 验证网站是否存在
        if ($siteId > 0) {
            $website = clone $this->websiteModel;
            $website->load($siteId);
            if (!$website->getId()) {
                $this->printer->error("网站ID {$siteId} 不存在");
                return;
            }
            $this->printer->success("选择网站: {$website->getData(Website::fields_NAME)} (ID: {$siteId})");
        } else {
            $this->printer->note('导入到全局（site_id = 0）');
        }
        
        $this->printer->note('');
        
        // 导入分类
        $this->printer->note('正在导入博客分类...');
        $categoryMap = $this->importCategories($siteId);
        
        // 导入文章
        $this->printer->note('');
        $this->printer->note('正在导入博客文章...');
        $this->importPosts($siteId, $categoryMap);
        
        $this->printer->success('');
        $this->printer->success('✓ 博客测试数据导入完成！');
    }

    /**
     * 解析网站ID参数
     */
    private function parseSiteId(array $args): ?int
    {
        foreach ($args as $i => $arg) {
            // -s 123 或 --site 123
            if (($arg === '-s' || $arg === '--site') && isset($args[$i + 1])) {
                return (int)$args[$i + 1];
            }
            // -s=123 或 --site=123
            if (preg_match('/^(-s|--site)=(\d+)$/', $arg, $matches)) {
                return (int)$matches[2];
            }
        }
        return null;
    }

    /**
     * 交互式选择网站
     */
    private function selectWebsite(): ?int
    {
        // 获取所有网站
        $websites = $this->websiteModel->reset()
            ->select()
            ->fetch()
            ->getItems();
        
        if (empty($websites)) {
            $this->printer->warning('暂无网站数据，将导入到全局（site_id = 0）');
            $this->printer->note('');
            $this->printer->note('继续导入吗？[Y/n]');
            $input = trim(fgets(STDIN));
            if (strtolower($input) === 'n') {
                return null;
            }
            return 0;
        }
        
        $this->printer->note('');
        $this->printer->note('请选择要导入数据的网站:');
        $this->printer->note('');
        $this->printer->note('  [0] 全局（不绑定特定网站）');
        
        $websiteList = [];
        foreach ($websites as $website) {
            $id = $website->getId();
            $name = $website->getData(Website::fields_NAME);
            $code = $website->getData(Website::fields_CODE);
            $url = $website->getData(Website::fields_URL);
            $websiteList[$id] = $website;
            $this->printer->note("  [{$id}] {$name} ({$code}) - {$url}");
        }
        
        $this->printer->note('');
        $this->printer->note('请输入网站ID（直接回车选择0）:');
        
        $input = trim(fgets(STDIN));
        
        if ($input === '') {
            return 0;
        }
        
        $selectedId = (int)$input;
        
        if ($selectedId === 0) {
            return 0;
        }
        
        if (!isset($websiteList[$selectedId])) {
            $this->printer->error("无效的网站ID: {$selectedId}");
            return null;
        }
        
        return $selectedId;
    }

    /**
     * 导入博客分类
     * 
     * @return array 分类slug到ID的映射
     */
    private function importCategories(int $siteId): array
    {
        $categories = $this->getTestCategories();
        $categoryMap = [];
        $importedCount = 0;
        $skippedCount = 0;
        
        foreach ($categories as $categoryData) {
            try {
                // 检查slug是否已存在
                $existing = $this->categoryModel->reset()
                    ->where(Category::fields_SLUG, $categoryData['slug'])
                    ->where(Category::fields_SITE_ID, $siteId)
                    ->find()
                    ->fetch();
                
                if ($existing->getId()) {
                    $categoryMap[$categoryData['slug']] = (int)$existing->getId();
                    $this->printer->warning("  - 分类已存在: {$categoryData['name']} (ID: {$existing->getId()})");
                    $skippedCount++;
                    continue;
                }
                
                // 创建分类
                $category = clone $this->categoryModel;
                $category->clearData();
                $category->setData(Category::fields_SITE_ID, $siteId);
                $category->setData(Category::fields_NAME, $categoryData['name']);
                $category->setData(Category::fields_SLUG, $categoryData['slug']);
                $category->setData(Category::fields_DESCRIPTION, $categoryData['description']);
                $category->setData(Category::fields_COVER_IMAGE, $categoryData['cover_image'] ?? '');
                $category->setData(Category::fields_PARENT_ID, 0);
                $category->setData(Category::fields_SORT_ORDER, $categoryData['sort_order']);
                $category->setData(Category::fields_STATUS, Category::STATUS_ENABLED);
                $category->setData(Category::fields_META_TITLE, $categoryData['meta_title'] ?? $categoryData['name']);
                $category->setData(Category::fields_META_DESCRIPTION, $categoryData['meta_description'] ?? $categoryData['description']);
                $category->setData(Category::fields_META_KEYWORDS, $categoryData['meta_keywords'] ?? '');
                
                $categoryId = $category->save();
                
                if ($categoryId) {
                    $categoryMap[$categoryData['slug']] = (int)$categoryId;
                    $this->printer->success("  ✓ 创建分类: {$categoryData['name']} (ID: {$categoryId})");
                    $importedCount++;
                }
            } catch (\Exception $e) {
                $this->printer->error("  ✗ 创建分类失败 {$categoryData['name']}: " . $e->getMessage());
            }
        }
        
        $this->printer->note("  共导入 {$importedCount} 个分类，跳过 {$skippedCount} 个已存在");
        
        return $categoryMap;
    }

    /**
     * 导入博客文章
     */
    private function importPosts(int $siteId, array $categoryMap): void
    {
        $posts = $this->getTestPosts();
        $importedCount = 0;
        $skippedCount = 0;
        
        foreach ($posts as $postData) {
            try {
                // 检查slug是否已存在
                $existing = $this->postModel->reset()
                    ->where(Post::fields_SLUG, $postData['slug'])
                    ->where(Post::fields_SITE_ID, $siteId)
                    ->find()
                    ->fetch();
                
                if ($existing->getId()) {
                    $this->printer->warning("  - 文章已存在: {$postData['title']} (ID: {$existing->getId()})");
                    $skippedCount++;
                    continue;
                }
                
                // 获取分类ID
                $categoryId = 0;
                if (isset($postData['category_slug']) && isset($categoryMap[$postData['category_slug']])) {
                    $categoryId = $categoryMap[$postData['category_slug']];
                }
                
                // 创建文章
                $post = clone $this->postModel;
                $post->clearData();
                $post->setData(Post::fields_SITE_ID, $siteId);
                $post->setData(Post::fields_CATEGORY_ID, $categoryId);
                $post->setData(Post::fields_TITLE, $postData['title']);
                $post->setData(Post::fields_SLUG, $postData['slug']);
                $post->setData(Post::fields_SUMMARY, $postData['summary']);
                $post->setData(Post::fields_CONTENT, $postData['content']);
                $post->setData(Post::fields_COVER_IMAGE, $postData['cover_image'] ?? '');
                $post->setData(Post::fields_AUTHOR, $postData['author']);
                $post->setData(Post::fields_TAGS, $postData['tags']);
                $post->setData(Post::fields_VIEW_COUNT, rand(100, 5000));
                $post->setData(Post::fields_STATUS, Post::STATUS_PUBLISHED);
                $post->setData(Post::fields_IS_FEATURED, $postData['is_featured'] ?? 0);
                $post->setData(Post::fields_PUBLISHED_AT, $postData['published_at'] ?? date('Y-m-d H:i:s'));
                
                $postId = $post->save();
                
                if ($postId) {
                    $categoryName = $postData['category_slug'] ?? '无分类';
                    $this->printer->success("  ✓ 创建文章: {$postData['title']} (ID: {$postId}, 分类: {$categoryName})");
                    $importedCount++;
                }
            } catch (\Exception $e) {
                $this->printer->error("  ✗ 创建文章失败 {$postData['title']}: " . $e->getMessage());
            }
        }
        
        $this->printer->note("  共导入 {$importedCount} 篇文章，跳过 {$skippedCount} 篇已存在");
    }

    /**
     * 获取测试分类数据
     */
    private function getTestCategories(): array
    {
        return [
            [
                'name' => '技术教程',
                'slug' => 'tech-tutorials',
                'description' => '涵盖各种技术教程，包括编程、框架、工具使用等',
                'sort_order' => 1,
                'meta_title' => '技术教程 - 学习编程与开发',
                'meta_keywords' => '技术教程,编程,开发,框架',
            ],
            [
                'name' => '产品更新',
                'slug' => 'product-updates',
                'description' => '最新产品功能更新、版本发布和改进说明',
                'sort_order' => 2,
                'meta_title' => '产品更新 - 最新功能与版本',
                'meta_keywords' => '产品更新,新功能,版本发布',
            ],
            [
                'name' => '行业资讯',
                'slug' => 'industry-news',
                'description' => '互联网、科技行业的最新动态和趋势分析',
                'sort_order' => 3,
                'meta_title' => '行业资讯 - 科技动态与趋势',
                'meta_keywords' => '行业资讯,科技新闻,互联网',
            ],
            [
                'name' => '使用技巧',
                'slug' => 'tips-tricks',
                'description' => '产品使用技巧、最佳实践和效率提升方法',
                'sort_order' => 4,
                'meta_title' => '使用技巧 - 提升效率的方法',
                'meta_keywords' => '使用技巧,最佳实践,效率',
            ],
            [
                'name' => '案例分享',
                'slug' => 'case-studies',
                'description' => '真实用户案例分享，展示成功经验和解决方案',
                'sort_order' => 5,
                'meta_title' => '案例分享 - 成功经验与方案',
                'meta_keywords' => '案例分享,成功案例,解决方案',
            ],
            [
                'name' => '公司动态',
                'slug' => 'company-news',
                'description' => '公司最新消息、活动、里程碑和团队故事',
                'sort_order' => 6,
                'meta_title' => '公司动态 - 企业新闻与活动',
                'meta_keywords' => '公司动态,企业新闻,团队',
            ],
        ];
    }

    /**
     * 获取测试文章数据
     */
    private function getTestPosts(): array
    {
        $posts = [
            // 技术教程分类
            [
                'title' => 'PHP 8.3 新特性详解：提升代码质量的利器',
                'slug' => 'php-83-new-features-guide',
                'category_slug' => 'tech-tutorials',
                'summary' => '深入探讨 PHP 8.3 带来的新特性，包括类型系统改进、新函数和性能优化，帮助开发者写出更优雅的代码。',
                'content' => $this->generateArticleContent('PHP 8.3 新特性', [
                    '类型常量（Typed Class Constants）',
                    '#[Override] 属性',
                    'json_validate() 函数',
                    '动态类常量获取',
                    '性能优化与改进',
                ]),
                'author' => '张三',
                'tags' => 'PHP,编程,后端开发,PHP8',
                'is_featured' => 1,
                'published_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            ],
            [
                'title' => '从零开始学习 Docker：容器化部署实战指南',
                'slug' => 'docker-beginner-guide',
                'category_slug' => 'tech-tutorials',
                'summary' => '本文将带你从零开始学习 Docker，掌握容器化技术，实现应用的快速部署和管理。',
                'content' => $this->generateArticleContent('Docker 入门', [
                    'Docker 基本概念介绍',
                    '安装和配置 Docker 环境',
                    'Dockerfile 编写技巧',
                    'Docker Compose 多容器编排',
                    '实战：部署 Web 应用',
                ]),
                'author' => '李四',
                'tags' => 'Docker,容器化,DevOps,部署',
                'is_featured' => 1,
                'published_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
            ],
            [
                'title' => 'Vue 3 组合式 API 最佳实践',
                'slug' => 'vue3-composition-api-best-practices',
                'category_slug' => 'tech-tutorials',
                'summary' => '探索 Vue 3 组合式 API 的强大功能，学习如何组织和复用代码逻辑，构建可维护的前端应用。',
                'content' => $this->generateArticleContent('Vue 3 组合式 API', [
                    'setup 函数详解',
                    'reactive 与 ref 的区别',
                    '自定义 Hook 的创建',
                    '生命周期钩子的使用',
                    '状态管理与 Pinia 集成',
                ]),
                'author' => '王五',
                'tags' => 'Vue,前端,JavaScript,组合式API',
                'is_featured' => 0,
                'published_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
            ],
            [
                'title' => 'MySQL 性能优化：从索引到查询调优',
                'slug' => 'mysql-performance-optimization',
                'category_slug' => 'tech-tutorials',
                'summary' => '全面介绍 MySQL 性能优化技巧，包括索引设计、SQL 优化、配置调整等，帮助解决数据库性能瓶颈。',
                'content' => $this->generateArticleContent('MySQL 性能优化', [
                    '索引原理与设计策略',
                    'EXPLAIN 执行计划分析',
                    '慢查询日志分析',
                    '查询优化实战技巧',
                    '数据库配置调优',
                ]),
                'author' => '赵六',
                'tags' => 'MySQL,数据库,性能优化,SQL',
                'is_featured' => 0,
                'published_at' => date('Y-m-d H:i:s', strtotime('-7 days')),
            ],
            
            // 产品更新分类
            [
                'title' => '版本更新 v2.5.0：全新可视化编辑器上线',
                'slug' => 'release-v250-visual-editor',
                'category_slug' => 'product-updates',
                'summary' => '我们很高兴地宣布 v2.5.0 版本正式发布，带来了全新的可视化页面编辑器，让网站搭建更加简单直观。',
                'content' => $this->generateArticleContent('v2.5.0 版本更新', [
                    '全新可视化编辑器',
                    '拖拽式组件布局',
                    '实时预览功能',
                    '多设备响应式设计',
                    '性能优化与 Bug 修复',
                ]),
                'author' => '产品团队',
                'tags' => '版本更新,新功能,可视化编辑器',
                'is_featured' => 1,
                'published_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            ],
            [
                'title' => '新功能预告：AI 智能内容生成即将上线',
                'slug' => 'upcoming-ai-content-generation',
                'category_slug' => 'product-updates',
                'summary' => '我们正在开发 AI 智能内容生成功能，帮助用户快速创建高质量的文案、标题和描述。',
                'content' => $this->generateArticleContent('AI 智能内容生成', [
                    '功能介绍与使用场景',
                    '支持的内容类型',
                    'AI 模型选择',
                    '预计上线时间',
                    '参与内测的方式',
                ]),
                'author' => '产品团队',
                'tags' => 'AI,新功能,内容生成,预告',
                'is_featured' => 0,
                'published_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
            ],
            
            // 行业资讯分类
            [
                'title' => '2024 年前端开发趋势：AI 驱动的新时代',
                'slug' => 'frontend-trends-2024-ai-era',
                'category_slug' => 'industry-news',
                'summary' => '回顾 2024 年前端开发领域的重大变化，AI 工具如何改变开发者的工作方式。',
                'content' => $this->generateArticleContent('2024 前端趋势', [
                    'AI 辅助编程工具崛起',
                    '新一代构建工具 Vite/Turbopack',
                    'Server Components 普及',
                    'Edge Computing 应用',
                    'Web 性能优化新标准',
                ]),
                'author' => '行业观察',
                'tags' => '前端,趋势,AI,2024',
                'is_featured' => 0,
                'published_at' => date('Y-m-d H:i:s', strtotime('-4 days')),
            ],
            [
                'title' => '电商行业数字化转型：无头商务架构解析',
                'slug' => 'ecommerce-headless-architecture',
                'category_slug' => 'industry-news',
                'summary' => '深入分析无头商务（Headless Commerce）架构，了解现代电商系统的技术演进方向。',
                'content' => $this->generateArticleContent('无头商务架构', [
                    '什么是无头商务',
                    '传统架构 vs 无头架构',
                    '主流无头商务平台对比',
                    '实施无头商务的挑战',
                    '未来发展趋势',
                ]),
                'author' => '行业观察',
                'tags' => '电商,无头商务,架构,数字化',
                'is_featured' => 0,
                'published_at' => date('Y-m-d H:i:s', strtotime('-8 days')),
            ],
            
            // 使用技巧分类
            [
                'title' => '5 个提升网站 SEO 排名的实用技巧',
                'slug' => 'seo-tips-improve-ranking',
                'category_slug' => 'tips-tricks',
                'summary' => '分享 5 个经过验证的 SEO 优化技巧，帮助你的网站在搜索结果中获得更好的排名。',
                'content' => $this->generateArticleContent('SEO 优化技巧', [
                    '关键词研究与布局',
                    '页面加载速度优化',
                    '高质量内容创作',
                    '内部链接策略',
                    '移动端优化要点',
                ]),
                'author' => 'SEO 专家',
                'tags' => 'SEO,网站优化,排名,技巧',
                'is_featured' => 1,
                'published_at' => date('Y-m-d H:i:s', strtotime('-6 days')),
            ],
            [
                'title' => '如何使用页面构建器快速创建落地页',
                'slug' => 'create-landing-page-quickly',
                'category_slug' => 'tips-tricks',
                'summary' => '详细介绍使用页面构建器创建高转化率落地页的完整流程和实用技巧。',
                'content' => $this->generateArticleContent('快速创建落地页', [
                    '落地页设计原则',
                    '选择合适的模板',
                    '添加和配置组件',
                    'CTA 按钮优化',
                    'A/B 测试方法',
                ]),
                'author' => '产品运营',
                'tags' => '落地页,页面构建,转化率,技巧',
                'is_featured' => 0,
                'published_at' => date('Y-m-d H:i:s', strtotime('-12 days')),
            ],
            
            // 案例分享分类
            [
                'title' => '客户案例：某电商平台如何实现 300% 转化提升',
                'slug' => 'case-study-ecommerce-conversion',
                'category_slug' => 'case-studies',
                'summary' => '分享某知名电商客户通过我们的解决方案，实现转化率大幅提升的成功经验。',
                'content' => $this->generateArticleContent('电商转化提升案例', [
                    '客户背景与挑战',
                    '解决方案设计',
                    '实施过程与调整',
                    '数据效果分析',
                    '经验总结与建议',
                ]),
                'author' => '客户成功团队',
                'tags' => '案例,电商,转化率,成功经验',
                'is_featured' => 1,
                'published_at' => date('Y-m-d H:i:s', strtotime('-9 days')),
            ],
            [
                'title' => 'SaaS 企业如何通过内容营销获取优质线索',
                'slug' => 'saas-content-marketing-leads',
                'category_slug' => 'case-studies',
                'summary' => '一家 B2B SaaS 企业通过系统化的内容营销策略，成功实现高质量线索的持续获取。',
                'content' => $this->generateArticleContent('SaaS 内容营销案例', [
                    '企业背景介绍',
                    '内容策略规划',
                    '博客与 SEO 优化',
                    '线索转化漏斗',
                    '成果与数据展示',
                ]),
                'author' => '市场团队',
                'tags' => 'SaaS,内容营销,线索获取,B2B',
                'is_featured' => 0,
                'published_at' => date('Y-m-d H:i:s', strtotime('-15 days')),
            ],
            
            // 公司动态分类
            [
                'title' => '我们完成了 A 轮融资，感谢信任与支持',
                'slug' => 'series-a-funding-announcement',
                'category_slug' => 'company-news',
                'summary' => '我们很高兴地宣布完成 A 轮融资，感谢所有用户、合作伙伴和投资人的信任与支持。',
                'content' => $this->generateArticleContent('A 轮融资公告', [
                    '融资详情公布',
                    '发展历程回顾',
                    '未来发展规划',
                    '团队扩张计划',
                    '感谢与展望',
                ]),
                'author' => 'CEO',
                'tags' => '融资,公司动态,里程碑',
                'is_featured' => 1,
                'published_at' => date('Y-m-d H:i:s', strtotime('-20 days')),
            ],
            [
                'title' => '团队招募：寻找志同道合的伙伴',
                'slug' => 'team-recruitment-2024',
                'category_slug' => 'company-news',
                'summary' => '我们正在扩大团队规模，寻找在技术、产品、运营等领域有热情的优秀人才加入。',
                'content' => $this->generateArticleContent('团队招募', [
                    '公司文化介绍',
                    '开放职位列表',
                    '福利待遇说明',
                    '工作环境展示',
                    '投递方式与流程',
                ]),
                'author' => 'HR团队',
                'tags' => '招聘,团队,职位,公司文化',
                'is_featured' => 0,
                'published_at' => date('Y-m-d H:i:s', strtotime('-25 days')),
            ],
            [
                'title' => '年度用户大会圆满落幕：共创未来',
                'slug' => 'annual-user-conference-recap',
                'category_slug' => 'company-news',
                'summary' => '2024 年度用户大会圆满结束，感谢所有参与者，一起回顾精彩瞬间。',
                'content' => $this->generateArticleContent('年度用户大会', [
                    '大会概况',
                    '主题演讲精华',
                    '新产品发布',
                    '用户分享环节',
                    '精彩瞬间回顾',
                ]),
                'author' => '活动团队',
                'tags' => '用户大会,活动,年度,回顾',
                'is_featured' => 0,
                'published_at' => date('Y-m-d H:i:s', strtotime('-30 days')),
            ],
        ];
        
        return $posts;
    }

    /**
     * 生成文章内容
     */
    private function generateArticleContent(string $title, array $sections): string
    {
        $html = "<h2>{$title}</h2>\n\n";
        $html .= "<p>本文将详细介绍 {$title} 的相关内容，帮助读者深入理解和掌握核心要点。</p>\n\n";
        
        foreach ($sections as $index => $section) {
            $num = $index + 1;
            $html .= "<h3>{$num}. {$section}</h3>\n";
            $html .= "<p>关于 {$section}，这是一个非常重要的主题。在实际应用中，我们需要充分理解其原理和最佳实践。</p>\n";
            $html .= "<p>通过合理的规划和实施，可以有效提升工作效率和项目质量。以下是一些关键点需要注意：</p>\n";
            $html .= "<ul>\n";
            $html .= "  <li>深入理解基本概念和原理</li>\n";
            $html .= "  <li>结合实际场景进行应用</li>\n";
            $html .= "  <li>持续学习和优化改进</li>\n";
            $html .= "</ul>\n\n";
        }
        
        $html .= "<h3>总结</h3>\n";
        $html .= "<p>通过本文的介绍，相信读者对 {$title} 有了更深入的了解。在实际工作中，建议结合具体情况灵活应用，不断总结经验，持续优化提升。</p>\n";
        $html .= "<p>如果您有任何问题或建议，欢迎在评论区留言交流。</p>\n";
        
        return $html;
    }

    public function tip(): string
    {
        return '导入博客测试数据（分类和文章）';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'blog:import:sample',
            $this->tip(),
            [
                '-s, --site <id>' => '指定网站ID（不指定则交互式选择）',
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '交互式选择网站' => 'php bin/w blog:import:sample',
                '指定网站ID导入' => 'php bin/w blog:import:sample -s 1',
                '导入到全局' => 'php bin/w blog:import:sample --site=0',
            ]
        );
    }
}
