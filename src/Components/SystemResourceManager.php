<?php

namespace DreamFactory\Core\System\Components;

use DreamFactory\Core\System\Contracts\SystemResourceTypeInterface;
use DreamFactory\Core\System\Resources\Admin;
use DreamFactory\Core\System\Resources\App;
use DreamFactory\Core\System\Resources\Cache;
use DreamFactory\Core\System\Resources\Constant;
use DreamFactory\Core\System\Resources\Cors;
use DreamFactory\Core\System\Resources\Custom;
use DreamFactory\Core\System\Resources\EmailTemplate;
use DreamFactory\Core\System\Resources\Environment;
use DreamFactory\Core\System\Resources\Event;
use DreamFactory\Core\System\Resources\Import;
use DreamFactory\Core\System\Resources\Lookup;
use DreamFactory\Core\System\Resources\Package;
use DreamFactory\Core\System\Resources\Password;
use DreamFactory\Core\System\Resources\Profile;
use DreamFactory\Core\System\Resources\Role;
use DreamFactory\Core\System\Resources\Service;
use DreamFactory\Core\System\Resources\ServiceType;
use DreamFactory\Core\System\Resources\Session;

class SystemResourceManager
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * The custom system resource type information.
     *
     * @var SystemResourceTypeInterface[]
     */
    protected $types = [];

    /**
     * Create a new system resource manager instance.
     *
     * @param  \Illuminate\Foundation\Application $app
     */
    public function __construct($app)
    {
        $this->app = $app;
        $types = [
            [
                'name'        => 'admin',
                'label'       => 'Administrators',
                'description' => 'Allows configuration of system administrators.',
                'class_name'  => Admin::class,
            ],
            [
                'name'        => 'admin/password',
                'label'       => 'Administrator\'s Password Operations',
                'description' => 'Allows password operations for system administrators.',
                'class_name'  => Password::class,
            ],
            [
                'name'        => 'admin/profile',
                'label'       => 'Administrator\'s Profile Operations',
                'description' => 'Allows profile operations for system administrators.',
                'class_name'  => Profile::class,
            ],
            [
                'name'        => 'admin/session',
                'label'       => 'Administrator\'s Session Operations',
                'description' => 'Allows session operations for system administrators.',
                'class_name'  => Session::class,
            ],
            [
                'name'        => 'app',
                'label'       => 'Apps',
                'description' => 'Allows management of user application(s)',
                'class_name'  => App::class,
            ],
            [
                'name'        => 'cache',
                'label'       => 'Cache Administration',
                'description' => 'Allows administration of system-wide and service cache.',
                'class_name'  => Cache::class
            ],
            [
                'name'        => 'constant',
                'label'       => 'Constants',
                'description' => 'Read-only listing of constants available for client use.',
                'class_name'  => Constant::class,
                'read_only'   => true,
            ],
            [
                'name'        => 'cors',
                'label'       => 'CORS Configuration',
                'description' => 'Allows configuration of CORS system settings.',
                'class_name'  => Cors::class,
            ],
            [
                'name'        => 'custom',
                'label'       => 'Custom Settings',
                'description' => 'Allows for creating system-wide custom settings',
                'class_name'  => Custom::class,
            ],
            [
                'name'        => 'email_template',
                'label'       => 'Email Templates',
                'description' => 'Allows configuration of email templates.',
                'class_name'  => EmailTemplate::class,
            ],
            [
                'name'        => 'environment',
                'label'       => 'Environment',
                'description' => 'Read-only system environment configuration.',
                'class_name'  => Environment::class,
                'singleton'   => true,
                'read_only'   => true,
            ],
            [
                'name'        => 'event',
                'label'       => 'Events',
                'description' => 'Provides a list of system generated events.',
                'class_name'  => Event::class,
            ],
            [
                'name'        => 'import',
                'label'       => 'Import',
                'description' => 'Allows importing resources.',
                'class_name'  => Import::class,
            ],
            [
                'name'        => 'lookup',
                'label'       => 'Lookup Keys',
                'description' => 'Allows configuration of lookup keys.',
                'class_name'  => Lookup::class,
            ],
            [
                'name'        => 'package',
                'label'       => 'Package',
                'description' => 'Allows Package import/export',
                'class_name'  => Package::class
            ],
            [
                'name'        => 'role',
                'label'       => 'Roles',
                'description' => 'Allows role configuration.',
                'class_name'  => Role::class,
            ],
            [
                'name'        => 'service',
                'label'       => 'Services',
                'description' => 'Allows configuration of services.',
                'class_name'  => Service::class,
            ],
            [
                'name'        => 'service_type',
                'label'       => 'Service Types',
                'description' => 'Read-only system service types.',
                'class_name'  => ServiceType::class,
                'read_only'   => true,
            ],
        ];
        foreach ($types as $type) {
            $this->addType(new SystemResourceType($type));
        }
    }

    /**
     * Register a system resource type extension resolver.
     *
     * @param  SystemResourceTypeInterface|null $type
     *
     * @return void
     */
    public function addType(SystemResourceTypeInterface $type)
    {
        $this->types[$type->getName()] = $type;
    }

    /**
     * Return the service type info.
     *
     * @param string $name
     *
     * @return SystemResourceTypeInterface
     */
    public function getResourceType($name)
    {
        if (isset($this->types[$name])) {
            return $this->types[$name];
        }

        return null;
    }

    /**
     * Return all of the known service types.
     *
     * @return SystemResourceTypeInterface[]
     */
    public function getResourceTypes()
    {
        return $this->types;
    }

    /**
     * Return all of the known service type names.
     *
     * @return string[]
     */
    public function getResourceTypeNames()
    {
        return array_keys($this->types);
    }
}
