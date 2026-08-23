<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Login flash / MessageManager titles must live in Weline_Admin CSV so WLS
 * Phrase layers (request module = Admin) can translate them under en_US.
 */
final class LoginFlashI18nContractTest extends TestCase
{
    public function testAdminEnUsCoversLoginFlashAndMessageTitles(): void
    {
        $csv = $this->loadCsv(dirname(__DIR__, 3) . '/i18n/en_US.csv');

        $required = [
            '验证码错误！' => 'Incorrect verification code!',
            '请输入验证码！' => 'Please enter the verification code!',
            '错误！' => 'Error!',
            '提示！' => 'Prompt!',
            '警告！' => 'Warning!',
            '异常警告！' => 'Exception warning!',
            '操作成功！' => 'Operation successful!',
            '登录凭据错误！' => 'Invalid login credentials!',
            '人机验证失败或已过期，请重试' => null,
            '账户不存在！' => null,
            '账户被禁用！' => null,
        ];

        foreach ($required as $source => $exact) {
            self::assertArrayHasKey($source, $csv, "missing Admin en_US key: {$source}");
            self::assertNotSame($source, $csv[$source], "untranslated Admin en_US key: {$source}");
            if ($exact !== null) {
                self::assertSame($exact, $csv[$source], "unexpected translation for {$source}");
            }
        }
    }

    public function testGateExposesTranslatableChineseSourceKeys(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/BackendVerificationCodeGate.php');
        self::assertStringContainsString("'请输入验证码！'", $source);
        self::assertStringContainsString("'验证码错误！'", $source);
        $login = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Login.php');
        self::assertStringContainsString('MessageManager::error(__($verificationCodeState[\'error_message\']))', $login);
    }

    /**
     * @return array<string, string>
     */
    private function loadCsv(string $path): array
    {
        self::assertFileExists($path);
        $handle = fopen($path, 'rb');
        self::assertNotFalse($handle);
        $map = [];
        try {
            while (($row = fgetcsv($handle)) !== false) {
                if (!isset($row[0], $row[1])) {
                    continue;
                }
                $map[trim((string)$row[0])] = trim((string)$row[1]);
            }
        } finally {
            fclose($handle);
        }

        return $map;
    }
}
