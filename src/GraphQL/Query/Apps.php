<?php
namespace DreamFactory\Core\GraphQL\Query;

class Apps extends BaseModelQuery
{
    protected $attributes = [
        'name' => 'apps',
        'type' => 'App',
        'model' => \DreamFactory\Core\Models\App::class,
    ];
}