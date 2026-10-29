<?php

namespace DreamFactory\Core\System\Models;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Facades\DB;

/**
 * DB-backed overrides for an allow-listed set of config values.
 * Anything not in ALLOWED is rejected on write and ignored on apply.
 */
class ServerSetting
{
    const TABLE = 'server_settings';

    const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    const ALLOWED = [
        'log_level' => ['config' => 'logging.log_level', 'label' => 'Log level', 'values' => self::LEVELS],
    ];

    /** @var bool set while config:cache / optimize run, so overrides never reach the cache file */
    public static $suspended = false;

    /** @var string|null the .env log level, captured before any override is applied */
    protected static $baseline = null;

    /** @var array channels whose level followed the baseline, captured with it */
    protected static $channels = [];

    /** Forget the captured baseline (tests). */
    public static function reset(): void
    {
        static::$baseline = null;
        static::$channels = [];
    }

    protected static function baseline(): string
    {
        if (static::$baseline === null) {
            static::$baseline = (string)config('logging.log_level');
            foreach ((array)config('logging.channels') as $name => $channel) {
                if (is_array($channel) && ($channel['level'] ?? null) === static::$baseline) {
                    static::$channels[] = $name;
                }
            }
        }

        return static::$baseline;
    }

    protected static function validate(string $name, $value): string
    {
        if (!isset(static::ALLOWED[$name])) {
            throw new BadRequestException("Server setting '$name' is not overridable.");
        }
        $value = strtolower(trim((string)$value));
        if (!in_array($value, static::ALLOWED[$name]['values'], true)) {
            throw new BadRequestException(
                "Invalid value for '$name'. Use one of: " . implode(', ', static::ALLOWED[$name]['values']) . '.'
            );
        }

        return $value;
    }

    public static function set(string $name, $value): array
    {
        $value = static::validate($name, $value);
        static::baseline();
        $userId = Session::getCurrentUserId();
        $now = date('Y-m-d H:i:s');
        if (DB::table(static::TABLE)->where('name', $name)->exists()) {
            DB::table(static::TABLE)->where('name', $name)
                ->update(['value' => $value, 'last_modified_date' => $now, 'last_modified_by_id' => $userId]);
        } else {
            DB::table(static::TABLE)->insert([
                'name' => $name, 'value' => $value, 'created_date' => $now, 'last_modified_date' => $now,
                'created_by_id' => $userId, 'last_modified_by_id' => $userId,
            ]);
        }
        static::applyAll();

        return static::row($name);
    }

    public static function clear(string $name): array
    {
        if (!isset(static::ALLOWED[$name])) {
            throw new BadRequestException("Server setting '$name' is not overridable.");
        }
        DB::table(static::TABLE)->where('name', $name)->delete();
        static::applyLevel(static::baseline());

        return static::row($name);
    }

    /** Apply every stored override on top of config. Silent if the table or DB is unavailable. */
    public static function applyAll(): void
    {
        try {
            $rows = DB::table(static::TABLE)->pluck('value', 'name')->all();
        } catch (\Throwable $e) {
            return;
        }
        static::baseline();
        $level = $rows['log_level'] ?? null;
        if ($level !== null && in_array($level, static::LEVELS, true)) {
            static::applyLevel($level);
        }
    }

    protected static function applyLevel(string $level): void
    {
        config(['logging.log_level' => $level]);
        foreach (static::$channels as $ch) {
            config(["logging.channels.$ch.level" => $level]);
            // a channel resolved before we booted would keep its old level
            app('log')->forgetChannel($ch);
        }
    }

    public static function row(string $name): array
    {
        $stored = null;
        try {
            $stored = DB::table(static::TABLE)->where('name', $name)->value('value');
        } catch (\Throwable $e) {
            // table not migrated yet: report the .env value
        }
        $valid = $stored !== null && in_array($stored, static::ALLOWED[$name]['values'], true);

        return [
            'name'           => $name,
            'label'          => static::ALLOWED[$name]['label'],
            'value'          => $valid ? $stored : static::baseline(),
            'env_value'      => static::baseline(),
            'source'         => $valid ? 'override' : 'env',
            'allowed_values' => static::ALLOWED[$name]['values'],
        ];
    }

    public static function rows(): array
    {
        return array_map([static::class, 'row'], array_keys(static::ALLOWED));
    }
}
