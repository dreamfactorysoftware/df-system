<?php

use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\ForbiddenException;
use DreamFactory\Core\System\Models\ServerSetting;
use DreamFactory\Core\Utility\Session;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    public function testConfigCacheDoesNotBakeOverride()
    {
        config(['logging.log_level' => 'info']);
        DB::table('server_settings')->insert(['name' => 'log_level', 'value' => 'error']);
        Cache::forget(ServerSetting::CACHE_KEY);

        Event::dispatch(new CommandStarting('config:cache', new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput()));
        $this->assertTrue(ServerSetting::$suspended);
        // the provider's boot path: skipped while suspended
        if (!ServerSetting::$suspended) {
            ServerSetting::applyAll();
        }
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

    protected function seedRestrictedAdmin(array $services)
    {
        Session::setUserInfo(['id' => 1, 'email' => 'admin@test.com', 'is_sys_admin' => true]);
        Session::put('has_role', true);
        Session::put('role.services', $services);
    }

    public function testRoleWithServerSettingAccessAllowed()
    {
        $this->seedRestrictedAdmin([
            ['service' => 'system', 'component' => 'server_setting/*', 'verb_mask' => static::FULL_MASK],
        ]);
        $this->assertTrue(Session::checkServicePermission(Verbs::GET, 'system', static::RESOURCE));
        $this->assertTrue(Session::checkServicePermission(Verbs::PATCH, 'system', static::RESOURCE . '/log_level'));
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE);
        $this->assertSame(200, $rs->getStatusCode());

        $rs = $this->makeRequest(Verbs::PATCH, static::RESOURCE . '/log_level', [], ['value' => 'error']);
        $this->assertSame(200, $rs->getStatusCode());
        $this->assertSame('override', Arr::get($rs->getContent(), 'source'));
    }

    public function testRoleWithoutServerSettingAccessForbidden()
    {
        $this->seedRestrictedAdmin([
            ['service' => 'system', 'component' => 'cache/*', 'verb_mask' => static::FULL_MASK],
        ]);
        // makeRequest bypasses the AccessCheck middleware, which is what enforces the role in production
        // by calling this exact check with the request's service and resource path. ForbiddenException is a 403.
        foreach ([[Verbs::GET, static::RESOURCE], [Verbs::PATCH, static::RESOURCE . '/log_level']] as [$verb, $path]) {
            try {
                Session::checkServicePermission($verb, 'system', $path);
                $this->fail("$verb $path should be forbidden");
            } catch (ForbiddenException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
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
        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('forget')->andReturn(true);

        ServerSetting::applyAll();

        $this->assertSame('error', config('logging.log_level'));
    }
}
