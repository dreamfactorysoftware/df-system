<?php

namespace DreamFactory\Core\System\Resources;

use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Utility\Session as SessionUtility;

class Session extends UserSessionResource
{
    /**
     * {@inheritdoc}
     */
    protected function handleGET()
    {
        if (!SessionUtility::isAuthenticated()) {
            throw new NotFoundException('No user session found.');
        }

        if (!SessionUtility::isSysAdmin()) {
            throw new UnauthorizedException('You are not authorized to perform this action.');
        }

        return parent::handleGET();
    }

    /**
     * {@inheritdoc}
     */
    protected function handlePOST()
    {
        $credentials = [
            'email'        => $this->getPayloadData('email'),
            'username'     => $this->getPayloadData('username'),
            'password'     => $this->getPayloadData('password'),
            'is_sys_admin' => true
        ];

        return $this->handleLogin($credentials, boolval($this->getPayloadData('remember_me')));
    }
}