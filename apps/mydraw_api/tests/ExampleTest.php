<?php

namespace Tests;

use Laravel\Lumen\Testing\DatabaseMigrations;
use Laravel\Lumen\Testing\DatabaseTransactions;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function test_that_base_endpoint_returns_a_successful_response()
    {
        $this->get('/');

        $this->assertEquals(200, $this->response->getStatusCode());
        $json = json_decode($this->response->getContent(), true);
        $this->assertIsArray($json);
        $this->assertTrue($json['Status'] ?? false);
        $this->assertSame(200, $json['Code'] ?? null);
        $this->assertArrayHasKey('Data', $json);
        $this->assertSame($this->app->version(), $json['Data']['version'] ?? null);
        $this->assertSame('ok', $json['Data']['status'] ?? null);
    }
}
