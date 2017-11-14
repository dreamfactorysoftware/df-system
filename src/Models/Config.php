<?php

namespace DreamFactory\Core\System\Models;

use DreamFactory\Core\Models\App;
use DreamFactory\Core\Models\BaseServiceConfigModel;
use DreamFactory\Core\Models\EmailTemplate;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Models\SingleRecordModel;

class Config extends BaseServiceConfigModel
{
    use SingleRecordModel;

    protected $table = 'system_config';

    protected $fillable = [
        'service_id',
        'invite_email_service_id',
        'invite_email_template_id',
        'password_email_service_id',
        'password_email_template_id',
        'default_app_id'
    ];

    protected $casts = [
        'service_id'                 => 'integer',
        'invite_email_service_id'    => 'integer',
        'invite_email_template_id'   => 'integer',
        'password_email_service_id'  => 'integer',
        'password_email_template_id' => 'integer',
        'default_app_id'             => 'integer',
    ];

    protected $hidden = ['created_by_id', 'last_modified_by_id'];

    /**
     * @param array $schema
     */
    protected static function prepareConfigSchemaField(array &$schema)
    {
        parent::prepareConfigSchemaField($schema);

        switch ($schema['name']) {
            case 'default_app_id':
                $schema['label'] = 'Default Application';
                $apps = App::get();
                $appsList = [
                    [
                        'label' => '',
                        'name'  => null
                    ]
                ];
                foreach ($apps as $app) {
                    $appsList[] = [
                        'label' => $app->name,
                        'name'  => $app->id
                    ];
                }
                $schema['type'] = 'picklist';
                $schema['values'] = $appsList;
                $schema['description'] = 'Select a default application to be used for the system UI.';
                break;
            case 'invite_email_service_id':
            case 'password_email_service_id':
                $label = substr($schema['label'], 0, strlen($schema['label']) - 11);
                $services = Service::whereIsActive(1)
                    ->whereIn('type', ['aws_ses', 'smtp_email', 'mailgun_email', 'mandrill_email', 'local_email'])
                    ->get();
                $emailSvcList = [
                    [
                        'label' => '',
                        'name'  => null
                    ]
                ];
                foreach ($services as $service) {
                    $emailSvcList[] = [
                        'label' => $service->label,
                        'name'  => $service->id
                    ];
                }
                $schema['type'] = 'picklist';
                $schema['values'] = $emailSvcList;
                $schema['label'] = $label . ' Service';
                $schema['description'] =
                    'Select an Email service for sending out ' .
                    $label .
                    '.';
                break;
            case 'invite_email_template_id':
            case 'password_email_template_id':
                $label = substr($schema['label'], 0, strlen($schema['label']) - 11);
                $templates = EmailTemplate::get();
                $templateList = [
                    [
                        'label' => '',
                        'name'  => null
                    ]
                ];
                foreach ($templates as $template) {
                    $templateList[] = [
                        'label' => $template->name,
                        'name'  => $template->id
                    ];
                }
                $schema['type'] = 'picklist';
                $schema['values'] = $templateList;
                $schema['label'] = $label . ' Template';
                $schema['description'] = 'Select an Email template to use for ' .
                    $label .
                    '.';
                break;
        }
    }
}