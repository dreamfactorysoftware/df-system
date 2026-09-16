<?php

namespace DreamFactory\Core\System\Http\Middleware;

use Closure;
use DreamFactory\Core\Http\Middleware\AuthCheck;
use DreamFactory\Core\Models\App;
use DreamFactory\Core\System\Components\AccessUsageRecorder;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Records when each app (API key), role and user was last used or denied.
 *
 * Prepended to the df.api group so it wraps auth_check and access_check:
 * requests rejected there with 401/403 never reach middleware pushed after
 * access_check (df-agents' ledger, df-limits), but they do return through
 * here. The write happens in terminate(), after the response is sent.
 */
class RecordAccessUsage
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        if (!config('df-access-usage.enabled', true)) {
            return;
        }

        try {
            $status = (is_object($response) && method_exists($response, 'getStatusCode'))
                ? (int)$response->getStatusCode()
                : 0;
            $denied = in_array($status, [401, 403], true);

            $appId = Session::get('app.id');
            if (empty($appId) && $denied) {
                // An inactive app's key is rejected inside auth_check ("App is not
                // active") before the session is populated. Resolve the key directly
                // so disabled keys that clients still send show up in the audit.
                $appId = App::getAppIdByApiKey(AuthCheck::getApiKey($request));
            }

            $subjects = [
                AccessUsageRecorder::SUBJECT_APP  => $appId,
                AccessUsageRecorder::SUBJECT_ROLE => Session::getRoleId(),
                AccessUsageRecorder::SUBJECT_USER => Session::getCurrentUserId(),
            ];
            if (!array_filter($subjects)) {
                return;
            }

            $service = $request->route('service');

            AccessUsageRecorder::record(
                $subjects,
                $denied,
                is_string($service) ? strtolower($service) : null,
                $status
            );
        } catch (\Throwable $e) {
            // Usage tracking must never affect the request path.
            Log::warning('access_usage write failed: ' . $e->getMessage());
        }
    }
}
