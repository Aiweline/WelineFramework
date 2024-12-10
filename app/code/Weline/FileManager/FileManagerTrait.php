<?php

namespace Weline\FileManager;

trait FileManagerTrait
{
    public function getTarget(): string
    {
        return $this->getData('target') ?? '';
    }

    public function setTarget(string $target): static
    {
        return $this->setData('target', $target);
    }

    public function getPath(): string
    {
        return $this->getData('path') ?? '';
    }

    public function setPath(string $path): static
    {
        return $this->setData('path', $path);
    }

    public function getValue(): string
    {
        return $this->getData('value') ?? '';
    }

    public function setValue(string $value): static
    {
        return $this->setData('value', $value);
    }

    public function getTitle(): string
    {
        return __($this->getData('title') ?? '从图库选择');
    }

    public function setTitle(string $title): static
    {
        if (empty($title)) {
            return $this;
        }
        return $this->setData('title', $title);
    }

    public function isMulti(): bool
    {
        return $this->getData('multi') ?? false;
    }

    public function setMulti(bool $multi): static
    {
        return $this->setData('multi', $multi);
    }

    public function getVars(): string
    {
        return $this->getData('vars') ?? '';
    }

    public function setVars(string $vars): static
    {
        return $this->setData('vars', $vars);
    }

    public function setWidth(string|int $width): static
    {
        return $this->setData('width', (int)$width);
    }

    public function getWidth(): int
    {
        return $this->getData('width');
    }

    public function setHeight(string|int $height): static
    {
        return $this->setData('height', (int)$height);
    }

    public function getHeight(): int
    {
        return $this->getData('height');
    }

    public function setExt(string $ext): static
    {
        return $this->setData('ext', $ext);
    }

    public function getExt(): string
    {
        return $this->getData('ext');
    }

    public function setSize(int $size): static
    {
        return $this->setData('size', $size);
    }

    public function getSize(): string
    {
        return $this->getData('size');
    }
}
