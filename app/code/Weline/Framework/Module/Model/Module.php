<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Model;

use JetBrains\PhpStorm\Pure;
use Weline\Framework\DataObject\DataObject;

class Module extends DataObject
{
    public const position       = 'position';
    public const name           = 'name';
    public const status         = 'status';
    public const version        = 'version';
    public const router         = 'router';
    public const description    = 'description';
    public const base_path      = 'base_path';
    public const namespace_path = 'namespace_path';
    public const path           = 'path';
    public const dependencies   = 'dependencies';


    public function setPosition(string $position): self
    {
        $this->setData(self::position, $position);
        return $this;
    }

    public function getPosition(): string
    {
        return $this->getData(self::position);
    }

    /**
     * @return bool
     */
    public function isStatus(): bool
    {
        return $this->getData(self::status);
    }

    /**
     * @param bool $status
     */
    public function setStatus(bool $status): static
    {
        $this->setData(self::status, $status);
        return $this;
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        return $this->getData(self::version);
    }

    /**
     * @param string $version
     */
    public function setVersion(string $version): static
    {
        $this->setData(self::version, $version);
        return $this;
    }

    /**
     * @return string
     */
    public function getRouter(): string
    {
        return $this->getData(self::router);
    }

    /**
     * @param string $router
     *
     * @return Module
     */
    public function setRouter(array|string $router): static
    {
        if (is_array($router)) {
            $this->setData(self::router, $router);
        } else {
            $this->addData([self::router => $router]);
        }
        return $this;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->getData(self::description);
    }

    /**
     * @param string $description
     *
     * @return Module
     */
    public function setDescription(string $description): static
    {
        $this->setData(self::description, $description);
        return $this;
    }

    /**
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->getData(self::base_path);
    }

    /**
     * @param string $base_path
     *
     * @return Module
     */
    public function setBasePath(string $base_path): static
    {
        $this->setData(self::base_path, $base_path);
        return $this;
    }


    /**
     * @return string
     */
    public function getNamespacePath(): string
    {
        return $this->getData(self::namespace_path);
    }

    /**
     * @param string $namespace_path
     *
     * @return Module
     */
    public function setNamespacePath(string $namespace_path): static
    {
        $this->setData(self::namespace_path, $namespace_path);
        return $this;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->getData(self::path);
    }

    /**
     * @param string $path
     *
     * @return Module
     */
    public function setPath(string $path): static
    {
        $this->setData(self::path, $path);
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->getData(self::name);
    }

    /**
     * @param string $name
     *
     * @return Module
     */
    public function setName(string $name): static
    {
        $this->setData(self::name, $name);
        return $this;
    }

    public function getModuleFile(string $filename): string
    {
        return BP . $this->getBasePath() . $filename;
    }

    /**
     * @return string
     */
    public function getDependencies(): string
    {
        return $this->getData(self::dependencies);
    }

    /**
     * @param array $dependencies
     *
     * @return Module
     */
    public function setDependencies(array $dependencies): static
    {
        $this->setData(self::dependencies, $dependencies);
        return $this;
    }
}
