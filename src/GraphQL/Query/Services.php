<?php

namespace DreamFactory\Core\GraphQL\Query;

class Services extends BaseModelQuery
{
    protected $attributes = [
        'name' => 'services',
        'type' => 'Service',
        'model' => \DreamFactory\Core\Models\Service::class,
    ];
}