<?php

namespace DreamFactory\Core\System\Resources;

use DreamFactory\Core\Contracts\CacheInterface;
use DreamFactory\Core\Exceptions\NotImplementedException;
use DreamFactory\Core\Resources\BaseRestResource;
use ServiceManager;

/**
 * Class Cache
 *
 * @package DreamFactory\Core\Resources
 */
class Cache extends BaseRestResource
{
    const EVENT_SCRIPT_CACHE_PREFIX = 'event_script:';

    /**
     * {@inheritdoc}
     */
    protected static function getResourceIdentifier()
    {
        return 'name';
    }

    public function getResources()
    {
        $services = [];
        $fields = ['name', 'label', 'description', 'type'];
        foreach (ServiceManager::getServiceList($fields) as $info) {
            $name = array_get($info, 'name');
            // only allowed services by role here
            if (\DreamFactory\Core\Utility\Session::checkForAnyServicePermissions($name)) {
                $services[] = $info;
            }
        }

        return $services;
    }

    /**
     * Handles DELETE action
     *
     * @return array
     * @throws NotImplementedException
     */
    protected function handleDELETE()
    {
        if (empty($this->resource)) {
            \Cache::flush();
            if (boolval(ini_get('soap.wsdl_cache_enabled'))) {
                // soap services may cache wsdl file contents
                if (false !== $path = realpath(ini_get('soap.wsdl_cache_dir'))) {
                    array_map('unlink', glob("$path/wsdl-*"));
                }
            }
        } elseif ($this->resource === '_event') {
            $eventName = $this->resourceId;
            $cacheKey = static::EVENT_SCRIPT_CACHE_PREFIX . $eventName;
            \Cache::forget($cacheKey);
        } else {
            $service = ServiceManager::getService($this->resource);
            if ($service instanceof CacheInterface) {
                $service->flush();
            } else {
                throw new NotImplementedException('Service does not implement API controlled cache.');
            }
        }

        return ['success' => true];
    }

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);
        $resourceName = strtolower($this->name);
        $path = '/' . $resourceName;

        return [
            $path                => [
                'delete' => [
                    'summary'     => 'Delete all cache.',
                    'description' => 'This clears all cached information in the system. Doing so may impact the performance of the system.',
                    'operationId' => 'deleteAllCacheFrom' . $capitalized,
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
            ],
            $path . '/{service}' => [
                'delete' => [
                    'summary'     => 'Delete cache for one service.',
                    'description' => 'This clears all cached information related to a particular service. Doing so may impact the performance of the service.',
                    'operationId' => 'deleteServiceCacheFrom' . $capitalized,
                    'parameters'  => [
                        [
                            'name'        => 'service',
                            'description' => 'Identifier of the service whose cache we are to delete.',
                            'schema'      => ['type' => 'string'],
                            'in'          => 'path',
                            'required'    => true,
                        ],
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
            ],
        ];
    }
}