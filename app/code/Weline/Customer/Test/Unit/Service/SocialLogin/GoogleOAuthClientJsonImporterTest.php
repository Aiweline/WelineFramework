<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Service\SocialLogin;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Service\SocialLogin\GoogleOAuthClientJsonImporter;

/**
 * @covers \Weline\Customer\Service\SocialLogin\GoogleOAuthClientJsonImporter
 */
class GoogleOAuthClientJsonImporterTest extends TestCase
{
    public function testExpandWebClientJsonIntoClientIdAndSecret(): void
    {
        $json = json_encode([
            'web' => [
                'client_id' => '985381790080-91idruk53ts1c1pblkd2lkbspaoj8dkr.apps.googleusercontent.com',
                'client_secret' => 'GOCSPX-example-secret',
                'redirect_uris' => [
                    'https://p05113ef3.test.weline.com:9555/customer/account/social-login/callback',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $result = GoogleOAuthClientJsonImporter::expandPostedValues(
            [
                GoogleOAuthClientJsonImporter::JSON_FIELD_KEY => (string)$json,
                'customer/social_login/google/client_id' => '',
                'customer/social_login/google/client_secret' => '',
            ],
            [
                GoogleOAuthClientJsonImporter::JSON_FIELD_KEY,
                'customer/social_login/google/client_id',
                'customer/social_login/google/client_secret',
            ],
        );

        $this->assertTrue($result['imported']);
        $this->assertNull($result['error']);
        $this->assertSame(
            '985381790080-91idruk53ts1c1pblkd2lkbspaoj8dkr.apps.googleusercontent.com',
            $result['values']['customer/social_login/google/client_id']
        );
        $this->assertSame('GOCSPX-example-secret', $result['values']['customer/social_login/google/client_secret']);
        $this->assertArrayNotHasKey(GoogleOAuthClientJsonImporter::JSON_FIELD_KEY, $result['values']);
        $this->assertContains(GoogleOAuthClientJsonImporter::JSON_FIELD_KEY, $result['inherit_keys']);
        $this->assertNotContains('customer/social_login/google/client_id', $result['inherit_keys']);
        $this->assertNotContains('customer/social_login/google/client_secret', $result['inherit_keys']);
    }

    public function testExpandIgnoresEmptyJsonPayload(): void
    {
        $result = GoogleOAuthClientJsonImporter::expandPostedValues(
            [
                GoogleOAuthClientJsonImporter::JSON_FIELD_KEY => "  \n",
                'customer/social_login/google/client_id' => 'keep-me',
            ],
            [],
        );

        $this->assertFalse($result['imported']);
        $this->assertNull($result['error']);
        $this->assertSame('keep-me', $result['values']['customer/social_login/google/client_id']);
        $this->assertArrayNotHasKey(GoogleOAuthClientJsonImporter::JSON_FIELD_KEY, $result['values']);
        $this->assertContains(GoogleOAuthClientJsonImporter::JSON_FIELD_KEY, $result['inherit_keys']);
    }

    public function testExpandDoesNotPolluteUnrelatedModulePosts(): void
    {
        $result = GoogleOAuthClientJsonImporter::expandPostedValues(
            [
                'captcha/google/api_key' => 'secret-from-captcha-form',
                'captcha/google/project_id' => 'demo-project',
            ],
            ['captcha/google/site_key'],
        );

        $this->assertFalse($result['imported']);
        $this->assertNull($result['error']);
        $this->assertSame('secret-from-captcha-form', $result['values']['captcha/google/api_key']);
        $this->assertSame(['captcha/google/site_key'], $result['inherit_keys']);
        $this->assertNotContains(GoogleOAuthClientJsonImporter::JSON_FIELD_KEY, $result['inherit_keys']);
    }

    public function testExpandReturnsErrorOnInvalidJson(): void
    {
        $result = GoogleOAuthClientJsonImporter::expandPostedValues(
            [GoogleOAuthClientJsonImporter::JSON_FIELD_KEY => '{not-json'],
            [],
        );

        $this->assertFalse($result['imported']);
        $this->assertSame('不是合法 JSON', $result['error']);
    }

    public function testExpandReturnsErrorWhenCredentialsMissing(): void
    {
        $result = GoogleOAuthClientJsonImporter::expandPostedValues(
            [
                GoogleOAuthClientJsonImporter::JSON_FIELD_KEY => json_encode([
                    'web' => ['client_secret' => 'only-secret'],
                ], JSON_THROW_ON_ERROR),
            ],
            [],
        );

        $this->assertFalse($result['imported']);
        $this->assertSame('缺少 client_id 或 client_secret', $result['error']);
    }

    public function testExtractCredentialsFromTopLevelShape(): void
    {
        $credentials = GoogleOAuthClientJsonImporter::extractCredentials(json_encode([
            'client_id' => 'top-level-id.apps.googleusercontent.com',
            'client_secret' => 'top-secret',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('top-level-id.apps.googleusercontent.com', $credentials['client_id']);
        $this->assertSame('top-secret', $credentials['client_secret']);
    }
}
