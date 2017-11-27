<?php

namespace DreamFactory\Core\System\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \DreamFactory\Core\System\Components\SystemResourceManager
 * @see \DreamFactory\Core\System\Contracts\SystemResourceInterface
 */
class SystemResourceManager extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'df.system.resource';
    }
}
