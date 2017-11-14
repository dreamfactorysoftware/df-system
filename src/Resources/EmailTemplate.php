<?php

namespace DreamFactory\Core\System\Resources;

class EmailTemplate extends BaseSystemResource
{
    /**
     * @var string DreamFactory\Core\Models\BaseSystemModel Model Class name.
     */
    protected static $model = \DreamFactory\Core\Models\EmailTemplate::class;

    protected function getApiDocSchemas()
    {
        $commonProperties = [
            'id'          => [
                'type'        => 'integer',
                'format'      => 'int32',
                'description' => 'Identifier of this template.',
            ],
            'name'        => [
                'type'        => 'string',
                'description' => 'Displayable name of this template.',
            ],
            'description' => [
                'type'        => 'string',
                'description' => 'Description of this template.',
            ],
            'to'          => [
                'type'        => 'array',
                'description' => 'Single or multiple receiver addresses.',
                'items'       => [
                    '$ref' => '#/components/schemas/EmailAddress',
                ],
            ],
            'cc'          => [
                'type'        => 'array',
                'description' => 'Optional CC receiver addresses.',
                'items'       => [
                    '$ref' => '#/components/schemas/EmailAddress',
                ],
            ],
            'bcc'         => [
                'type'        => 'array',
                'description' => 'Optional BCC receiver addresses.',
                'items'       => [
                    '$ref' => '#/components/schemas/EmailAddress',
                ],
            ],
            'subject'     => [
                'type'        => 'string',
                'description' => 'Text only subject line.',
            ],
            'body_text'   => [
                'type'        => 'string',
                'description' => 'Text only version of the body.',
            ],
            'body_html'   => [
                'type'        => 'string',
                'description' => 'Escaped HTML version of the body.',
            ],
            'from'        => [
                '$ref' => '#/components/schemas/EmailAddress',
            ],
            'reply_to'    => [
                '$ref' => '#/components/schemas/EmailAddress',
            ],
            'attachment'  => [
                'type'        => 'array',
                'description' => 'File(s) to import from storage service or URL for attachment',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'service' => [
                            'type'        => 'string',
                            'description' => 'Name of the storage service to use.'
                        ],
                        'path'    => [
                            'type'        => 'string',
                            'description' => 'File path relative to the service.'
                        ]
                    ]
                ]
            ],
            'defaults'    => [
                'type'        => 'array',
                'description' => 'Array of default name value pairs for template replacement.',
                'items'       => [
                    'type' => 'string',
                ],
            ],
        ];

        $stampProperties = [
            'created_date'       => [
                'type'        => 'string',
                'description' => 'Date this record was created.',
                'readOnly'    => true,
            ],
            'last_modified_date' => [
                'type'        => 'string',
                'description' => 'Date this record was last modified.',
                'readOnly'    => true,
            ],
        ];

        $models = [
            'EmailTemplateRequest'  => [
                'type'       => 'object',
                'properties' => $commonProperties,
            ],
            'EmailTemplateResponse' => [
                'type'       => 'object',
                'properties' => array_merge(
                    $commonProperties,
                    $stampProperties
                ),
            ],
            'EmailAddress'          => [
                'type'       => 'object',
                'properties' => [
                    'name'  => [
                        'type'        => 'string',
                        'description' => 'Optional name displayed along with the email address.',
                    ],
                    'email' => [
                        'type'        => 'string',
                        'description' => 'Required email address.',
                    ],
                ],
            ],
        ];

        return array_merge(parent::getApiDocSchemas(), $models);
    }
}