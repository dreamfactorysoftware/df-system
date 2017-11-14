<?php

namespace DreamFactory\Core;

use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Models\Config;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use DreamFactory\Core\System\Components\SystemResourceManager;
use DreamFactory\Core\System\Services\System;
use GraphQL;

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

        GraphQL::addSchema('system', [
            'query'    => [
                'apps'          => 'DreamFactory\Core\GraphQL\Query\Apps',
                'services'      => 'DreamFactory\Core\GraphQL\Query\Services',
                'service_types' => 'DreamFactory\Core\GraphQL\Query\ServiceTypes',
                'users'         => 'DreamFactory\Core\GraphQL\Query\Users',
            ],
            'mutation' => [

            ],
            'type' => [
                'App'         => 'DreamFactory\Core\GraphQL\Type\App',
                'Service'     => 'DreamFactory\Core\GraphQL\Type\Service',
                'ServiceType' => 'DreamFactory\Core\GraphQL\Type\ServiceType',
                'User'        => 'DreamFactory\Core\GraphQL\Type\User',
            ],
        ]);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
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
