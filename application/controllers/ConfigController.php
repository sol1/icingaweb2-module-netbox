<?php
namespace Icinga\Module\Clustergraph\Controllers;

use Icinga\Application\Config;
use Icinga\Forms\ConfigForm;
use Icinga\Web\Controller;
use Icinga\Module\Netbox\Forms\Config\ConfigForm;

class Netbox_ConfigController extends Controller
{
    public function indexAction()
    {
        // Load (or create) the module's config.ini
        $config = Config::module('netbox');
        
        $form = new ConfigForm();
        $form->setIniConfig($config); 
        $form->handleRequest();

        $this->view->form = $form;
    }
}