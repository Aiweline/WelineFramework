<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx\Runtime;

/**
 * Coordinates multiple Nginx files as one rollback unit.
 *
 * Every item uses NginxConfigPublication's same-filesystem transaction. Full
 * preflight happens before the first active file changes; an activation error
 * reverses every already-published item.
 */
final class NginxPublicationBundle
{
    /**
     * @param array<string,string> $contentsByActiveFile
     * @return array<string,string>
     */
    public function stage(array $contentsByActiveFile, string $scope = 'nginx bundle'): array
    {
        if ($contentsByActiveFile === []) {
            throw new \InvalidArgumentException('Nginx publication bundle must not be empty.');
        }
        $candidates = [];
        try {
            foreach ($contentsByActiveFile as $active => $contents) {
                $active = (string)$active;
                if ($active === '' || !\is_string($contents) || $contents === '') {
                    throw new \InvalidArgumentException('Nginx publication bundle item is invalid.');
                }
                $candidates[$active] = (new NginxConfigPublication($active, $scope))
                    ->stageCandidate($contents);
            }
            return $candidates;
        } catch (\Throwable $throwable) {
            foreach ($candidates as $active => $candidate) {
                (new NginxConfigPublication($active, $scope))->discardCandidate($candidate);
            }
            throw $throwable;
        }
    }

    /**
     * @param array<string,string> $candidateByActiveFile
     * @return array{transaction_id:string,scope:string,items:list<array{active:string,rollback:string|null}>}
     */
    public function publish(
        array $candidateByActiveFile,
        string $transactionId,
        string $scope = 'nginx bundle',
    ): array {
        if ($candidateByActiveFile === []) {
            throw new \InvalidArgumentException('Nginx publication bundle must not be empty.');
        }
        $publications = [];
        foreach ($candidateByActiveFile as $active => $candidate) {
            $active = (string)$active;
            $candidate = (string)$candidate;
            if (isset($publications[$active])) {
                throw new \InvalidArgumentException('Nginx publication bundle contains a duplicate active file.');
            }
            $publication = new NginxConfigPublication($active, $scope);
            $publication->rollbackPathForTransaction($transactionId);
            $publication->validateCandidate($candidate);
            $publications[$active] = [$publication, $candidate];
        }

        $items = [];
        try {
            foreach ($publications as $active => [$publication, $candidate]) {
                /** @var NginxConfigPublication $publication */
                $published = $publication->publishCandidate($candidate, $transactionId);
                $items[] = [
                    'active' => $active,
                    'rollback' => $published['rollback'],
                ];
            }
        } catch (\Throwable $throwable) {
            foreach (\array_reverse($items) as $item) {
                (new NginxConfigPublication($item['active'], $scope))
                    ->rollbackPublished($item['rollback']);
            }
            throw $throwable;
        }

        return [
            'transaction_id' => \strtolower($transactionId),
            'scope' => $scope,
            'items' => $items,
        ];
    }

    /** @param array{transaction_id:string,scope:string,items:list<array{active:string,rollback:string|null}>} $bundle */
    public function rollback(array $bundle): void
    {
        $scope = \trim((string)($bundle['scope'] ?? ''));
        $items = $bundle['items'] ?? [];
        if ($scope === '' || !\is_array($items) || !\array_is_list($items)) {
            throw new \InvalidArgumentException('Nginx publication rollback bundle is invalid.');
        }
        foreach (\array_reverse($items) as $item) {
            if (!\is_array($item) || \trim((string)($item['active'] ?? '')) === '') {
                throw new \InvalidArgumentException('Nginx publication rollback item is invalid.');
            }
            (new NginxConfigPublication((string)$item['active'], $scope))
                ->rollbackPublished(\is_string($item['rollback'] ?? null) ? $item['rollback'] : null);
        }
    }

    /** @param array{transaction_id:string,scope:string,items:list<array{active:string,rollback:string|null}>} $bundle */
    public function commit(array $bundle): bool
    {
        $scope = \trim((string)($bundle['scope'] ?? ''));
        $items = $bundle['items'] ?? [];
        if ($scope === '' || !\is_array($items) || !\array_is_list($items)) {
            return false;
        }
        foreach ($items as $item) {
            if (!\is_array($item) || \trim((string)($item['active'] ?? '')) === '') {
                return false;
            }
            $rollback = \is_string($item['rollback'] ?? null) ? $item['rollback'] : null;
            if (!(new NginxConfigPublication((string)$item['active'], $scope))
                ->commitPublished($rollback)
            ) {
                return false;
            }
        }
        return true;
    }
}
