<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Frontend\HeadPolicyProvider;

use Weline\Frontend\Interface\HeadPolicyProviderInterface;

class DefaultTitlePolicyProvider implements HeadPolicyProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provide($template, array $policy, array $context): array
    {
        $provided = [
            'separator' => ' | ',
            'append_site_name' => true,
            'site_name_position' => 'suffix',
            'deduplicate_site_name' => true,
            'home_title_mode' => 'site_only',
            'pagination_label' => '第 %{page} 页',
        ];

        $customPolicy = [];
        if (is_object($template) && method_exists($template, 'getData')) {
            $customPolicy = $template->getData('head_policy') ?? [];
        }

        return is_array($customPolicy) ? array_replace($provided, $customPolicy) : $provided;
    }
}
