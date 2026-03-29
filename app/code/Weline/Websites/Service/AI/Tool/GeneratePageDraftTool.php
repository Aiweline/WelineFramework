<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;

/**
 * AI 生成页面草稿工具
 *
 * 根据站点画像（标题、行业、目标用户）和主题信息，生成各页面类型的草稿内容。
 */
class GeneratePageDraftTool implements ToolInterface
{
    public function __construct(
        private readonly \Weline\Websites\Service\AiWorkbench\VirtualThemeWorkbenchService $virtualThemeService
    ) {
    }

    public function getName(): string
    {
        return 'generate_page_draft';
    }

    public function getDescription(): string
    {
        return 'Generate a page draft with content for a specific page type based on site profile and theme information.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_type' => [
                    'type' => 'string',
                    'description' => 'Page type code (e.g. home_page, about_page, contact_page)',
                ],
                'site_title' => [
                    'type' => 'string',
                    'description' => 'Site title or business name',
                ],
                'site_description' => [
                    'type' => 'string',
                    'description' => 'Brief description of the site purpose and target audience',
                ],
                'theme_name' => [
                    'type' => 'string',
                    'description' => 'Selected theme name or style direction',
                ],
                'component_code' => [
                    'type' => 'string',
                    'description' => 'Component code for the generated section (default: content/ai-generated-section)',
                ],
            ],
            'required' => ['page_type', 'site_title'],
        ];
    }

    public function execute(array $args): mixed
    {
        $pageType = \trim((string)($args['page_type'] ?? ''));
        $siteTitle = \trim((string)($args['site_title'] ?? ''));
        $siteDescription = \trim((string)($args['site_description'] ?? ''));
        $themeName = \trim((string)($args['theme_name'] ?? ''));
        $componentCode = \trim((string)($args['component_code'] ?? 'content/ai-generated-section'));

        if ($pageType === '') {
            return ['success' => false, 'message' => 'page_type is required'];
        }
        if ($siteTitle === '') {
            return ['success' => false, 'message' => 'site_title is required'];
        }

        $content = $this->generateContent($pageType, $siteTitle, $siteDescription, $themeName);
        $suggestions = $this->generateSuggestions($pageType, $siteTitle, $siteDescription);

        return [
            'success' => true,
            'page_type' => $pageType,
            'site_title' => $siteTitle,
            'content' => $content,
            'suggestions' => $suggestions,
            'component_code' => $componentCode,
            'next_step' => 'update_page_draft',
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    private function generateContent(string $pageType, string $siteTitle, string $siteDescription, string $themeName): array
    {
        return match ($pageType) {
            'home_page' => $this->generateHomePageContent($siteTitle, $siteDescription, $themeName),
            'about_page' => $this->generateAboutPageContent($siteTitle, $siteDescription),
            'contact_page' => $this->generateContactPageContent($siteTitle),
            'privacy_policy' => $this->generatePrivacyPolicyContent($siteTitle),
            'terms_of_service' => $this->generateTermsContent($siteTitle),
            'refund_policy' => $this->generateRefundContent($siteTitle),
            'cookie_policy' => $this->generateCookieContent($siteTitle),
            'blog_list' => $this->generateBlogListContent($siteTitle, $siteDescription),
            'blog_category' => $this->generateBlogCategoryContent($siteTitle),
            'blog_post' => $this->generateBlogPostContent($siteTitle, $siteDescription),
            'custom_page' => $this->generateCustomPageContent($siteTitle, $siteDescription),
            default => $this->generateGenericPageContent($siteTitle, $siteDescription),
        };
    }

    private function generateHomePageContent(string $siteTitle, string $siteDescription, string $themeName): array
    {
        return [
            'hero_title' => __('欢迎来到 %{title}', ['title' => $siteTitle]),
            'hero_subtitle' => $siteDescription !== '' ? $siteDescription : __('我们提供优质的产品与服务'),
            'sections' => [
                [
                    'type' => 'features',
                    'title' => __('为什么选择 %{title}', ['title' => $siteTitle]),
                    'items' => [
                        ['icon' => 'mdi mdi-check', 'title' => __('品质保证'), 'description' => __('我们致力于提供最优质的产品')],
                        ['icon' => 'mdi mdi-truck', 'title' => __('快速配送'), 'description' => __('全网快速配送，送货上门')],
                        ['icon' => 'mdi mdi-headset', 'title' => __('专业客服'), 'description' => __('7x24 小时在线客服支持')],
                    ],
                ],
                [
                    'type' => 'cta',
                    'title' => __('立即开始'),
                    'description' => __('加入我们，体验最佳服务'),
                    'button_text' => __('开始探索'),
                    'button_url' => '/',
                ],
            ],
        ];
    }

    private function generateAboutPageContent(string $siteTitle, string $siteDescription): array
    {
        $descText = $siteDescription !== '' ? $siteDescription . '。' : '';

        return [
            'page_title' => __('关于 %{title}', ['title' => $siteTitle]),
            'hero_title' => __('我们的故事'),
            'story_content' => __('%{title} 致力于为用户提供优质的产品和服务。%{description}我们相信，通过创新和用心，可以为每个人创造更好的体验。', ['title' => $siteTitle, 'description' => $descText]),
            'values' => [
                ['title' => __('用户至上'), 'description' => __('始终把用户需求放在第一位')],
                ['title' => __('创新驱动'), 'description' => __('不断探索更好的解决方案')],
                ['title' => __('诚信经营'), 'description' => __('透明公开，值得信赖')],
            ],
            'team_section' => [
                'title' => __('我们的团队'),
                'description' => __('由一群充满激情的专业人士组成'),
            ],
        ];
    }

    private function generateContactPageContent(string $siteTitle): array
    {
        return [
            'page_title' => __('联系我们'),
            'hero_title' => __('随时欢迎您的垂询'),
            'description' => __('如果您有任何问题或建议，请随时与我们联系'),
            'contact_methods' => [
                ['type' => 'email', 'label' => __('电子邮件'), 'value' => 'contact@example.com', 'icon' => 'mdi mdi-email'],
                ['type' => 'phone', 'label' => __('联系电话'), 'value' => '+86 10 1234 5678', 'icon' => 'mdi mdi-phone'],
                ['type' => 'address', 'label' => __('公司地址'), 'value' => __('某市某区某路 123 号'), 'icon' => 'mdi mdi-map-marker'],
            ],
            'form_title' => __('发送消息'),
            'form_fields' => ['name' => __('姓名'), 'email' => __('邮箱'), 'subject' => __('主题'), 'message' => __('留言内容')],
            'submit_button' => __('提交'),
        ];
    }

    private function generatePrivacyPolicyContent(string $siteTitle): array
    {
        return [
            'page_title' => __('隐私政策'),
            'last_updated' => date('Y-m-d'),
            'sections' => [
                ['title' => __('信息收集'), 'content' => __('我们收集您提供的信息以改善服务质量')],
                ['title' => __('信息使用'), 'content' => __('您的信息将仅用于提供和改善我们的服务')],
                ['title' => __('信息保护'), 'content' => __('我们采用适当的安全措施保护您的个人信息')],
                ['title' => __('第三方分享'), 'content' => __('除法律要求外，我们不会与第三方分享您的个人信息')],
                ['title' => __('用户权利'), 'content' => __('您有权访问、更正或删除您的个人信息')],
            ],
        ];
    }

    private function generateTermsContent(string $siteTitle): array
    {
        return [
            'page_title' => __('服务条款'),
            'last_updated' => date('Y-m-d'),
            'sections' => [
                ['title' => __('服务接受'), 'content' => __('使用我们的服务即表示您同意本条款')],
                ['title' => __('服务变更'), 'content' => __('我们保留随时修改服务的权利')],
                ['title' => __('用户责任'), 'content' => __('您同意合法使用我们的服务，不从事违法活动')],
                ['title' => __('知识产权'), 'content' => __('服务中的所有内容版权归我们所有')],
                ['title' => __('免责声明'), 'content' => __('服务按"原样"提供，不提供任何明示或暗示保证')],
            ],
        ];
    }

    private function generateRefundContent(string $siteTitle): array
    {
        return [
            'page_title' => __('退款政策'),
            'sections' => [
                ['title' => __('退款条件'), 'content' => __('商品在未拆封、未经使用的情况下可申请退款')],
                ['title' => __('退款流程'), 'content' => __('联系客服后 3-5 个工作日内完成退款')],
                ['title' => __('退款方式'), 'content' => __('退款将原路返回至您的支付账户')],
                ['title' => __('特殊情况'), 'content' => __('质量问题或物流损坏可随时申请退换')],
            ],
        ];
    }

    private function generateCookieContent(string $siteTitle): array
    {
        return [
            'page_title' => __('Cookie 政策'),
            'last_updated' => date('Y-m-d'),
            'sections' => [
                ['title' => __('Cookie 简介'), 'content' => __('Cookie 是存储在您设备上的小文件，用于改善用户体验')],
                ['title' => __('Cookie 用途'), 'content' => __('我们使用 Cookie 来记住您的偏好设置和分析网站流量')],
                ['title' => __('Cookie 管理'), 'content' => __('您可以在浏览器中禁用 Cookie，但这可能影响网站功能')],
            ],
        ];
    }

    private function generateBlogListContent(string $siteTitle, string $siteDescription): array
    {
        return [
            'page_title' => __('博客'),
            'hero_title' => __('阅读我们的最新文章'),
            'hero_subtitle' => $siteDescription !== '' ? $siteDescription : __('分享行业资讯与技术见解'),
            'filters' => ['category' => __('全部分类'), 'date' => __('全部时间'), 'sort' => __('最新发布')],
            'read_more_text' => __('阅读全文'),
        ];
    }

    private function generateBlogCategoryContent(string $siteTitle): array
    {
        return [
            'page_title' => __('博客分类'),
            'hero_title' => __('按分类浏览文章'),
            'categories' => [
                ['name' => __('技术文章'), 'count' => 12, 'slug' => 'tech'],
                ['name' => __('行业动态'), 'count' => 8, 'slug' => 'industry'],
                ['name' => __('产品更新'), 'count' => 5, 'slug' => 'product'],
            ],
        ];
    }

    private function generateBlogPostContent(string $siteTitle, string $siteDescription): array
    {
        return [
            'page_title' => __('文章详情'),
            'author' => __('编辑团队'),
            'publish_date' => date('Y-m-d'),
            'reading_time' => __('约 5 分钟阅读'),
            'sections' => [
                ['type' => 'intro', 'content' => $siteDescription !== '' ? $siteDescription : __('本文将介绍相关内容')],
                ['type' => 'toc', 'title' => __('目录')],
                ['type' => 'conclusion', 'title' => __('总结'), 'content' => __('希望本文对您有所帮助')],
            ],
            'share_text' => __('分享这篇文章'),
            'related_title' => __('相关阅读'),
        ];
    }

    private function generateCustomPageContent(string $siteTitle, string $siteDescription): array
    {
        return [
            'page_title' => $siteTitle,
            'hero_title' => $siteTitle,
            'hero_subtitle' => $siteDescription,
            'content' => __('在此添加您的自定义内容'),
            'components' => [
                ['type' => 'text', 'content' => __('可编辑的文本区域')],
                ['type' => 'image', 'src' => '', 'alt' => __('占位图')],
                ['type' => 'button', 'text' => __('了解更多'), 'url' => '/'],
            ],
        ];
    }

    private function generateGenericPageContent(string $siteTitle, string $siteDescription): array
    {
        return [
            'page_title' => $siteTitle,
            'hero_title' => $siteTitle,
            'hero_subtitle' => $siteDescription,
            'content' => __('页面内容占位'),
        ];
    }

    private function generateSuggestions(string $pageType, string $siteTitle, string $siteDescription): array
    {
        $suggestions = [];

        if ($pageType === 'home_page') {
            $suggestions[] = ['type' => 'improvement', 'text' => __('建议添加产品展示区域，突出核心卖点')];
            $suggestions[] = ['type' => 'improvement', 'text' => __('添加客户评价区，增强信任感')];
        }
        if ($pageType === 'about_page') {
            $suggestions[] = ['type' => 'improvement', 'text' => __('建议添加团队成员详细介绍')];
            $suggestions[] = ['type' => 'improvement', 'text' => __('添加公司历程时间线展示')];
        }
        if ($pageType === 'contact_page') {
            $suggestions[] = ['type' => 'improvement', 'text' => __('建议集成在线客服插件')];
            $suggestions[] = ['type' => 'improvement', 'text' => __('添加地图组件展示公司位置')];
        }

        $suggestions[] = ['type' => 'tip', 'text' => __('可根据实际业务调整文案和图片')];

        return $suggestions;
    }
}
