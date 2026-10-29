<?php

use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\System\Models\ServerSetting;
use DreamFactory\Core\Utility\Session;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class ServerSettingsTest extends \DreamFactory\Core\Testing\TestCase
{
    const RESOURCE = 'server_setting';

    protected $serviceId = 'system';

    public function setUp(): void
    {
        parent::setUp();
        // no JWT secret in the stock test container, so seed the admin session directly
        Session::setUserInfo(['id' => 1, 'email' => 'admin@test.com', 'is_sys_admin' => true]);
        DB::table('server_settings')->delete();
        ServerSetting::reset();
    }

    public function tearDown(): void
    {
        ServerSetting::$suspended = false;
        if (Schema::hasTable('server_settings')) {
            DB::table('server_settings')->delete();
        }
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
        ServerSetting::applyAll();

        $this->assertSame($key, config('app.key'));
        $this->assertSame('info', config('logging.log_level'));
    }

    public function testConfigCacheDoesNotBakeOverride()
    {
        config(['logging.log_level' => 'info']);
        DB::table('server_settings')->insert(['name' => 'log_level', 'value' => 'error']);

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

    public function testApiNonAdminDenied()
    {
        // a non-admin whose role could still reach system/*
        session()->put('user.is_sys_admin', false);
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE);
        $this->assertContains($rs->getStatusCode(), [401, 403]);
    }
}
