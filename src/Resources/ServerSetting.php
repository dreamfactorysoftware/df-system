<?php

namespace DreamFactory\Core\System\Resources;

use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\System\Models\ServerSetting as Settings;
use DreamFactory\Core\Utility\Session;

/**
 * Sys-admin-only view and edit of allow-listed runtime server settings (log level).
 */
class ServerSetting extends BaseRestResource
{
    protected static function getResourceIdentifier()
    {
        return 'name';
    }

    /** The system service waves admins through without a per-resource check, so enforce it here. */
    protected function settingName()
    {
        if (!Session::isSysAdmin()) {
            throw new UnauthorizedException('You are not authorized to perform this action.');
        }
        if (!isset(Settings::ALLOWED[$this->resource])) {
            throw new NotFoundException("Server setting '{$this->resource}' not found.");
        }

        return $this->resource;
    }

    protected function audit($name, $to)
    {
        // Written before the change applies, so it is not lost to a stricter new level.
        \Log::notice('Server setting changed', [
            'setting' => $name, 'from' => Settings::row($name)['value'], 'to' => $to, 'user_id' => Session::getCurrentUserId(),
        ]);
    }

    protected function handleGET()
    {
        if (empty($this->resource)) {
            if (!Session::isSysAdmin()) {
                throw new UnauthorizedException('You are not authorized to perform this action.');
            }

            return ['resource' => Settings::rows()];
        }

        return Settings::row($this->settingName());
    }

    protected function handlePUT()
    {
        $name = $this->settingName();
        $to = $this->getPayloadData('value');
        $this->audit($name, $to);

        return Settings::set($name, $to);
    }

    protected function handlePATCH()
    {
        return $this->handlePUT();
    }

    protected function handleDELETE()
    {
        $name = $this->settingName();
        $this->audit($name, Settings::row($name)['env_value']);

        return Settings::clear($name);
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
                    'description' => 'Sys admin only. Shows each setting, its effective value, the .env value and the source.',
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
