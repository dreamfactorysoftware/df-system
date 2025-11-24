<?php

namespace DreamFactory\Core\System\Resources;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Snowflake Marketplace Usage Resource
 *
 * Provides usage statistics for the Snowflake Marketplace free edition
 * ALWAYS ACTIVE - designed for dedicated Snowflake Marketplace builds
 */
class SnowflakeMarketplace extends ReadOnlySystemResource
{
    /**
     * Default daily request limit for Snowflake Marketplace free edition
     */
    const DEFAULT_DAILY_LIMIT = 50;

    /**
     * Handle GET requests for usage information
     *
     * @return array
     */
    protected function handleGET()
    {
        // Handle /system/snowflake-marketplace/usage endpoint
        if ($this->resource === 'usage') {
            return $this->getUsageStats();
        }

        // Default response - list available resources
        return [
            'resource' => [
                ['name' => 'usage', 'label' => 'Usage Statistics', 'description' => 'Get current Snowflake API usage statistics']
            ]
        ];
    }

    /**
     * Get current usage statistics
     *
     * @return array
     */
    protected function getUsageStats()
    {
        $today = Carbon::now()->toDateString();
        $limit = (int) env('SNOWFLAKE_DAILY_REQUEST_LIMIT', self::DEFAULT_DAILY_LIMIT);

        $usage = DB::table('snowflake_marketplace_usage')
            ->where('usage_date', $today)
            ->first();

        if (!$usage) {
            return [
                'limit' => $limit,
                'used' => 0,
                'remaining' => $limit,
                'reset_at' => Carbon::now()->endOfDay()->toIso8601String(),
                'edition' => 'snowflake-marketplace-free'
            ];
        }

        return [
            'limit' => $limit,
            'used' => $usage->request_count,
            'remaining' => max(0, $limit - $usage->request_count),
            'reset_at' => $usage->reset_at,
            'tampered' => $usage->tampered ?? false,
            'edition' => 'snowflake-marketplace-free'
        ];
    }

}
