<?php
namespace DreamFactory\Core\GraphQL\Type;

use GraphQL\Type\Definition\Type;

class ServiceType extends BaseType
{
    protected $attributes = [
        'name' => 'ServiceType',
        'description' => 'A service type',
    ];

    public function fields()
    {
        return [
            'name' => [
                'type' => Type::string(),
                'description' => 'The name of the service type'
            ],
            'label' => [
                'type' => Type::string(),
                'description' => 'The name of the service type'
            ],
            'group' => [
                'type' => Type::string(),
                'description' => 'The name of the service type'
            ],
            'description' => [
                'type' => Type::string(),
                'description' => 'The name of the service type'
            ],
        ];
    }
}