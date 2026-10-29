<?php

namespace DreamFactory\Core\System\Resources;

use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\System\Models\ServerSetting as Settings;
use DreamFactory\Core\Utility\Session;

/**
 * View and edit of allow-listed runtime server settings (log level).
 * Access is role-based like any system resource: `server_setting/*` on the system service.
 */
class ServerSetting extends BaseRestResource
{
    protected static function getResourceIdentifier()
    {
        return 'name';
    }

    protected function settingName()
    {
        if (!isset(Settings::ALLOWED[$this->resource])) {
            throw new NotFoundException("Server setting '{$this->resource}' not found.");
        }

        return $this->resource;
    }

    protected function audit($name, $from, $to)
    {
        // Own channel at a fixed level, so the configured (or just-changed) log level cannot drop it.
        \Log::build([
            'driver' => 'single', 'path' => storage_path('logs/dreamfactory.log'), 'level' => 'debug',
        ])->notice('Server setting changed', [
            'setting' => $name, 'from' => $from, 'to' => $to, 'user_id' => Session::getCurrentUserId(),
        ]);
    }

    protected function handleGET()
    {
        if (empty($this->resource)) {
            return ['resource' => Settings::rows()];
        }

        return Settings::row($this->settingName());
    }

    protected function handlePUT()
    {
        $name = $this->settingName();
        $from = Settings::row($name)['value'];
        $row = Settings::set($name, $this->getPayloadData('value'));
        if ($from !== $row['value']) {
            $this->audit($name, $from, $row['value']);
        }

        return $row;
    }

    protected function handlePATCH()
    {
        return $this->handlePUT();
    }

    protected function handleDELETE()
    {
        $name = $this->settingName();
        $from = Settings::row($name)['value'];
        $row = Settings::clear($name);
        if ($from !== $row['value']) {
            $this->audit($name, $from, $row['value']);
        }

        return $row;
    }

    protected function getApiDocPaths()
    {
        $capitalized = camelize($this->getServiceName());
        $path = '/' . strtolower($this->name);
        $param = [['name' => 'name', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]];

        return [
            $path             => [
                'get' => [
                    'summary'     => 'List overridable server settings.',
                    'description' => 'Requires `server_setting/*` access on the system service. Shows each setting, its effective value, the .env value and the source.',
                    'operationId' => 'get' . $capitalized . 'ServerSettings',
                    'responses'   => ['200' => ['description' => 'Success']],
                ],
            ],
            $path . '/{name}' => [
                'get'    => [
                    'summary'     => 'Retrieve one server setting.',
                    'operationId' => 'get' . $capitalized . 'ServerSetting',
                    'parameters'  => $param,
                    'responses'   => ['200' => ['description' => 'Success']],
                ],
                'patch'  => [
                    'summary'     => 'Override a server setting.',
                    'operationId' => 'update' . $capitalized . 'ServerSetting',
                    'parameters'  => $param,
                    'requestBody' => [
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object', 'properties' => ['value' => ['type' => 'string']],
                        ]]],
                    ],
                    'responses'   => ['200' => ['description' => 'Success']],
                ],
                'delete' => [
                    'summary'     => 'Remove the override and fall back to the .env value.',
                    'operationId' => 'delete' . $capitalized . 'ServerSetting',
                    'parameters'  => $param,
                    'responses'   => ['200' => ['description' => 'Success']],
                ],
            ],
        ];
    }
}
