<?php
namespace DreamFactory\Core\GraphQL\Type;

class Service extends BaseType
{
    protected $attributes = [
        'name'        => 'Service',
        'description' => 'A service',
        'model'       => \DreamFactory\Core\Models\Service::class,
    ];
}