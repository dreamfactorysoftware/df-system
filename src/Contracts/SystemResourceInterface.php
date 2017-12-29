<?php
namespace DreamFactory\Core\System\Contracts;

/**
 * Something that behaves like a system resource and can handle service requests
 */

use DreamFactory\Core\Contracts\RequestHandlerInterface;

/**
 * Interface SystemResourceInterface
 *
 * @package DreamFactory\Core\Contracts
 */
interface SystemResourceInterface extends RequestHandlerInterface
{
    /**
     * @return SystemResourceTypeInterface
     */
    public static function getSystemResourceTypeInfo();

    /**
     * @param null|string $resource
     *
     * @return array
     */
    public function getPermissions($resource = null);

    /**
     * @return array
     */
    public function getAccessList();

    /**
     * @return array|null
     */
    public function getApiDocInfo();
}
