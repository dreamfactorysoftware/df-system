<?php

namespace DreamFactory\Core\System;

use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Models\Config;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use DreamFactory\Core\System\Commands\BackfillAccessUsage;
use DreamFactory\Core\System\Components\SystemResourceManager;
use DreamFactory\Core\System\Facades\SystemResourceManager as SystemResourceManagerFacade;
use DreamFactory\Core\System\Http\Middleware\RecordAccessUsage;
use DreamFactory\Core\System\Services\System;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Route;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     */
    public function boot()
    {
        // add migrations, https://laravel.com/docs/5.4/packages#resources
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->app->alias('df.system.resource', SystemResourceManager::class);
        // DreamFactory Specific Facades...
        $loader = AliasLoader::getInstance();
        $loader->alias('SystemResourceManager', SystemResourceManagerFacade::class);

        // Access-usage tracking. PREPENDED so it wraps auth_check and access_check
        // and sees their 401/403 responses. This relies on df-core having already
        // built df.api in its own boot(): providers boot in package-name order, so
        // df-core runs before df-system. Were that to flip, df-core would merge this
        // entry after access_check and denied requests would go unrecorded.
        Route::aliasMiddleware('df.access_usage', RecordAccessUsage::class);
        Route::prependMiddlewareToGroup('df.api', 'df.access_usage');

        if ($this->app->runningInConsole()) {
            $this->commands([BackfillAccessUsage::class]);
        }
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/access_usage.php', 'df-access-usage');

        // The system resource manager is used to resolve various system resource types.
        // It also implements the resolver interface which may be used by other components adding system resource types.
        $this->app->singleton('df.system.resource', function ($app) {
            return new SystemResourceManager($app);
        });

        $this->app->resolving('df.service', function (ServiceManager $df) {
            // Add the system service
            $df->addType(new ServiceType([
                    'name'              => 'system',
                    'label'             => 'System Management',
                    'description'       => 'Service supporting management of the system.',
                    'group'             => ServiceTypeGroups::SYSTEM,
                    'singleton'         => true,
                    'config_handler'    => Config::class,
                    'factory'           => function ($config) {
                        return new System($config);
                    },
                    'access_exceptions' => [
                        [
                            'verb_mask' => 31, //Allow all verbs
                            'resource'  => 'admin/session',
                        ],
                        [
                            'verb_mask' => 2, //Allow POST only
                            'resource'  => 'admin/password',
                        ],
                        [
                            'verb_mask' => 1,
                            'resource'  => 'environment',
                        ]
                    ],
                ]
            ));
        });
    }
}
