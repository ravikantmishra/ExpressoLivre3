<?php

class Messenger_Preference extends Tinebase_Preference_Abstract
{
    /**************************** application preferences/settings *****************/
    
    const SHOWNOTIFICATIONS = 'showNotifications';

    const CHATHISTORY = 'chatHistory';
   
    const NAME = 'name';
    
    
    /**
     * application
     *
     * @var string
     */
    protected $_application = 'Messenger';

    /**
     * preference names that have no default option
     * 
     * @var array
     */
    protected $_skipDefaultOption = array();
        
    /**************************** public functions *********************************/
    
    /**
     * get all possible application prefs
     *
     * @return  array   all application prefs
     */
    public function getAllApplicationPreferences()
    {
        $allPrefs = array(
            self::SHOWNOTIFICATIONS,
            self::CHATHISTORY,
            self::NAME,
        );
        
        return $allPrefs;
    }
    
    /**
     * get translated right descriptions
     * 
     * @return  array with translated descriptions for this applications preferences
     */
    public function getTranslatedPreferences()
    {
        $translate = Tinebase_Translation::getTranslation($this->_application);

        $prefDescriptions = array(
            self::SHOWNOTIFICATIONS  => array(
                'label'         => $translate->_('Show Notifications'),
                'description'   => $translate->_('Show notifications ...'),
            ),
            self::CHATHISTORY  => array(
                'label'         => $translate->_('Chat History'),
                'description'   => $translate->_('History of chats ...'),
            ),
            self::NAME  => array(
                'label'         => $translate->_('Custon name'),
                'description'   => $translate->_('Custon name ...'),
            ),
        );
        
        return $prefDescriptions;
    }
    
    /**
     * get preference defaults if no default is found in the database
     *
     * @param string $_preferenceName
     * @return Tinebase_Model_Preference
     */
    public function getApplicationPreferenceDefaults($_preferenceName, $_accountId=NULL, $_accountType=Tinebase_Acl_Rights::ACCOUNT_TYPE_USER)
    {
        $translate = Tinebase_Translation::getTranslation($this->_application);
        $preference = $this->_getDefaultBasePreference($_preferenceName);
        
        switch($_preferenceName) {
            case self::SHOWNOTIFICATIONS:
                $preference->personal_only  = TRUE;
                $preference->value      = 1;
                $preference->options    = '<?xml version="1.0" encoding="UTF-8"?>
                    <options>
                        <special>' . Tinebase_Preference_Abstract::YES_NO_OPTIONS . '</special>
                    </options>';
                break;
            case self::CHATHISTORY:
                $preference->personal_only  = TRUE;
                $preference->value      = 'dont';
                $preference->options    = '<?xml version="1.0" encoding="UTF-8"?>
                    <options>
                        <option>
                            <label>' . $translate->_("Don't save history chat") . '</label>
                            <value>dont</value>
                        </option>
                        <option>
                            <label>' . $translate->_('Download history chat') . '</label>
                            <value>download</value>
                        </option>
                        <option>
                            <label>' . $translate->_('Send history chat to e-mail') . '</label>
                            <value>email</value>
                        </option>
                    </options>';
                break;
            case self::NAME:
                $preference->value = "";
                break;
            default:
                throw new Tinebase_Exception_NotFound('Default preference with name ' . $_preferenceName . ' not found.');
        }
        
        return $preference;
    }
    
    
    /**
     * get special options
     *
     * @param string $_value
     * @return array
     */
    
    protected function _getSpecialOptions($_value)
    {
        $result = array();
        switch($_value) {
            case self::NAME:
//                $accounts = Messenger_Controller_Account::getInstance()->search();
//                foreach ($accounts as $account) {
//                    $result[] = array($account->getId(), $account->name);
//                }
                $result = "";//parent::getValue($_value);
                break;
            default:
                $result = parent::_getSpecialOptions($_value);
        }
        
        return $result;
    }
   
}
