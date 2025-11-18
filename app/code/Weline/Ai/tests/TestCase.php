<?php

declare(strict_types=1);

namespace Weline\Ai\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base Test Case for Weline AI Module
 * 
 * Provides common functionality for all test cases in the AI module.
 * 
 * @package Weline_Ai
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Set up test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Common setup for all tests
    }

    /**
     * Tear down test environment
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Common cleanup for all tests
        parent::tearDown();
    }

    /**
     * Assert that a response matches expected structure
     *
     * @param array $expected Expected structure
     * @param array $actual Actual response
     * @return void
     */
    protected function assertResponseStructure(array $expected, array $actual): void
    {
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual, "Response missing key: {$key}");
            
            if (is_array($value)) {
                $this->assertIsArray($actual[$key], "Response key {$key} should be an array");
                $this->assertResponseStructure($value, $actual[$key]);
            }
        }
    }

    /**
     * Assert that an API response is successful
     *
     * @param array $response API response
     * @return void
     */
    protected function assertApiSuccess(array $response): void
    {
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success'], 'API response should indicate success');
    }

    /**
     * Assert that an API response contains an error
     *
     * @param array $response API response
     * @return void
     */
    protected function assertApiError(array $response): void
    {
        $this->assertArrayHasKey('success', $response);
        $this->assertFalse($response['success'], 'API response should indicate error');
        $this->assertArrayHasKey('error', $response);
    }
}

