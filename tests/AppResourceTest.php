<?php

use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Models\App;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Arr;

class AppResourceTest extends \DreamFactory\Core\Testing\TestCase
{
    const RESOURCE = 'app';

    protected $serviceId = 'system';

    /** @var int[] ids of the apps created by a test */
    protected $created = [];

    public function setUp(): void
    {
        parent::setUp();
        Session::setUserInfo(['id' => 1, 'email' => 'admin@test.com', 'is_sys_admin' => true]);
    }

    public function tearDown(): void
    {
        foreach ($this->created as $id) {
            if ($app = App::find($id)) {
                $app->delete();
            }
        }
        parent::tearDown();
    }

    /** PATCH app/{id}?regenerate=true rotates that app's key and no other. */
    public function testRegenerateRotatesOnlyTargetApp()
    {
        // explicit keys: App::generateApiKey() is time()-based, so a key generated in the
        // same second as the regenerate call would compare equal
        $this->createApp('test_regenerate_a', str_repeat('a', 64));
        $b = $this->createApp('test_regenerate_b', str_repeat('b', 64));
        $before = App::pluck('api_key', 'id')->all();

        $rs = $this->makeRequest(
            Verbs::PATCH,
            static::RESOURCE . '/' . $b,
            [ApiOptions::REGENERATE => 'true', ApiOptions::FIELDS => 'id,api_key']
        );
        $this->assertSame(200, $rs->getStatusCode());

        $after = App::pluck('api_key', 'id')->all();
        $this->assertNotSame($before[$b], $after[$b], 'target app key was not rotated');
        $this->assertSame(Arr::except($before, [$b]), Arr::except($after, [$b]), 'another app key changed');
        // the response still describes the target app, now with its new key
        $this->assertEquals($b, Arr::get($rs->getContent(), 'id'));
        $this->assertSame($after[$b], Arr::get($rs->getContent(), 'api_key'));
    }

    public function testRegenerateUnknownAppIsNotFound()
    {
        $before = App::pluck('api_key', 'id')->all();
        $missing = (int)App::max('id') + 1000;

        $rs = $this->makeRequest(Verbs::PATCH, static::RESOURCE . '/' . $missing, [ApiOptions::REGENERATE => 'true']);

        $this->assertSame(404, $rs->getStatusCode());
        $this->assertSame($before, App::pluck('api_key', 'id')->all());
    }

    protected function createApp($name, $apiKey)
    {
        $record = ['name' => $name, 'type' => 0, 'api_key' => $apiKey];
        $rs = $this->makeRequest(Verbs::POST, static::RESOURCE, [], [static::$wrapper => [$record]]);
        $this->assertSame(201, $rs->getStatusCode());
        $id = (int)Arr::get($rs->getContent(), static::$wrapper . '.0.id');
        $this->created[] = $id;

        return $id;
    }
}
