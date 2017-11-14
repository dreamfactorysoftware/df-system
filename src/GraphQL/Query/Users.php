<?php
namespace DreamFactory\Core\GraphQL\Query;

class Users extends BaseModelQuery
{
    protected $attributes = [
        'name' => 'users',
        'type' => 'User',
        'model' => \DreamFactory\Core\Models\User::class,
    ];
}