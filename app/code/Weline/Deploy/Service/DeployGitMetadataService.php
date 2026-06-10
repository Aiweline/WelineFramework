<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * 从 Git 工作区读取 commit、branch、tag 等元数据。
 */
class DeployGitMetadataService
{
    private string $root;

    public function __construct()
    {
        $this->root = BP;
    }

    /**
     * 获取当前 HEAD 的短 SHA（7 位）。
     */
    public function getShortCommit(): string
    {
        return trim((string)shell_exec(
            'cd ' . escapeshellarg($this->root) . ' && git rev-parse --short HEAD 2>/dev/null'
        ));
    }

    /**
     * 获取当前 HEAD 的完整 SHA。
     */
    public function getFullCommit(): string
    {
        return trim((string)shell_exec(
            'cd ' . escapeshellarg($this->root) . ' && git rev-parse HEAD 2>/dev/null'
        ));
    }

    /**
     * 获取当前分支名；detached HEAD 时返回空字符串。
     */
    public function getCurrentBranch(): string
    {
        $result = trim((string)shell_exec(
            'cd ' . escapeshellarg($this->root) . ' && git rev-parse --abbrev-ref HEAD 2>/dev/null'
        ));
        return ($result === 'HEAD') ? '' : $result;
    }

    /**
     * Git fetch origin，可选 tags。
     */
    public function fetch(bool $tags = false): void
    {
        $cmd = 'cd ' . escapeshellarg($this->root) . ' && git fetch origin' . ($tags ? ' --tags' : '');
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException(__('Git fetch 失败：%{1}', [implode("\n", $output)]));
        }
    }

    /**
     * 切换到指定 tag（detached HEAD）。
     */
    public function checkoutTag(string $tag): void
    {
        // 先 fetch tags 确保 tag 存在
        $this->fetch(true);
        exec(
            'cd ' . escapeshellarg($this->root) . ' && git checkout ' . escapeshellarg($tag) . ' 2>&1',
            $output,
            $exitCode
        );
        if ($exitCode !== 0) {
            throw new \RuntimeException(__('Git checkout tag 失败：%{1}', [implode("\n", $output)]));
        }
    }

    /**
     * 恢复到指定分支（从 detached HEAD 恢复）。
     */
    public function checkoutBranch(string $branch): void
    {
        exec(
            'cd ' . escapeshellarg($this->root) . ' && git checkout ' . escapeshellarg($branch) . ' 2>&1',
            $output,
            $exitCode
        );
        if ($exitCode !== 0) {
            throw new \RuntimeException(__('Git checkout 分支失败：%{1}', [implode("\n", $output)]));
        }
    }

    /**
     * reset --hard origin/{branch}
     */
    public function resetHard(string $branch): void
    {
        exec(
            'cd ' . escapeshellarg($this->root) . ' && git reset --hard origin/' . escapeshellarg($branch) . ' 2>&1',
            $output,
            $exitCode
        );
        if ($exitCode !== 0) {
            throw new \RuntimeException(__('Git reset 失败：%{1}', [implode("\n", $output)]));
        }
    }

    /**
     * git pull --ff-only origin {branch}
     */
    public function pullFastForward(string $branch): void
    {
        exec(
            'cd ' . escapeshellarg($this->root) . ' && git pull --ff-only origin ' . escapeshellarg($branch) . ' 2>&1',
            $output,
            $exitCode
        );
        if ($exitCode !== 0) {
            throw new \RuntimeException(__('Git pull 失败：%{1}', [implode("\n", $output)]));
        }
    }

    /**
     * 获取 tag 指向的完整 commit SHA（不依赖当前 checkout）。
     */
    public function getTagCommit(string $tag): string
    {
        return trim((string)shell_exec(
            'cd ' . escapeshellarg($this->root) . ' && git rev-parse ' . escapeshellarg($tag) . ' 2>/dev/null'
        ));
    }
}
