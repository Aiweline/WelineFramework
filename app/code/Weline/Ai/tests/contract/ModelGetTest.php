<?php

declare(strict_types=1);

namespace Weline\Ai\Tests\Contract;

use Weline\Ai\Tests\TestCase;

/**
 * Contract Test for GET /api/v1/model/{id} endpoint
 * 
 * Verifies that the Model Get API endpoint adheres to the defined contract.
 * Based on specs/001-app-code-weline/contracts/model_get.json
 * 
 * @package Weline_Ai
 */
class ModelGetTest extends TestCase
{
    /**
     * Test that model get endpoint returns correct response structure
     *
     * @return void
     */
    public function testModelGetEndpointReturnsCorrectStructure(): void
    {
        $this->markTestIncomplete('Model get endpoint not yet implemented');
        
        $modelId = 1;
        $headers = [
            'X-API-Version' => 'v1',
        ];
        
        // Expected response structure
        $expectedStructure = [
            'success' => true,
            'data' => [
                'id' => 0,
                'supplier' => '',
                'name' => '',
                'model_code' => '',
                'version' => '',
                'is_copy' => false,
                'origin_model_id' => null,
            ],
        ];
        
        // Make API request (will be implemented)
        // $response = $this->get("/api/v1/model/{$modelId}", $headers);
        
        // Assertions
        // $this->assertEquals(200, $response->getStatusCode());
        // $this->assertApiSuccess($response->json());
        // $this->assertResponseStructure($expectedStructure, $response->json());
        // $this->assertEquals($modelId, $response->json()['data']['id']);
        // $this->assertIsString($response->json()['data']['model_code']);
        // $this->assertIsBool($response->json()['data']['is_copy']);
    }

    /**
     * Test that model get endpoint handles non-existent models
     *
     * @return void
     */
    public function testModelGetEndpointHandlesNonExistentModel(): void
    {
        $this->markTestIncomplete('Model get endpoint not yet implemented');
        
        $nonExistentModelId = 99999;
        
        // $response = $this->get("/api/v1/model/{$nonExistentModelId}");
        // $this->assertEquals(404, $response->getStatusCode());
        // $this->assertApiError($response->json());
    }

    /**
     * Test that model get endpoint returns copy model information
     *
     * @return void
     */
    public function testModelGetEndpointReturnsCopyModelInfo(): void
    {
        $this->markTestIncomplete('Model get endpoint not yet implemented');
        
        // Create a copy model first
        // Then retrieve it
        // Verify is_copy is true and origin_model_id is set
    }
}

