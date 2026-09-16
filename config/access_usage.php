<?php

return [
    // Record when each app (API key), role and user was last used or denied (access_usage table).
    'enabled'          => env('DF_ACCESS_USAGE_ENABLED', true),
    // At most one write per subject and outcome per window. 0 writes on every request.
    'throttle_seconds' => (int)env('DF_ACCESS_USAGE_THROTTLE_SECONDS', 300),
];
