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
        $this->addElement('text', 'baseurl', [
            'label'       => $this->translate('NetBox API URL'),
            'description' => $this->translate('Base URL to the Netbox API, e.g. https://netbox.example.com/api'),
        ]);

        $this->addElement('password', 'apitoken', [
            'label'       => $this->translate('API Token'),
            'description' => $this->translate('See https://netbox.example.com/user/api-tokens'),
        ]);

        $this->addElement('text', 'proxy', [
            'label'       => $this->translate('Proxy'),
            'description' => $this->translate('Optional proxy server setting in the format <address>:<port>'),
        ]);

        $this->addElement('checkbox', 'ssl_enable', [
            'label' => $this->translate('Enable SSL checks'),
            'description' => $this->translate('Checking this box will cause the Netbox import module enable SSL certificate verification and SSL hostname check.'),
        ]);

        $this->addElement('checkbox', 'slug_keyids', [
            'label'       => $this->translate('Slug keyid\'s'),
            'description' => $this->translate('Where available use slugs for keyid\'s.'),
        ]);
    }
}