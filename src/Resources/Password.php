<?php

namespace DreamFactory\Core\System\Resources;

use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Models\User;

class Password extends UserPasswordResource
{
    /**
     * Only admin accounts can be reset through the admin endpoint, but
     * refusing an ordinary account out loud (401 naming the address) while an
     * unknown address answered 200 let an unauthenticated caller sort a list
     * of emails into registered users and everyone else. Return the same
     * envelope every other reset request gets and send nothing. This mirrors
     * what df-user's Password resource does for admin addresses.
     *
     * {@inheritdoc}
     */
    protected function passwordReset($email)
    {
        if (!empty($email)) {
            /** @var User $user */
            $user = User::whereEmail($email)->first();

            if (null !== $user && !$user->is_sys_admin) {
                return static::resetRequestedResponse();
            }
        }

        return parent::passwordReset($email);
    }

    /**
     * {@inheritdoc}
     */
    protected static function isAllowed(User $user)
    {
        if (null === $user) {
            throw new NotFoundException("User not found in the system.");
        }

        if (!$user->is_sys_admin) {
            throw new UnauthorizedException('You are not authorized to reset/change password for the account ' .
                $user->email);
        }

        return true;
    }
}