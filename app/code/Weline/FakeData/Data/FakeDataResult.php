<?php

declare(strict_types=1);

namespace Weline\FakeData\Data;

class FakeDataResult
{
    private int $created = 0;
    private int $updated = 0;
    private int $skipped = 0;
    private int $deleted = 0;
    /** @var array<int, string> */
    private array $warnings = [];
    /** @var array<int, string> */
    private array $errors = [];

    public static function error(string $message): self
    {
        return (new self())->addError($message);
    }

    public function addCreated(int $count = 1): self
    {
        $this->created += max(0, $count);
        return $this;
    }

    public function addUpdated(int $count = 1): self
    {
        $this->updated += max(0, $count);
        return $this;
    }

    public function addSkipped(int $count = 1): self
    {
        $this->skipped += max(0, $count);
        return $this;
    }

    public function addDeleted(int $count = 1): self
    {
        $this->deleted += max(0, $count);
        return $this;
    }

    public function addWarning(string $message): self
    {
        $message = trim($message);
        if ($message !== '') {
            $this->warnings[] = $message;
        }
        return $this;
    }

    public function addError(string $message): self
    {
        $message = trim($message);
        if ($message !== '') {
            $this->errors[] = $message;
        }
        return $this;
    }

    public function merge(self $result): self
    {
        $data = $result->toArray();
        $this->created += (int)$data['created'];
        $this->updated += (int)$data['updated'];
        $this->skipped += (int)$data['skipped'];
        $this->deleted += (int)$data['deleted'];
        $this->warnings = array_merge($this->warnings, $data['warnings']);
        $this->errors = array_merge($this->errors, $data['errors']);
        return $this;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array{created:int,updated:int,skipped:int,deleted:int,warnings:array<int,string>,errors:array<int,string>}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'deleted' => $this->deleted,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }
}

