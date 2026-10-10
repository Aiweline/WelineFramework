<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Api\OpsLaneContributionInterface;

final class OpsLaneRegistry
{
    /** @var list<OpsLaneContributionInterface>|null */
    private ?array $lanes = null;

    /**
     * @return list<OpsLaneContributionInterface>
     */
    public function all(): array
    {
        if ($this->lanes !== null) {
            return $this->lanes;
        }

        $lanes = [];
        $base = BP . '/app/code';
        $pattern = $base . '/*/*/extends/module/Weline_Ops/Lane/*.php';
        foreach (glob($pattern) ?: [] as $file) {
            $file = (string)$file;
            $class = $this->classFromFile($file);
            if ($class === null) {
                continue;
            }
            if (!class_exists($class, false)) {
                require_once $file;
            }
            if (!class_exists($class)) {
                continue;
            }
            try {
                $instance = ObjectManager::getInstance($class);
            } catch (\Throwable) {
                continue;
            }
            if ($instance instanceof OpsLaneContributionInterface) {
                $lanes[] = $instance;
            }
        }

        usort(
            $lanes,
            static fn (OpsLaneContributionInterface $a, OpsLaneContributionInterface $b): int => $a->getSortOrder() <=> $b->getSortOrder()
        );

        return $this->lanes = $lanes;
    }

    private function classFromFile(string $file): ?string
    {
        $content = @file_get_contents($file);
        if (!is_string($content) || $content === '') {
            return null;
        }
        if (!preg_match('/namespace\s+([^;]+);/', $content, $ns)
            || !preg_match('/class\s+(\w+)/', $content, $cls)) {
            return null;
        }

        return trim($ns[1]) . '\\' . trim($cls[1]);
    }
}
