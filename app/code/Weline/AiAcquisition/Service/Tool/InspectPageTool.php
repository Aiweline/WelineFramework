<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Tool;

use Weline\Ai\Api\ToolInterface;
use Weline\AiAcquisition\Service\Search\PageInspector;
use Weline\Framework\Manager\ObjectManager;

/**
 * 页面检查工具：打开候选页面，抽取公司名、公开邮箱、国家提示与正文摘要作为证据。
 *
 * 抓取失败返回 error，由模型决定换线索还是换关键词，不影响循环继续。
 */
class InspectPageTool implements ToolInterface
{
    public function getName(): string
    {
        return 'inspect_page';
    }

    public function getDescription(): string
    {
        return (string)__('Open one public page and extract title, meta description, company name, public emails, country hints and a text excerpt. Use it to verify buyer intent before saving a lead.');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'Public http(s) URL of the candidate page (search result link).',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $args): mixed
    {
        $url = trim((string)($args['url'] ?? ''));
        if ($url === '') {
            return ['error' => (string)__('url 不能为空，请给出搜索结果中的公开页面地址。')];
        }

        try {
            $result = ObjectManager::getInstance(PageInspector::class)->inspect($url);
        } catch (\Throwable $e) {
            return ['error' => (string)__('页面检查失败：%{1}', [$e->getMessage()])];
        }

        if (isset($result['error']) && (string)$result['error'] !== '') {
            return ['url' => $url, 'error' => (string)$result['error']];
        }

        return $result;
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
