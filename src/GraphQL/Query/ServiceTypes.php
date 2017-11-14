<?php

namespace DreamFactory\Core\GraphQL\Query;

use GraphQL;
use GraphQL\Type\Definition\Type;
use Folklore\GraphQL\Support\Query;
use ServiceManager;

class ServiceTypes extends Query
{
    protected $attributes = [
        'name' => 'service_types',
    ];

    public function type()
    {
        return Type::listOf(GraphQL::type('ServiceType'));
    }

    public function args()
    {
        return [
            'name' => ['name' => 'name', 'type' => Type::string()],
            'group' => ['name' => 'group', 'type' => Type::string()],
        ];
    }

    public function resolve($root, $args)
    {
        if (isset($args['name'])) {
            return ServiceManager::getServiceType($args['name'])->toArray();
        } elseif (isset($args['group'])) {
            $types = [];
            $result = ServiceManager::getServiceTypes($args['group']);
            foreach ($result as $type) {
                $types[] = (object)$type->toArray();
            }

            return $types;
        } else {
            $types = [];
            $result = ServiceManager::getServiceTypes();
            foreach ($result as $type) {
                $types[] = (object)$type->toArray();
            }

            return $types;
        }
    }
}