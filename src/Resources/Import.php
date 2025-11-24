<?php

namespace DreamFactory\Core\System\Resources;

use DreamFactory\Core\Components\ResourceImport\Manager;
use DreamFactory\Core\Exceptions\BadRequestException;

class Import extends BaseSystemResource
{

    protected function handleGET()
    {
        return false;
    }

    protected function handlePOST()
    {
        // Get uploaded file
        $file = $this->request->getFile('file', $this->request->getFile('files'));
        // Get the service name. Defaults to 'db' service
        $service = $this->request->input('service', 'db');
        $resource = $this->request->input('resource');

        if (empty($file)) {
            $file = $this->request->input('import_url');
        }

        if (empty($file)) {
            throw new BadRequestException(
                'No import file supplied. ' .
                'Please upload a file or provide an URL of a file to import. ' .
                'Supported file type(s) is/are ' . implode(', ', Manager::FILE_EXTENSION) . '.'
            );
        }

        $importer = new Manager($file, $service, $resource);
        if ($importer->import()) {
            $importedResource = $importer->getResource();

            return [
                'resource' => \DreamFactory\Core\Utility\Environment::getURI() .
                    '/api/v2/' .
                    $service .
                    '/_table/' .
                    $importedResource
            ];
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);
        $resourceName = strtolower($this->name);

        return [
            '/' . $resourceName => [
                'post' => [
                    'summary'     => 'Imports resource data.',
                    'description' => 'Imports various resource data.',
                    'operationId' => 'importDataTo' . $capitalized,
//                    'consumes'    => ['multipart/form-data'],
                    'parameters'  => [
                        [
                            'name'        => 'import_url',
                            'in'          => 'query',
                            'schema'      => ['type' => 'string'],
                            'description' => 'URL of the resource file to import'
                        ],
                        [
                            'name'        => 'service',
                            'in'          => 'query',
                            'schema'      => ['type' => 'string'],
                            'description' => 'Name of the target service.'
                        ],
                        [
                            'name'        => 'resource',
                            'in'          => 'query',
                            'schema'      => ['type' => 'string'],
                            'description' => 'Name of the target resource'
                        ]
                    ],
                    'requestBody' => ['$ref' => '#/components/requestBodies/ImportRequest'],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/ImportResponse']
                    ],
                ]
            ]
        ];
    }

    protected function getApiDocRequests()
    {
        return [
            'ImportRequest' => [
                'description' => 'Import Request',
                'content'     => [
                    'application/csv' => [
                        'schema' => ['type' => 'array', 'items' => ['type' => 'string']]
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocResponses()
    {
        return [
            'ImportResponse' => [
                'description' => 'Import Response',
                'content'     => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ImportResponse']
                    ],
                    'application/xml'  => [
                        'schema' => ['$ref' => '#/components/schemas/ImportResponse']
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocSchemas()
    {
        return [
            'ImportResponse' => [
                'type'       => 'object',
                'properties' => [
                    'resource' => [
                        'type'        => 'string',
                        'description' => 'URL of the imported resource'
                    ]
                ]
            ]
        ];
    }
}