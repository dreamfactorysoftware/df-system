<?php

namespace DreamFactory\Core\System\Resources;

use DreamFactory\Core\Models\App;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Models\RoleServiceAccess;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Utility\ResponseFactory;

class ConfigPackage extends BaseSystemResource
{
    protected function handleGET()
    {
        return ResponseFactory::create($this->exportManifest());
    }

    protected function handlePOST()
    {
        $payload = $this->request->getPayloadData();
        $manifest = array_get($payload, 'manifest', $payload);
        $overwrite = $this->request->getParameterAsBool('overwrite');

        return ResponseFactory::create($this->importManifest($manifest, $overwrite));
    }

    protected function exportManifest()
    {
        return [
            'format'      => 'dreamfactory.config/v1',
            'exported_at' => gmdate('c'),
            'services'    => $this->exportServices(),
            'roles'       => $this->exportRoles(),
            'apps'        => $this->exportApps(),
        ];
    }

    protected function exportServices()
    {
        return Service::orderBy('name')->get()->map(function (Service $service) {
            return [
                'name'        => $service->name,
                'label'       => $service->label,
                'description' => $service->description,
                'type'        => $service->type,
                'is_active'   => (bool)$service->is_active,
                'config'      => $service->config ?: [],
            ];
        })->values()->all();
    }

    protected function exportRoles()
    {
        return Role::orderBy('name')->get()->map(function (Role $role) {
            $access = RoleServiceAccess::whereRoleId($role->id)->get()->map(function (RoleServiceAccess $row) {
                $service = Service::whereId($row->service_id)->first();

                return [
                    'service'        => $service ? $service->name : null,
                    'component'      => trim((string)$row->component, '/'),
                    'verb_mask'      => (int)$row->verb_mask,
                    'requestor_mask' => (int)$row->requestor_mask,
                    'filters'        => $row->filters ?: [],
                    'filter_op'      => $row->filter_op,
                ];
            })->filter(function ($row) {
                return !empty($row['service']);
            })->values()->all();

            return [
                'name'        => $role->name,
                'description' => $role->description,
                'is_active'   => (bool)$role->is_active,
                'service_access' => $access,
            ];
        })->values()->all();
    }

    protected function exportApps()
    {
        return App::orderBy('name')->get()->map(function (App $app) {
            $role = $app->role_id ? Role::whereId($app->role_id)->first() : null;

            return [
                'name'                    => $app->name,
                'description'             => $app->description,
                'is_active'               => (bool)$app->is_active,
                'type'                    => (int)$app->type,
                'path'                    => $app->path,
                'url'                     => $app->url,
                'role'                    => $role ? $role->name : null,
                'requires_fullscreen'     => (bool)$app->requires_fullscreen,
                'allow_fullscreen_toggle' => (bool)$app->allow_fullscreen_toggle,
                'toggle_location'         => $app->toggle_location,
            ];
        })->values()->all();
    }

    protected function importManifest(array $manifest, $overwrite = false)
    {
        if (array_get($manifest, 'format') !== 'dreamfactory.config/v1') {
            throw new BadRequestException('Unsupported config package format.');
        }

        $result = [
            'success' => true,
            'created' => ['services' => 0, 'roles' => 0, 'apps' => 0],
            'updated' => ['services' => 0, 'roles' => 0, 'apps' => 0],
            'skipped' => ['services' => 0, 'roles' => 0, 'apps' => 0],
            'errors'  => [],
        ];

        $this->importServices((array)array_get($manifest, 'services', []), $overwrite, $result);
        $this->importRoles((array)array_get($manifest, 'roles', []), $overwrite, $result);
        $this->importApps((array)array_get($manifest, 'apps', []), $overwrite, $result);

        $result['success'] = empty($result['errors']);

        return $result;
    }

    protected function importServices(array $services, $overwrite, array &$result)
    {
        foreach ($services as $service) {
            $name = array_get($service, 'name');
            if (empty($name)) {
                $result['errors'][] = 'Skipped service without a name.';
                continue;
            }

            $model = Service::whereName($name)->first();
            if ($model && !$overwrite) {
                $result['skipped']['services']++;
                continue;
            }

            $created = empty($model);
            $model = $model ?: new Service();
            $model->fill([
                'name'        => $name,
                'label'       => array_get($service, 'label', $name),
                'description' => array_get($service, 'description'),
                'type'        => array_get($service, 'type'),
                'is_active'   => array_get($service, 'is_active', true),
                'config'      => array_get($service, 'config', []),
            ]);
            $model->save();
            $result[$created ? 'created' : 'updated']['services']++;
        }
    }

    protected function importRoles(array $roles, $overwrite, array &$result)
    {
        foreach ($roles as $role) {
            $name = array_get($role, 'name');
            if (empty($name)) {
                $result['errors'][] = 'Skipped role without a name.';
                continue;
            }

            $model = Role::whereName($name)->first();
            if ($model && !$overwrite) {
                $result['skipped']['roles']++;
                continue;
            }

            $created = empty($model);
            $model = $model ?: new Role();
            $model->fill([
                'name'        => $name,
                'description' => array_get($role, 'description'),
                'is_active'   => array_get($role, 'is_active', true),
            ]);
            $model->save();

            if ($overwrite) {
                RoleServiceAccess::whereRoleId($model->id)->delete();
            }
            $this->importRoleAccess($model, (array)array_get($role, 'service_access', []), $result);
            $result[$created ? 'created' : 'updated']['roles']++;
        }
    }

    protected function importRoleAccess(Role $role, array $accessRows, array &$result)
    {
        foreach ($accessRows as $row) {
            $serviceName = array_get($row, 'service');
            $service = $serviceName ? Service::whereName($serviceName)->first() : null;
            if (!$service) {
                $result['errors'][] = 'Role "' . $role->name . '" references missing service "' . $serviceName . '".';
                continue;
            }

            RoleServiceAccess::create([
                'role_id'        => $role->id,
                'service_id'     => $service->id,
                'component'      => '/' . trim((string)array_get($row, 'component', '*'), '/'),
                'verb_mask'      => (int)array_get($row, 'verb_mask', 31),
                'requestor_mask' => (int)array_get($row, 'requestor_mask', 3),
                'filters'        => array_get($row, 'filters', []),
                'filter_op'      => array_get($row, 'filter_op'),
            ]);
        }
    }

    protected function importApps(array $apps, $overwrite, array &$result)
    {
        foreach ($apps as $app) {
            $name = array_get($app, 'name');
            if (empty($name)) {
                $result['errors'][] = 'Skipped app without a name.';
                continue;
            }

            $model = App::whereName($name)->first();
            if ($model && !$overwrite) {
                $result['skipped']['apps']++;
                continue;
            }

            $roleName = array_get($app, 'role');
            $role = $roleName ? Role::whereName($roleName)->first() : null;

            $created = empty($model);
            $model = $model ?: new App();
            $model->fill([
                'name'                    => $name,
                'description'             => array_get($app, 'description'),
                'is_active'               => array_get($app, 'is_active', true),
                'type'                    => array_get($app, 'type', 0),
                'path'                    => array_get($app, 'path'),
                'url'                     => array_get($app, 'url'),
                'role_id'                 => $role ? $role->id : null,
                'requires_fullscreen'     => array_get($app, 'requires_fullscreen', false),
                'allow_fullscreen_toggle' => array_get($app, 'allow_fullscreen_toggle', false),
                'toggle_location'         => array_get($app, 'toggle_location'),
            ]);
            if ($created && empty($model->api_key)) {
                $model->api_key = App::generateApiKey($model->name);
            }
            $model->save();
            $result[$created ? 'created' : 'updated']['apps']++;
        }
    }
}
