<?php

namespace DreamFactory\Core\GraphQL\Query;

use DreamFactory\Core\Models\BaseModel;
use GraphQL;
use GraphQL\Type\Definition\Type;
use Folklore\GraphQL\Support\Query;

class BaseModelQuery extends Query
{
    public function type()
    {
        return Type::listOf(GraphQL::type(array_get($this->attributes, 'name')));
    }

    public function args()
    {
        return [
            'id'      => ['name' => 'id', 'type' => Type::int()],
            'name'    => ['name' => 'name', 'type' => Type::string()],
        ];
    }

    public function resolve($root, $args)
    {
        /** @var BaseModel $modelClass */
        if ($modelClass = array_get($this->attributes, 'model')) {
            if (!empty($args)) {
                return $modelClass::selectByRequest($args);
            } else {
                return $modelClass::all();
            }
        }

        return [];
    }
}