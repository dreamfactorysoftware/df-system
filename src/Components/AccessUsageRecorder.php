<?php

namespace DreamFactory\Core\System\Components;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Writes the access_usage table: when each app (API key), role and user was
 * last used or denied.
 *
 * Writes go through the query builder on purpose. Saving the App or Role
 * models fires their saved hooks, which flush the app:/role: caches (and, for
 * an inactive app, invalidate its JWTs) on every request.
 */
class AccessUsageRecorder
{
    const TABLE = 'access_usage';
    const LEDGER_TABLE = 'agent_activity_ledger';

    const SUBJECT_APP = 'app';
    const SUBJECT_ROLE = 'role';
    const SUBJECT_USER = 'user';

    const SUBJECTS = [self::SUBJECT_APP, self::SUBJECT_ROLE, self::SUBJECT_USER];

    /**
     * Record one request outcome for every subject it can be attributed to.
     *
     * @param array<string, int|string|null> $subjects [subject_type => id]
     * @param bool                           $denied   the request was rejected with 401/403
     * @param string|null                    $service  service name from the route
     * @param int                            $status   final HTTP status
     */
    public static function record(array $subjects, bool $denied, ?string $service, int $status): void
    {
        $ttl = (int)config('df-access-usage.throttle_seconds', 300);
        $outcome = $denied ? 'denied' : 'used';
        $column = $denied ? 'last_denied_at' : 'last_used_at';
        $now = Carbon::now()->format('Y-m-d H:i:s');

        foreach ($subjects as $type => $id) {
            if (empty($id) || !in_array($type, static::SUBJECTS, true)) {
                continue;
            }
            // At most one write per subject and outcome per window. Cache::add is
            // atomic on redis/memcached/database stores; on the file store a race
            // costs one extra write, nothing more.
            if ($ttl > 0 && !Cache::add("access_usage:{$type}:{$id}:{$outcome}", 1, $ttl)) {
                continue;
            }

            // last_service / last_status describe the last *use*. A denial only moves
            // last_denied_at, so probing a forbidden endpoint doesn't hide where the
            // credential is really used.
            $values = ['subject_type' => $type, 'subject_id' => (int)$id, 'first_seen_at' => $now, $column => $now];
            $update = [$column];
            if (!$denied) {
                $values['last_service'] = ($service === null) ? null : mb_substr($service, 0, 128);
                $values['last_status'] = $status;
                $update[] = 'last_service';
                $update[] = 'last_status';
            }

            DB::table(static::TABLE)->upsert([$values], ['subject_type', 'subject_id'], $update);
        }
    }

    /**
     * Seed last_used_at / first_seen_at from df-agents' agent_activity_ledger,
     * which records every data-plane request on Silver+ installs. Safe to re-run:
     * it only ever moves last_used_at later and first_seen_at earlier.
     *
     * @return int subjects inserted or updated
     */
    public static function backfillFromLedger(): int
    {
        if (!Schema::hasTable(static::LEDGER_TABLE) || !Schema::hasTable(static::TABLE)) {
            return 0;
        }

        $changed = 0;
        foreach ([static::SUBJECT_APP => 'app_id', static::SUBJECT_ROLE => 'role_id', static::SUBJECT_USER => 'user_id'] as $type => $column) {
            $history = DB::table(static::LEDGER_TABLE)
                ->whereNotNull($column)
                ->groupBy($column)
                ->select($column . ' as subject_id')
                ->selectRaw('min(occurred_at) as first_seen, max(occurred_at) as last_used')
                ->get();

            $existing = DB::table(static::TABLE)->where('subject_type', $type)->get()->keyBy('subject_id');

            foreach ($history as $row) {
                $id = (int)$row->subject_id;
                $firstSeen = Carbon::parse($row->first_seen)->format('Y-m-d H:i:s');
                $lastUsed = Carbon::parse($row->last_used)->format('Y-m-d H:i:s');

                if (null === ($current = $existing->get($id))) {
                    DB::table(static::TABLE)->insert([
                        'subject_type'  => $type,
                        'subject_id'    => $id,
                        'first_seen_at' => $firstSeen,
                        'last_used_at'  => $lastUsed,
                    ]);
                    $changed++;
                    continue;
                }

                $update = [];
                if (empty($current->last_used_at) || Carbon::parse($current->last_used_at)->lt($lastUsed)) {
                    $update['last_used_at'] = $lastUsed;
                }
                if (empty($current->first_seen_at) || Carbon::parse($current->first_seen_at)->gt($firstSeen)) {
                    $update['first_seen_at'] = $firstSeen;
                }
                if ($update) {
                    DB::table(static::TABLE)->where('id', $current->id)->update($update);
                    $changed++;
                }
            }
        }

        return $changed;
    }
}
