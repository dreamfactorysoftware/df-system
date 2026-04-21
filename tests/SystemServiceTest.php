<?php

use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Arr;

class SystemServiceTest extends \DreamFactory\Core\Testing\TestCase
{
    const RESOURCE = 'service';

    protected $serviceId = 'system';

    public function setUp(): void
    {
        parent::setUp();
        // Authenticate as sys admin so RBAC filtering doesn't hide services
        Session::authenticate(['email' => 'admin@test.com', 'password' => 'Dream123!']);
    }

    /************************************************
     * Testing GET
     ************************************************/

    public function testGETService()
    {
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE);
        $content = $rs->getContent();
        $services = Arr::get($content, static::$wrapper);

        $this->assertNotEmpty($services, 'Service list should not be empty');
        $names = array_column($services, 'name');
        $this->assertContains('system', $names, 'System service should be in the service list');
    }

    public function testGETServiceById()
    {
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/1');
        $content = $rs->getContent();

        $this->assertEquals('system', Arr::get($content, 'name'));
    }

    public function testGETServiceByIdWithFields()
    {
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/1', [ApiOptions::FIELDS => 'name,label,id']);
        $content = $rs->getContent();

        $this->assertEquals('system', Arr::get($content, 'name'));
        $this->assertEquals('System Management', Arr::get($content, 'label'));
        $this->assertEquals(1, Arr::get($content, 'id'));
        $this->assertEquals(3, count($content));
    }
}