<?php

namespace Icinga\Module\Netbox\Forms\Config;

use Icinga\Forms\ConfigForm as BaseConfigForm;

class ConfigForm extends BaseConfigForm
{
    public function init()
    {
        $this->setName('netbox_config');
        $this->setTitle($this->translate('Netbox Module Configuration'));
        $this->setSubmitLabel($this->translate('Save Changes'));
    }

    public function createElements(array $formData)
    {
        $this->addElement('text', 'netbox_baseurl', [
            'label'       => $this->translate('NetBox API URL'),
            'description' => $this->translate('Base URL to the Netbox API, e.g. https://netbox.example.com/api'),
            'placeholder' => 'https://netbox.example.com/api',
            'required'    => true,
        ]);

        $this->addElement('password', 'netbox_apitoken', [
            'label'       => $this->translate('API Token'),
            'description' => $this->translate('See https://netbox.example.com/user/api-tokens'),
            'required'    => true,
        ]);

        $this->addElement('text', 'netbox_proxy', [
            'label'       => $this->translate('Proxy'),
            'description' => $this->translate('Optional proxy server setting in the format <address>:<port>'),
        ]);

        $this->addElement('checkbox', 'netbox_ssl', [
            'label' => $this->translate('Enable SSL checks'),
            'description' => $this->translate('Checking this box will cause the Netbox import module enable SSL certificate verification and SSL hostname check.'),
        ]);

        $this->addElement('checkbox', 'netbox_slug', [
            'label'       => $this->translate('Slug keyid\'s'),
            'description' => $this->translate('Where available use slugs for keyid\'s.'),
        ]);
    }
}