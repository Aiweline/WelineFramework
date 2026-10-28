<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Resource\Compiler;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Resource\CompilerInterface;
use Weline\Theme\Service\Spec\ThemeSpecValidator;

/**
 * `php bin/w theme:compile spec`
 *
 * 主题规范门禁：默认主题 + 全部设计主题。硬违规打印后非 0 退出，可直接接 CI。
 */
class Spec implements CompilerInterface
{
    public function compile(string $source_file = '', string $out_file = '')
    {
        /** @var ThemeSpecValidator $validator */
        $validator = ObjectManager::getInstance(ThemeSpecValidator::class);

        $roots = [BP . 'app/code/Weline/Theme/view/theme'];
        foreach ([BP . 'app/design', BP . 'design'] as $designRoot) {
            if (!is_dir($designRoot)) {
                continue;
            }
            foreach (glob($designRoot . '/*/*/frontend', GLOB_ONLYDIR) ?: [] as $themeRoot) {
                $roots[] = $themeRoot;
            }
        }

        $hard = 0;
        $soft = 0;
        foreach (array_unique($roots) as $root) {
            foreach ($validator->assertDirectory($root) as $violation) {
                $severity = $violation['severity'] === 'hard' ? 'ERROR' : 'WARN';
                if ($violation['severity'] === 'hard') {
                    $hard++;
                } else {
                    $soft++;
                }
                echo sprintf(
                    "[%s] %s %s:%d %s\n        fix: %s\n",
                    $severity,
                    $violation['rule'],
                    str_replace(BP, '', $violation['file']),
                    $violation['line'],
                    $violation['message'],
                    $violation['hint']
                );
            }
        }

        echo sprintf("Theme spec: %d hard, %d soft, roots: %d\n", $hard, $soft, count(array_unique($roots)));

        if ($hard > 0) {
            exit(1);
        }

        return 0;
    }
}
