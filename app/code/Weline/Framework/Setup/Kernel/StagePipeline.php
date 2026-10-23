<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Kernel;

class StagePipeline
{
    private array $stages = [];

    public function __construct(private UpgradeSession $session)
    {
    }

    public function add(UpgradeStageInterface $stage): void
    {
        $key = $stage->key();
        if (isset($this->stages[$key])) {
            throw new \InvalidArgumentException('Duplicate stage key: ' . $key);
        }
        $this->stages[$key] = $stage;
    }

    public function stages(): array
    {
        return array_keys($this->stages);
    }

    public function run(): int
    {
        $graph = [];
        foreach ($this->stages as $key => $stage) {
            $graph[$key] = [];
            foreach ($stage->requires() as $dependency) {
                if (!isset($this->stages[$dependency])) {
                    throw new \RuntimeException('Missing stage dependency: ' . $dependency);
                }
                $graph[$key][] = $dependency;
            }
        }

        $ordered = [];
        $state = [];
        $visit = static function (string $key) use (&$visit, &$state, &$ordered, $graph): void {
            if (($state[$key] ?? null) === 2) {
                return;
            }
            if (($state[$key] ?? null) === 1) {
                throw new \RuntimeException('Circular stage dependency: ' . $key);
            }
            $state[$key] = 1;
            foreach ($graph[$key] as $dependency) {
                $visit($dependency);
            }
            $state[$key] = 2;
            $ordered[] = $key;
        };
        foreach (array_keys($graph) as $key) {
            $visit($key);
        }

        foreach ($ordered as $key) {
            $stage = $this->stages[$key];
            if (!$stage->shouldRun()) {
                $this->session->recordSkip($key, 'shouldRun=false');
                continue;
            }
            $result = $stage->run();
            $this->session->recordStage($key, $result->status(), 0.0, $result->meta());
            if ($result->isFatal()) {
                return $this->session->closeout(false);
            }
        }
        return $this->session->closeout(true);
    }
}
