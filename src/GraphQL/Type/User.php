<?php

namespace DreamFactory\Core\GraphQL\Type;

class User extends BaseType
{
    protected $attributes = [
        'name'        => 'User',
        'description' => 'A user',
        'model'       => \DreamFactory\Core\Models\User::class,
    ];
}