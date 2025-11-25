<?php
namespace Icinga\Module\Netbox\Controllers;

use Icinga\Application\Config;
use Icinga\Web\Controller;
use Icinga\Module\Netbox\Forms\Config\ConfigForm;

class ConfigController extends Controller
{
    public function init(): void
    {
        $this->assertPermission('config/modules');
        parent::init();
    }

    public function indexAction()
    {
        // Load (or create) the module's config.ini
        $config = Config::module('netbox');
        
        $form = new ConfigForm();
        $form->setIniConfig($config); 
        $form->handleRequest();

        $this->view->form = $form;
        $this->view->tabs = $this->Module()->getConfigTabs()->activate('config');
    }
}