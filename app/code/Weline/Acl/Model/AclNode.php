<?php
declare(strict_types=1);

namespace Weline\Acl\Model;

/**
 * 轻量级 ACL 节点，用于权限分配树渲染。
 *
 * 替代完整的 Acl Model 以避免 ORM 初始化开销（反射、Schema 解析、DB 连接等）。
 * 874 条记录下，从 ~500ms 降低到 ~5ms 的对象构造时间。
 */
class AclNode implements \ArrayAccess
{
    private array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function getSourceId(): string
    {
        return (string) ($this->data[Acl::schema_fields_SOURCE_ID] ?? '');
    }

    public function getSourceName(): string
    {
        return (string) ($this->data[Acl::schema_fields_SOURCE_NAME] ?? '');
    }

    public function getParentSource(): string
    {
        return (string) ($this->data[Acl::schema_fields_PARENT_SOURCE] ?? '');
    }

    public function getType(): string
    {
        return (string) ($this->data[Acl::schema_fields_TYPE] ?? '');
    }

    public function getIcon(): string
    {
        return (string) ($this->data[Acl::schema_fields_ICON] ?? '');
    }

    public function getMethod(): string
    {
        return (string) ($this->data[Acl::schema_fields_METHOD] ?? '');
    }

    public function getDocument(): string
    {
        return (string) ($this->data[Acl::schema_fields_DOCUMENT] ?? '');
    }

    public function getModule(): string
    {
        return (string) ($this->data[Acl::schema_fields_MODULE] ?? '');
    }

    public function getOrder(): int
    {
        return (int) ($this->data[Acl::schema_fields_ORDER] ?? 0);
    }

    public function getRoute(): string
    {
        return (string) ($this->data[Acl::schema_fields_ROUTE] ?? '');
    }

    public function getSub(): array
    {
        return $this->data['sub'] ?? [];
    }

    public function getData(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->data;
        }
        return $this->data[$key] ?? null;
    }

    public function setData(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[$key] = $value;
        }
        return $this;
    }

    public function getId(): mixed
    {
        return $this->data[Acl::schema_fields_ACL_ID] ?? null;
    }

    // ArrayAccess — 支持模板 {{node.field}} 语法

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }
}
