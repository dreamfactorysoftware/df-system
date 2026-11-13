<?php

use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\System\Models\ServerSetting;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ServerSettingsTest extends \DreamFactory\Core\Testing\TestCase
{
    const RESOURCE = 'server_setting';

    const FULL_MASK = VerbsMask::GET_MASK | VerbsMask::POST_MASK | VerbsMask::PUT_MASK | VerbsMask::PATCH_MASK | VerbsMask::DELETE_MASK;

    protected $serviceId = 'system';

    public function setUp(): void
    {
        parent::setUp();
        // no JWT secret in the stock test container, so seed the admin session directly
        Session::setUserInfo(['id' => 1, 'email' => 'admin@test.com', 'is_sys_admin' => true]);
        DB::table('server_settings')->delete();
        Cache::forget(ServerSetting::CACHE_KEY);
        ServerSetting::reset();
    }

    public function tearDown(): void
    {
        ServerSetting::$suspended = false;
        if (Schema::hasTable('server_settings')) {
            DB::table('server_settings')->delete();
        }
        Cache::forget(ServerSetting::CACHE_KEY);
        ServerSetting::reset();
        parent::tearDown();
    }

    public function testOverrideWinsOverEnv()
    {
        config(['logging.log_level' => 'debug', 'logging.channels.single.level' => 'debug']);
        ServerSetting::set('log_level', 'error');
        ServerSetting::applyAll();

        $this->assertSame('error', config('logging.log_level'));
        $this->assertSame('error', config('logging.channels.single.level'));
    }

    public function testNoRowLeavesEnvUnchanged()
    {
        config(['logging.log_level' => 'info', 'logging.channels.single.level' => 'info']);
        ServerSetting::applyAll();

        $this->assertSame('info', config('logging.log_level'));
        $this->assertSame('info', config('logging.channels.single.level'));
    }

    public function testDisallowedKeysRejected()
    {
        foreach (['app.key', 'database.default', 'logging.channels.slack.url', 'foo.password'] as $key) {
            try {
                ServerSetting::set($key, 'x');
                $this->fail("$key should have been rejected");
            } catch (BadRequestException $e) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('server_settings')->count());
    }

    public function testInvalidLevelRejected()
    {
        $this->expectException(BadRequestException::class);
        ServerSetting::set('log_level', 'loud');
    }

    public function testUnknownRowIgnoredAtBoot()
    {
        $key = config('app.key');
        config(['logging.log_level' => 'info']);
        DB::table('server_settings')->insert([
            ['name' => 'app.key', 'value' => 'hacked'],
            ['name' => 'log_level', 'value' => 'loud'],
        ]);
        Cache::forget(ServerSetting::CACHE_KEY);
        ServerSetting::applyAll();

        $this->assertSame($key, config('app.key'));
        $this->assertSame('info', config('logging.log_level'));
    }

    public function testBootWithoutTableDoesNotThrow()
    {
        Schema::rename('server_settings', 'server_settings_tmp');
        try {
            ServerSetting::applyAll();
            $this->assertTrue(true);
        } finally {
            Schema::rename('server_settings_tmp', 'server_settings');
        }
    }

    public function testApiGetPatchDelete()
    {
        $env = config('logging.log_level');
        $other = $env === 'error' ? 'debug' : 'error';

        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE);
        $row = Arr::first(Arr::get($rs->getContent(), static::$wrapper), fn($r) => $r['name'] === 'log_level');
        $this->assertSame('env', $row['source']);
        $this->assertContains('debug', $row['allowed_values']);

        $rs = $this->makeRequest(Verbs::PATCH, static::RESOURCE . '/log_level', [], ['value' => $other]);
        $this->assertSame('override', Arr::get($rs->getContent(), 'source'));
        $this->assertSame($other, config('logging.log_level'));

        $rs = $this->makeRequest(Verbs::DELETE, static::RESOURCE . '/log_level');
        $this->assertSame('env', Arr::get($rs->getContent(), 'source'));
        $this->assertSame($env, config('logging.log_level'));
    }

    public function testAuditWrittenAtStockLogLevel()
    {
        $file = storage_path('logs/dreamfactory.log');
        @unlink($file);
        // stock level: a plain notice would be filtered out
        config(['logging.log_level' => 'warning', 'logging.channels.single.level' => 'warning']);
        ServerSetting::reset();

        $this->makeRequest(Verbs::PATCH, static::RESOURCE . '/log_level', [], ['value' => 'debug']);

        $log = is_file($file) ? file_get_contents($file) : '';
        $this->assertStringContainsString('Server setting changed', $log);
        $this->assertStringContainsString('"from":"warning","to":"debug"', $log);
    }

    public function testInvalidPatchIsNotAudited()
    {
        $file = storage_path('logs/dreamfactory.log');
        @unlink($file);

        $this->makeRequest(Verbs::PATCH, static::RESOURCE . '/log_level', [], ['value' => 'loud']);

        $this->assertStringNotContainsString('Server setting changed', is_file($file) ? file_get_contents($file) : '');
    }

    /**
     * Admin accounts only. A role that grants the system service (or server_setting itself) to a
     * non-admin user must not be enough, even though the role check lets the request through.
     */
    public function testNonAdminUserWithRoleGrantForbidden()
    {
        $env = config('logging.log_level');
        Session::setUserInfo(['id' => 2, 'email' => 'user@test.com', 'is_sys_admin' => false]);
        Session::put('has_role', true);
        Session::put('role.services', [
            ['service' => 'system', 'component' => '*', 'verb_mask' => static::FULL_MASK],
            ['service' => 'system', 'component' => 'server_setting/*', 'verb_mask' => static::FULL_MASK],
        ]);
        // the role allows it, so AccessCheck would pass this request on to the resource
        $this->assertTrue(Session::checkServicePermission(Verbs::GET, 'system', static::RESOURCE));
        $this->assertTrue(Session::checkServicePermission(Verbs::PATCH, 'system', static::RESOURCE . '/log_level'));

        $this->assertAllForbidden();
        $this->assertSame(0, DB::table('server_settings')->count());
        $this->assertSame($env, config('logging.log_level'));
    }

    protected function assertAllForbidden()
    {
        $file = storage_path('logs/dreamfactory.log');
        @unlink($file);
        $calls = [
            [Verbs::GET, static::RESOURCE, null],
            [Verbs::GET, static::RESOURCE . '/log_level', null],
            [Verbs::PUT, static::RESOURCE . '/log_level', ['value' => 'debug']],
            [Verbs::PATCH, static::RESOURCE . '/log_level', ['value' => 'debug']],
            [Verbs::DELETE, static::RESOURCE . '/log_level', null],
        ];
        foreach ($calls as [$verb, $path, $payload]) {
            $rs = $this->makeRequest($verb, $path, [], $payload);
            $this->assertSame(403, $rs->getStatusCode(), "$verb $path should be forbidden");
            $this->assertArrayNotHasKey('resource', (array)$rs->getContent(), "$verb $path leaked settings");
            $this->assertArrayNotHasKey('value', (array)$rs->getContent(), "$verb $path leaked a value");
        }
        $this->assertStringNotContainsString('Server setting changed', is_file($file) ? file_get_contents($file) : '');
    }

    /** A non-string value is a bad request, not a server error. */
    public function testNonScalarValueRejected()
    {
        $rs = $this->makeRequest(Verbs::PATCH, static::RESOURCE . '/log_level', [], ['value' => ['debug']]);
        $this->assertSame(400, $rs->getStatusCode());
        $this->assertSame(0, DB::table('server_settings')->count());
    }

    public function testStderrChannelUntouched()
    {
        config(['logging.log_level' => 'debug', 'logging.channels.stderr.level' => 'debug', 'logging.channels.single.level' => 'debug']);
        ServerSetting::reset();
        ServerSetting::set('log_level', 'error');

        $this->assertSame('error', config('logging.channels.single.level'));
        $this->assertSame('debug', config('logging.channels.stderr.level'));
    }

    public function testNoOpResetNotAudited()
    {
        $file = storage_path('logs/dreamfactory.log');
        @unlink($file);

        $this->makeRequest(Verbs::DELETE, static::RESOURCE . '/log_level');

        $this->assertStringNotContainsString('Server setting changed', is_file($file) ? file_get_contents($file) : '');
    }

    /** @dataProvider rowsProvider */
    public function testWarmCacheBootMakesNoQuery($withRow)
    {
        if ($withRow) {
            DB::table('server_settings')->insert(['name' => 'log_level', 'value' => 'error']);
        }
        ServerSetting::applyAll();

        DB::enableQueryLog();
        DB::flushQueryLog();
        ServerSetting::applyAll();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame([], array_values(array_filter($queries, fn($q) => str_contains($q, 'server_settings'))));
    }

    public static function rowsProvider()
    {
        return ['with override' => [true], 'no rows' => [false]];
    }

    public function testWriteVisibleOnNextBoot()
    {
        $env = config('logging.log_level');
        ServerSetting::applyAll(); // warm the (empty) cache

        ServerSetting::set('log_level', 'error');
        ServerSetting::reset();
        config(['logging.log_level' => $env]);
        ServerSetting::applyAll();
        $this->assertSame('error', config('logging.log_level'));

        ServerSetting::clear('log_level');
        ServerSetting::reset();
        config(['logging.log_level' => $env]);
        ServerSetting::applyAll();
        $this->assertSame($env, config('logging.log_level'));
    }

    public function testCacheFailureStillApplies()
    {
        DB::table('server_settings')->insert(['name' => 'log_level', 'value' => 'error']);
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('forget')->andReturn(true);

        ServerSetting::applyAll();

        $this->assertSame('error', config('logging.log_level'));
    }
}
