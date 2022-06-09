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
         // IntegrateIo Hosted Trial Login
         if ($this->getPayloadData('integrateio_id') !== null) {
            $credentials = [
                'integrateio_id' => $this->getPayloadData('integrateio_id'),
                'email'          => $this->getPayloadData('email'),
                'sso_token'      => $this->getPayloadData('sso_token'),
                'timestamp'      => $this->getPayloadData('timestamp')
            ];

            return $this->handleIntegrateLogin($credentials, boolval($this->getPayloadData('remember_me')));
        }

        $credentials = [
            'email'        => $this->getPayloadData('email'),
            'username'     => $this->getPayloadData('username'),
            'password'     => $this->getPayloadData('password'),
            'is_sys_admin' => true
        ];

        return $this->handleLogin($credentials, boolval($this->getPayloadData('remember_me')));
    }
}