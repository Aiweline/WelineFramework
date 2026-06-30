<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

final class ZhHansLoginMessagesTest extends TestCase
{
    public function testLoginFailureMessagesDefaultToChinese(): void
    {
        $translations = $this->loadTranslations();

        $this->assertSame('请输入用户名/邮箱和密码。', $translations['Username/email and password are required.'] ?? null);
        $this->assertSame('用户不存在。', $translations['The user does not exist.'] ?? null);
        $this->assertSame('登录尝试次数过多，请稍后再试。', $translations['Too many login attempts. Please try again later.'] ?? null);
        $this->assertSame('密码错误。', $translations['The password is incorrect.'] ?? null);
        $this->assertSame('登录失败：%{1}', $translations['Login failed: %{1}'] ?? null);
    }

    private function loadTranslations(): array
    {
        $file = dirname(__DIR__, 3) . '/i18n/zh_Hans_CN.csv';
        $this->assertFileExists($file);

        $translations = [];
        $handle = fopen($file, 'rb');
        $this->assertIsResource($handle);

        try {
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) >= 2) {
                    $translations[(string) $row[0]] = (string) $row[1];
                }
            }
        } finally {
            fclose($handle);
        }

        return $translations;
    }
}
