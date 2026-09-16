<?php

namespace DreamFactory\Core\System\Resources;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\System\Components\AccessUsageRecorder;
use DreamFactory\Core\System\Components\AccessUsageReport;

/**
 * Read-only access audit: when each app (API key), role or user was last used
 * or denied, with never_used / stale / disabled_but_attempted /
 * role_unreferenced flags. Never returns API keys.
 */
class AccessUsage extends BaseSystemResource
{
    const DEFAULT_STALE_DAYS = 90;

    /**
     * @return array
     * @throws BadRequestException
     */
    protected function handleGET()
    {
        $subject = strtolower((string)$this->request->getParameter('subject', AccessUsageRecorder::SUBJECT_APP));
        if (!in_array($subject, AccessUsageRecorder::SUBJECTS, true)) {
            throw new BadRequestException(
                "Invalid subject '$subject'. Use one of: " . implode(', ', AccessUsageRecorder::SUBJECTS) . '.'
            );
        }

        $staleDays = $this->request->getParameter('stale_days', static::DEFAULT_STALE_DAYS);
        if (!is_numeric($staleDays) || (int)$staleDays < 1) {
            throw new BadRequestException('stale_days must be a positive integer.');
        }

        $report = AccessUsageReport::build(
            $subject,
            (int)$staleDays,
            $this->request->getParameterAsBool('include_never_used', true)
        );

        // Always wrapped, since the report carries meta alongside the records.
        return [
            config('df.resources_wrapper', 'resource') => $report['resource'],
            'meta'                                     => $report['meta'],
        ];
    }

    protected function getApiDocPaths()
    {
        $service = $this->getServiceName();
        $capitalized = camelize($service);
        $resourceName = strtolower($this->name);

        return [
            '/' . $resourceName => [
                'get' => [
                    'summary'     => 'Retrieve last-used times and audit flags for apps, roles or users.',
                    'description' =>
                        'Reports when each app (API key), role or user was last used or denied, with ' .
                        'never_used, stale, disabled_but_attempted and role_unreferenced flags. ' .
                        'API keys are never returned.',
                    'operationId' => 'get' . $capitalized . 'AccessUsage',
                    'parameters'  => [
                        [
                            'name'        => 'subject',
                            'in'          => 'query',
                            'description' => 'What to report on.',
                            'schema'      => ['type' => 'string', 'enum' => AccessUsageRecorder::SUBJECTS, 'default' => 'app'],
                        ],
                        [
                            'name'        => 'stale_days',
                            'in'          => 'query',
                            'description' => 'Last use older than this many days is flagged stale.',
                            'schema'      => ['type' => 'integer', 'minimum' => 1, 'default' => static::DEFAULT_STALE_DAYS],
                        ],
                        [
                            'name'        => 'include_never_used',
                            'in'          => 'query',
                            'description' => 'Include subjects with no recorded use.',
                            'schema'      => ['type' => 'boolean', 'default' => true],
                        ],
                    ],
                    'responses'   => [
                        '200' => [
                            'description' => 'Access usage report',
                            'content'     => ['application/json' => ['schema' => ['type' => 'object']]],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function getApiDocRequests()
    {
        return [];
    }

    protected function getApiDocResponses()
    {
        return [];
    }

    protected function getApiDocSchemas()
    {
        return [];
    }
}
