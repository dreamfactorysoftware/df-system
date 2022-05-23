<?php

namespace DreamFactory\Core\System\Resources;

use DreamFactory\Core\Enums\AppTypes;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Models\App as AppModel;
use DreamFactory\Core\Models\Config as SystemConfig;
use DreamFactory\Core\Models\Service as ServiceModel;
use DreamFactory\Core\Models\UserAppRole;
use DreamFactory\Core\Utility\Environment as EnvUtilities;
use DreamFactory\Core\Utility\Session as SessionUtilities;
use ServiceManager;

class Environment extends BaseSystemResource
{
    /**
     * @return array
     */
    protected function handleGET()
    {
        $result = [];

        // Required when no authentication is provided
        $result['authentication'] = static::getLoginApi(); // auth options
        $result['apps'] = (array)static::getApps(); // app options

        // authenticated in some way or default app role, show the following
        if (SessionUtilities::isAuthenticated() || SessionUtilities::getRoleId()) {
            $result['platform'] = [
                'version'                => \Config::get('app.version'),
                'bitnami_demo'           => EnvUtilities::isDemoApplication(),
                'is_hosted'              => to_bool(env('DF_MANAGED', false)),
                'is_trial'               => to_bool(env('DF_IS_TRIAL', false)),
                'license'                => EnvUtilities::getLicenseLevel(),
                'secured_package_export' => EnvUtilities::isZipInstalled(),
                'license_key'            => \Config::get('app.license_key')
//                'aws_product_code'       => EnvUtilities::getProductCode(),
//                'aws_instance_id'        => EnvUtilities::getInstanceId(),
//                'df_instance_id'         => EnvUtilities::getDreamFactoryInstanceId()
            ];

            // including information that helps users use the API or debug
            $result['server'] = [
                'server_os' => strtolower(php_uname('s')),
                'release'   => php_uname('r'),
                'version'   => php_uname('v'),
                'host'      => php_uname('n'),
                'machine'   => php_uname('m')
            ];

            $result['client'] = [
                "user_agent" => \Request::header('User-Agent'),
                "ip_address" => \Request::getClientIp(),
                "locale"     => \Request::getLocale()
            ];

            /*
             * Most API calls return a resource array or a single resource,
             * If an array, shall we wrap it?, With what shall we wrap it?
             */
            $result['config'] = [
                'always_wrap_resources' => \Config::get('df.always_wrap_resources'),
                'resources_wrapper'     => \Config::get('df.resources_wrapper'),
                'db'                    => [
                    /** The default number of records to return at once for database queries */
                    'max_records_returned' => \Config::get('database.max_records_returned'),
                    'time_format'          => \Config::get('df.db.time_format'),
                    'date_format'          => \Config::get('df.db.date_format'),
                    'datetime_format'      => \Config::get('df.db.datetime_format'),
                    'timestamp_format'     => \Config::get('df.db.timestamp_format'),
                ],
            ];

            if (SessionUtilities::isSysAdmin()) {
                // administrator-only information
                $dbDriver = \Config::get('database.default');
                $result['platform']['db_driver'] = $dbDriver;
                if ($dbDriver === 'sqlite') {
                    $result['platform']['sqlite_storage'] = \Config::get('df.db.sqlite_storage');
                }
                $result['platform']['install_path'] = base_path() . DIRECTORY_SEPARATOR;
                $result['platform']['log_path'] = env('DF_MANAGED_LOG_PATH',
                        storage_path('logs')) . DIRECTORY_SEPARATOR;
                $result['platform']['app_debug'] = env('APP_DEBUG', false);
                $result['platform']['log_mode'] = \Config::get('logging.log');
                $result['platform']['log_level'] = \Config::get('logging.log_level');
                $result['platform']['cache_driver'] = \Config::get('cache.default');

                if ($result['platform']['cache_driver'] === 'file') {
                    $result['platform']['cache_path'] = \Config::get('cache.stores.file.path') . DIRECTORY_SEPARATOR;
                }

                $packages = EnvUtilities::getInstalledPackagesInfo();
                $result['platform']['packages'] = $packages;

                $result['php'] = EnvUtilities::getPhpInfo();
                // Remove environment variables being kicked back to the client
                unset($result['php']['environment']);
                unset($result['php']['php_variables']);
            }
        }

        return $result;
    }

    protected static function getApps()
    {
        if (SessionUtilities::isAuthenticated()) {
            $user = SessionUtilities::user();
            $defaultAppId = $user->default_app_id;

            if (SessionUtilities::isSysAdmin()) {
                $apps = AppModel::whereIsActive(1)->whereNotIn('type', [AppTypes::NONE])->get();
            } else {
                $userId = $user->id;
                $userAppRoles = UserAppRole::whereUserId($userId)->whereNotNull('role_id')->get(['app_id']);
                $appIds = [];
                foreach ($userAppRoles as $uar) {
                    $appIds[] = $uar->app_id;
                }
                $appIdsString = implode(',', $appIds);
                $appIdsString = (empty($appIdsString)) ? '-1' : $appIdsString;
                $typeString = implode(',', [AppTypes::NONE]);
                $typeString = (empty($typeString)) ? '-1' : $typeString;

                $apps =
                    AppModel::whereIsActive(1)->whereRaw("(app.id IN ($appIdsString) OR role_id > 0) AND type NOT IN ($typeString)")
                        ->get();
            }
        } else {
            $apps = AppModel::whereIsActive(1)
                ->where('role_id', '>', 0)
                ->whereNotIn('type', [AppTypes::NONE])
                ->get();
        }

        if (empty($defaultAppId)) {
            $systemConfig = SystemConfig::first(['default_app_id']);
            $defaultAppId = (!empty($systemConfig)) ? $systemConfig->default_app_id : null;
        }

        $out = [];
        /** @type AppModel $app */
        foreach ($apps as $app) {
            $out[] = static::makeAppInfo($app->toArray(), $defaultAppId);
        }

        return $out;
    }

    protected static function makeAppInfo(array $app, $defaultAppId)
    {
        return [
            'id'                      => $app['id'],
            'name'                    => $app['name'],
            'description'             => $app['description'],
            'url'                     => $app['launch_url'],
            'is_default'              => ($defaultAppId === $app['id']) ? true : false,
            'allow_fullscreen_toggle' => $app['allow_fullscreen_toggle'],
            'requires_fullscreen'     => $app['requires_fullscreen'],
            'toggle_location'         => $app['toggle_location'],
        ];
    }

    /**
     * @return array
     */
    protected static function getLoginApi()
    {
        $adminApi = [
            'path'    => 'system/admin/session',
            'verb'    => Verbs::POST,
            'payload' => [
                'email'       => 'string',
                'password'    => 'string',
                'remember_me' => 'bool',
            ],
        ];
        $userApi = [
            'path'    => 'user/session',
            'verb'    => Verbs::POST,
            'payload' => [
                'email'       => 'string',
                'password'    => 'string',
                'remember_me' => 'bool',
            ],
        ];

        if (class_exists('\DreamFactory\Core\User\Services\User')) {
            $oauth = static::getOAuthServices();
            $ldap = static::getAdLdapServices();
            $saml = static::getSamlServices();

            /** @var \DreamFactory\Core\User\Services\User $userService */
            $userService = ServiceManager::getService('user');

            return [
                'admin'                     => $adminApi,
                'user'                      => $userApi,
                'oauth'                     => $oauth,
                'adldap'                    => $ldap,
                'saml'                      => $saml,
                'allow_open_registration'   => $userService->allowOpenRegistration,
                'open_reg_email_service_id' => $userService->openRegEmailServiceId,
                'allow_forever_sessions'    => config('df.allow_forever_sessions', false),
                'login_attribute'           => strtolower(config('df.login_attribute', 'email'))
            ];
        }

        return [
            'admin'                     => $adminApi,
            'allow_open_registration'   => false,
            'open_reg_email_service_id' => null,
        ];
    }

    /**
     * @return array
     */
    protected static function getOAuthServices()
    {
        $types = ServiceManager::getServiceTypeNames(ServiceTypeGroups::OAUTH);

        /** @var ServiceModel[] $oauth */
        /** @noinspection PhpUndefinedMethodInspection */
        $oauth = ServiceModel::whereIn('type', $types)->whereIsActive(1)->get(['id', 'name', 'type', 'label']);

        $services = [];
        foreach ($oauth as $o) {
            $config = ($o->getConfigAttribute()) ?: [];
            $services[] = [
                'path'       => 'user/session?service=' . strtolower($o->name),
                'name'       => $o->name,
                'label'      => $o->label,
                'verb'       => [Verbs::GET, Verbs::POST],
                'type'       => $o->type,
                'icon_class' => array_get($config, 'icon_class'),
            ];
        }

        return $services;
    }

    protected static function getSamlServices()
    {
        $samls = ServiceModel::whereType('saml')->whereIsActive(1)->get(['id', 'name', 'type', 'label']);

        $services = [];
        foreach ($samls as $saml) {
            $config = ($saml->getConfigAttribute()) ?: [];
            $services[] = [
                'path'       => $saml->name . '/sso',
                'name'       => $saml->name,
                'label'      => $saml->label,
                'verb'       => Verbs::GET,
                'type'       => 'saml',
                'icon_class' => array_get($config, 'icon_class'),
            ];
        }

        return $services;
    }

    /**
     * @return array
     */
    protected static function getAdLdapServices()
    {
        $result = ServiceManager::getServiceListByGroup(ServiceTypeGroups::LDAP, ['name', 'type', 'label'], true);

        $services = [];
        foreach ($result as $l) {
            $name = array_get($l, 'name');
            $services[] = [
                'path'    => 'user/session?service=' . strtolower($name),
                'name'    => $name,
                'label'   => array_get($l, 'label'),
                'verb'    => Verbs::POST,
                'payload' => [
                    'username'    => 'string',
                    'password'    => 'string',
                    'service'     => $name,
                    'remember_me' => 'bool',
                ],
            ];
        }

        return $services;
    }

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);
        $resourceName = strtolower($this->name);

        return [
            '/' . $resourceName => [
                'get' => [
                    'summary'     => 'Retrieve system environment.',
                    'description' =>
                        'Minimum environment information given without a valid user session.' .
                        ' More information given based on user privileges.',
                    'operationId' => 'get' . $capitalized . 'Environment',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/EnvironmentResponse']
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocRequests()
    {
        return [];
    }

    protected function getApiDocResponses()
    {
        $class = trim(strrchr(static::class, '\\'), '\\');

        return [
            $class . 'Response' => [
                'description' => 'Response',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/' . $class . 'Response']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/' . $class . 'Response']
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocSchemas()
    {
        $models = [
            'EnvironmentResponse' => [
                'type'       => 'object',
                'properties' => [
                    'platform'       => [
                        'type'        => 'object',
                        'description' => 'System platform properties.',
                        'properties'  => [
                            'version'   => ['type' => 'string'],
                            'is_hosted' => ['type' => 'boolean'],
                            'host'      => ['type' => 'string'],
                            'license'   => ['type' => 'string'],
                        ],
                    ],
                    'authentication' => [
                        'type'        => 'object',
                        'description' => 'Authentication options for this server.',
                        'properties'  => [
                            'admin'                     => [
                                'type'        => 'object',
                                'description' => 'Admin Authentication.',
                                'properties'  => [
                                    'path'    => ['type' => 'string'],
                                    'verb'    => ['type' => 'boolean'],
                                    'payload' => ['type' => 'string'],
                                ],
                            ],
                            'user'                      => [
                                'type'        => 'object',
                                'description' => 'Admin Authentication.',
                                'properties'  => [
                                    'path'    => ['type' => 'string'],
                                    'verb'    => ['type' => 'boolean'],
                                    'payload' => ['type' => 'string'],
                                ],
                            ],
                            'oauth'                     => [
                                'type'        => 'object',
                                'description' => 'System platform properties.',
                                'properties'  => [
                                    'version'   => ['type' => 'string'],
                                    'is_hosted' => ['type' => 'boolean'],
                                    'host'      => ['type' => 'string'],
                                    'license'   => ['type' => 'string'],
                                ],
                            ],
                            'adldap'                    => [
                                'type'        => 'object',
                                'description' => 'System platform properties.',
                                'properties'  => [
                                    'version'   => ['type' => 'string'],
                                    'is_hosted' => ['type' => 'boolean'],
                                    'host'      => ['type' => 'string'],
                                    'license'   => ['type' => 'string'],
                                ],
                            ],
                            'saml'                      => [
                                'type'        => 'object',
                                'description' => 'System platform properties.',
                                'properties'  => [
                                    'version'   => ['type' => 'string'],
                                    'is_hosted' => ['type' => 'boolean'],
                                    'host'      => ['type' => 'string'],
                                    'license'   => ['type' => 'string'],
                                ],
                            ],
                            'allow_open_registration'   => ['type' => 'boolean'],
                            'open_reg_email_service_id' => ['type' => 'integer', 'format' => 'int32'],
                            'allow_forever_sessions'    => ['type' => 'boolean'],
                            'login_attribute'           => ['type' => 'string'],
                        ],
                    ],
                    'apps'           => [
                        'type'        => 'array',
                        'description' => 'Array of apps.',
                        'items'       => [
                            '$ref' => '#/components/schemas/AppsResponse',
                        ],
                    ],
                    'config'         => [
                        'type'        => 'object',
                        'description' => 'System config properties.',
                        'properties'  => [
                            'resources_wrapper'     => ['type' => 'string'],
                            'always_wrap_resources' => ['type' => 'boolean'],
                            'db'                    => [
                                'type'        => 'object',
                                'description' => 'Database services options.',
                                'properties'  => [
                                    'max_records_returned' => ['type' => 'string'],
                                    'time_format'          => ['type' => 'boolean'],
                                    'date_format'          => ['type' => 'string'],
                                    'timedate_format'      => ['type' => 'string'],
                                    'timestamp_format'     => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                    'client'         => [
                        'type'        => 'object',
                        'description' => 'Calling client properties.',
                        'properties'  => [
                            'user_agent' => ['type' => 'string'],
                            'ip_address' => ['type' => 'string'],
                            'locale'     => ['type' => 'string'],
                        ],
                    ],
                    'server'         => [
                        'type'        => 'object',
                        'description' => 'System server properties.',
                        'properties'  => [
                            'server_os' => ['type' => 'string'],
                            'release'   => ['type' => 'string'],
                            'version'   => ['type' => 'string'],
                            'machine'   => ['type' => 'string'],
                            'host'      => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        return $models;
    }
}
