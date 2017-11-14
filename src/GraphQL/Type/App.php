<?php

namespace DreamFactory\Core\GraphQL\Type;

class App extends BaseType
{
    protected $attributes = [
        'name'        => 'App',
        'description' => 'An app',
        'model'       => \DreamFactory\Core\Models\App::class,
    ];
}