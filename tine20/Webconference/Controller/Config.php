<?php

/**
 * WebconferenceConfig controller for Webconference application
 * 
 * @package     Webconference
 * @subpackage  Controller
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Edgar de Lucca <edgar.lucca@sepro.gov.br>
 * @copyright   Copyright (c) 2012 Metaways Infosystems GmbH (http://www.metaways.de)
 *
 */

/**
 * WebconferenceConfig controller class for Webconference application
 * 
 * @package     Webconference
 * @subpackage  Controller
 */
class Webconference_Controller_Config extends Tinebase_Controller_Record_Abstract {

    /**
     * the constructor
     *
     * don't use the constructor. use the singleton 
     */
    private function __construct() {
        $this->_applicationName = 'Webconference';
        $this->_modelName = 'Webconference_Model_Config';
        $this->_backend = new Webconference_Backend_Config();
        $this->_currentAccount = Tinebase_Core::getUser();
        $this->_purgeRecords = FALSE;
        // activate this if you want to use containers
        $this->_doContainerACLChecks = FALSE;
    }

    /**
     * holds the instance of the singleton
     *
     * @var Webconference_Controller_ExampleRecord
     */
    private static $_instance = NULL;

    /**
     * the singleton pattern
     *
     * @return Webconference_Controller_ExampleRecord
     */
    public static function getInstance() {
        if (self::$_instance === NULL) {
            self::$_instance = new Webconference_Controller_Config();
        }
        return self::$_instance;
    }

}
