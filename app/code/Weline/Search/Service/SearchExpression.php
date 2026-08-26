<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Search\Dto\SearchRequest;

/**
 * Declarative match/filter/sort for engine compilation.
 */
final class SearchExpression
{
    /** @var list<string> */
    private array $matchFields = [];

    /** @var array<string, mixed> */
    private array $filters = [];

    /** @var list<array{field:string,dir:string}> */
    private array $sorts = [];

    private int $limit = 24;

    private int $offset = 0;

    public static function of(SearchRequest $request): self
    {
        $expr = new self();
        $expr->limit = $request->pageSize;
        $expr->offset = \max(0, ($request->page - 1) * $request->pageSize);

        return $expr;
    }

    /**
     * @param list<string>|string $fields
     */
    public function match(array|string $fields): self
    {
        $list = \is_array($fields) ? $fields : [$fields];
        foreach ($list as $field) {
            $name = \trim((string)$field);
            if ($name !== '') {
                $this->matchFields[] = $name;
            }
        }
        $this->matchFields = \array_values(\array_unique($this->matchFields));

        return $this;
    }

    public function filter(string $field, mixed $value): self
    {
        $name = \trim($field);
        if ($name !== '') {
            $this->filters[$name] = $value;
        }

        return $this;
    }

    public function sort(string $field, string $dir = 'asc'): self
    {
        $name = \trim($field);
        if ($name === '') {
            return $this;
        }
        $direction = \strtolower($dir) === 'desc' ? 'desc' : 'asc';
        $this->sorts[] = ['field' => $name, 'dir' => $direction];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = \max(1, \min(48, $limit));

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = \max(0, $offset);

        return $this;
    }

    /** @return list<string> */
    public function matchFields(): array
    {
        return $this->matchFields;
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->filters;
    }

    /** @return list<array{field:string,dir:string}> */
    public function sorts(): array
    {
        return $this->sorts;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}
