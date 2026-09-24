<?php

namespace DreamFactory\Core\System\Components;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the system/access_usage report: every app, role or user joined to its
 * access_usage row, plus audit flags. Never returns API keys.
 */
class AccessUsageReport
{
    const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Every place a role can be assigned from, as [table, column]. Tables that
     * only exist with some packages (SSO, LDAP, agents, AI) are skipped when
     * absent. Lookups and limits hang off a role but don't assign it, so they
     * don't count.
     */
    const ROLE_ASSIGNMENTS = [
        ['app', 'role_id'],
        ['user_to_app_to_role', 'role_id'],
        ['app_role_map', 'role_id'],
        ['ldap_config', 'default_role'],
        ['azure_ad_config', 'default_role'],
        ['oauth_config', 'default_role'],
        ['oidc_config', 'default_role'],
        ['saml_config', 'default_role'],
        ['role_adldap', 'role_id'],
        ['role_azure_ad', 'role_id'],
        ['role_oidc', 'role_id'],
        ['role_google', 'role_id'],
        ['user_config', 'open_reg_role_id'],
        ['agents', 'role_id'],
        ['ai_chat_config', 'ai_role_id'],
    ];

    /**
     * @param string $subject          app | role | user
     * @param int    $staleDays        last use older than this counts as stale
     * @param bool   $includeNeverUsed include subjects with no recorded use
     *
     * @return array{resource: array, meta: array}
     */
    public static function build(string $subject, int $staleDays = 90, bool $includeNeverUsed = true): array
    {
        $now = Carbon::now();
        $cutoff = $now->copy()->subDays($staleDays);

        $usage = DB::table(AccessUsageRecorder::TABLE)->where('subject_type', $subject)->get()->keyBy('subject_id');
        $ledgerAvailable = Schema::hasTable(AccessUsageRecorder::LEDGER_TABLE);
        $ledger = $ledgerAvailable ? static::ledgerStats($subject, $now->copy()->subDays(30)) : [];
        $referenced = (AccessUsageRecorder::SUBJECT_ROLE === $subject) ? static::referencedRoleIds() : [];

        $rows = [];
        foreach (static::subjects($subject) as $entity) {
            $id = (int)$entity->id;
            $use = $usage->get($id);
            $lastUsed = static::date($use->last_used_at ?? null);
            $lastDenied = static::date($use->last_denied_at ?? null);
            $isActive = (bool)$entity->is_active;

            if (!$includeNeverUsed && null === $lastUsed) {
                continue;
            }

            $rows[] = [
                'subject_type'           => $subject,
                'subject_id'             => $id,
                'name'                   => $entity->name,
                'is_active'              => $isActive,
                'last_used_at'           => $lastUsed,
                'last_denied_at'         => $lastDenied,
                'last_service'           => $use->last_service ?? null,
                'last_status'            => isset($use->last_status) ? (int)$use->last_status : null,
                'never_used'             => null === $lastUsed,
                'stale'                  => null !== $lastUsed && Carbon::parse($lastUsed)->lt($cutoff),
                // An inactive key or role that clients are still sending.
                'disabled_but_attempted' => !$isActive && null !== $lastDenied && Carbon::parse($lastDenied)->gte($cutoff),
                'role_unreferenced'      => (AccessUsageRecorder::SUBJECT_ROLE === $subject) ? !isset($referenced[$id]) : null,
                'last_login_date'        => (AccessUsageRecorder::SUBJECT_USER === $subject) ? static::date($entity->last_login_date) : null,
                'is_sys_admin'           => (AccessUsageRecorder::SUBJECT_USER === $subject) ? (bool)$entity->is_sys_admin : null,
                'requests_30d'           => $ledgerAvailable ? ($ledger[$id]['requests_30d'] ?? 0) : null,
                'top_services'           => $ledgerAvailable ? ($ledger[$id]['top_services'] ?? []) : null,
            ];
        }

        return [
            'resource' => $rows,
            'meta'     => [
                'subject'             => $subject,
                'stale_days'          => $staleDays,
                'generated_at'        => $now->format(static::DATE_FORMAT),
                'ledger_available'    => $ledgerAvailable,
                // Earliest recorded activity of any kind. "never_used" only means
                // "not seen since then".
                'tracking_started_at' => static::date(DB::table(AccessUsageRecorder::TABLE)->min('first_seen_at')),
            ],
        ];
    }

    protected static function subjects(string $subject)
    {
        switch ($subject) {
            case AccessUsageRecorder::SUBJECT_ROLE:
                return DB::table('role')->orderBy('name')->get(['id', 'name', 'is_active']);
            case AccessUsageRecorder::SUBJECT_USER:
                return DB::table('user')->orderBy('email')
                    ->get(['id', 'email as name', 'is_active', 'last_login_date', 'is_sys_admin']);
            default:
                return DB::table('app')->orderBy('name')->get(['id', 'name', 'is_active']);
        }
    }

    /**
     * Requests per subject over the window, from agent_activity_ledger.
     *
     * @return array<int, array{requests_30d: int, top_services: array}>
     */
    protected static function ledgerStats(string $subject, Carbon $since): array
    {
        $column = [
            AccessUsageRecorder::SUBJECT_APP  => 'app_id',
            AccessUsageRecorder::SUBJECT_ROLE => 'role_id',
            AccessUsageRecorder::SUBJECT_USER => 'user_id',
        ][$subject];

        $rows = DB::table(AccessUsageRecorder::LEDGER_TABLE)
            ->where('occurred_at', '>=', $since->format(static::DATE_FORMAT))
            ->whereNotNull($column)
            ->groupBy($column, 'service_name')
            ->select($column . ' as subject_id', 'service_name')
            ->selectRaw('count(*) as requests')
            ->get();

        $services = [];
        foreach ($rows as $row) {
            $services[(int)$row->subject_id][$row->service_name] = (int)$row->requests;
        }

        $stats = [];
        foreach ($services as $id => $counts) {
            arsort($counts);
            $top = [];
            foreach (array_slice($counts, 0, 3, true) as $service => $requests) {
                $top[] = ['service' => $service, 'requests' => $requests];
            }
            $stats[$id] = ['requests_30d' => array_sum($counts), 'top_services' => $top];
        }

        return $stats;
    }

    /**
     * @return array<int, true> role ids assigned anywhere
     */
    protected static function referencedRoleIds(): array
    {
        $ids = [];
        foreach (static::ROLE_ASSIGNMENTS as [$table, $column]) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
                continue;
            }
            foreach (DB::table($table)->whereNotNull($column)->distinct()->pluck($column) as $id) {
                $ids[(int)$id] = true;
            }
        }

        // AI Connection "Allowed Roles" is a JSON array in a text column.
        if (Schema::hasTable('ai_connection_config') && Schema::hasColumn('ai_connection_config', 'allowed_roles')) {
            foreach (DB::table('ai_connection_config')->whereNotNull('allowed_roles')->pluck('allowed_roles') as $json) {
                foreach ((array)json_decode((string)$json, true) as $id) {
                    if (is_numeric($id)) {
                        $ids[(int)$id] = true;
                    }
                }
            }
        }

        return $ids;
    }

    protected static function date($value): ?string
    {
        return empty($value) ? null : Carbon::parse($value)->format(static::DATE_FORMAT);
    }
}
