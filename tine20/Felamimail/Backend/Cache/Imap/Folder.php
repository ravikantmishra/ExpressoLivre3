<?php
/**
 * Tine 2.0
 *
 * @package     Felamimail
 * @subpackage  Backend
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Cassiano Dal Pizzol <cassiano.dalpizzol@serpro.gov.br>
 * @author      Bruno Costa Vieira <bruno.vieira-costa@serpro.gov.br>
 * @author      Mario Cesar Kolling <mario.kolling@serpro.gov.br>
 * @copyright   Copyright (c) 2009-2013 Serpro (http://www.serpro.gov.br)
 *
 */

class Felamimail_Backend_Cache_Imap_Folder extends Felamimail_Backend_Cache_Imap_Abstract
                                           implements Felamimail_Backend_Cache_FolderInterface
{
    /*************************** abstract functions ****************************/
    /**
     * Search for records matching given filter
     *
     * @param  Tinebase_Model_Filter_FilterGroup    $_filter
     * @param  Tinebase_Model_Pagination            $_pagination
     * @param  array|string|boolean                 $_cols columns to get, * per default / use self::IDCOL or TRUE to get only ids
     * @return Tinebase_Record_RecordSet|array
     */
    public function search(Tinebase_Model_Filter_FilterGroup $_filter = NULL, Tinebase_Model_Pagination $_pagination = NULL, $_cols = '*')    
    {
        $filters = $_filter->getFilterObjects();     
        
        foreach($filters as $filter)
        {
            switch($filter->getField())
            {
                case 'account_id':
                    $accountId = $filter->getValue();
                    break;
                case 'parent':
                    $globalName = $filter->getValue();
                    break;
                case 'id':
                    $felamimailAccount = Felamimail_Controller_Account::getInstance()->search()->toArray();
                    $accountId = $felamimailAccount[0]['id'];
                    $globalName = $filter->getValue();
                    break;
            }
        }
        
        $account = Felamimail_Controller_Account::getInstance()->get($accountId);
        $resultArray = array();
        $folders = $this->_getFoldersFromIMAP($account, $globalName);
        foreach($folders as $folder)
        {
           $resultArray[] = $this->get(self::encodeFolderUid($folder['globalName']));;
        }
        
        $result = new Tinebase_Record_RecordSet('Felamimail_Model_Folder', $resultArray, true);
        return $result;
    }
    
    /**
     * get folders from imap
     * 
     * @param Felamimail_Model_Account $_account
     * @param mixed $_folderName
     * @return array
     */
    protected function _getFoldersFromIMAP(Felamimail_Model_Account $_account, $_folderName)
    {
        if (empty($_folderName))
        {
            $folders = $this->_getRootFolders($_account);
        } else {
            if (!is_array($_folderName))
            {
                $folders = $this->_getSubfolders($_account, $_folderName);
            } 
            else
            {
                $folders = array();
                foreach ($_folderName as $folder)
                {
                    $folder = self::decodeFolderUid($folder);
                    $folders = array_merge($folders, $this->_getFolder($_account, $folder));
                }
            }  
        }
        
        return $folders;
    }
    
    /**
     * get root folders and check account capabilities and system folders
     * 
     * @param Felamimail_Model_Account $_account
     * @return array of folders
     */
    protected function _getRootFolders(Felamimail_Model_Account $_account)
    {
        $imap = Felamimail_Backend_ImapFactory::factory($_account);
        
        if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ 
            . ' Get subfolders of root for account ' . $_account->getId());
        $result = $imap->getFolders('', '%');
        
        return $result;
    }
    
    /**
     * get root folders and check account capabilities and system folders
     * 
     * @param Felamimail_Model_Account $_account
     * @return array of folders
     */
    protected function _getFolder($_folderName)
    {
        $imap = Felamimail_Backend_ImapFactory::factory($_account);
        
        if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ 
            . ' Get folder ' . $_folderName);
        $result = $imap->getFolders(Felamimail_Model_Folder::encodeFolderName($_folderName));
        
        return $result;
    }
    
    /**
     * get subfolders
     * 
     * @param $_account
     * @param $_folderName
     * @return array of folders
     */
    protected function _getSubfolders(Felamimail_Model_Account $_account, $_folderName)
    {
        $result = array();
        
        try {
            if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ 
                . ' trying to get subfolders of ' . $_folderName . $this->_delimiter);

            $imap = Felamimail_Backend_ImapFactory::factory($_account);
            $result = $imap->getFolders(Felamimail_Model_Folder::encodeFolderName($_folderName) . '/', '%');
            
            // remove folder if self
            if (in_array($_folderName, array_keys($result))) {
                unset($result[$_folderName]);
            }        
        } catch (Zend_Mail_Storage_Exception $zmse) {
            if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ 
                . ' No subfolders of ' . $_folderName . ' found.');
        }
        
        return $result;
    }
    
    
    
    
    /**
     * Updates existing entry
     *
     * @param Tinebase_Record_Interface $_record
     * @throws Tinebase_Exception_Record_Validation|Tinebase_Exception_InvalidArgument
     * @return Tinebase_Record_Interface Record|NULL
     */
    public function update(Tinebase_Record_Interface $_record)
    {
/*        
Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . ' Folder update = $_record ' . print_r($_record,true));
*/ 
//        $aux = new Felamimail_Backend_Cache_Sql_Folder();        
//        $retorno = $aux->update($_record);
        
//Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . 'Folder update = $retorno ' . print_r($retorno,true));
        return NULL;        
    }
    
    
    /**
     * Gets total count of search with $_filter
     * 
     * @param Tinebase_Model_Filter_FilterGroup $_filter
     * @return int
     */
    public function searchCount(Tinebase_Model_Filter_FilterGroup $_filter)
    {
/*        
Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . ' Folder searchCount = $_filter ' . print_r($_filter,true));
*/  
        $aux = new Felamimail_Backend_Cache_Sql_Folder();
        $retorno = $aux->searchCount($_filter);
//Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . 'Folder searchCount = $retorno ' . print_r($retorno,true));
        return $retorno;
    }
    

    
    /**
     * Gets one entry (by id)
     *
     * @param string $_id
     * @param $_getDeleted get deleted records
     * @return Tinebase_Record_Interface
     * @throws Tinebase_Exception_NotFound
     */
    public function get($_id, $_getDeleted = FALSE) 
    {
            $globalName = $this->decodeFolderUid($_id);
            
            $imap = Felamimail_Backend_ImapFactory::factory(Tinebase_Core::getPreference('Felamimail')->{Felamimail_Preference::DEFAULTACCOUNT});

            if($globalName == 'user'){
                   return new Felamimail_Model_Folder(array(
                    'id' => $_id,
                    'account_id' => Tinebase_Core::getPreference('Felamimail')->{Felamimail_Preference::DEFAULTACCOUNT},
                    'localname' => $globalName,
                    'globalname' => $globalName,
                    'parent' => '',
                    'delimiter' => $globalName,self::IMAPDELIMITER,
                    'is_selectable' => 1,
                    'has_children' => 1,
                    'system_folder' => 1,
                    'imap_status' => Felamimail_Model_Folder::IMAP_STATUS_OK,
                    'imap_timestamp' => Tinebase_DateTime::now(),
                    'cache_status' => 'complete',
                    'cache_timestamp' => Tinebase_DateTime::now(),
                    'cache_job_lowestuid' => 0,
                    'cache_job_startuid' => 0,
                    'cache_job_actions_est' => 0,
                    'cache_job_actions_done' => 0
                ));
                
            }else{
                $folder = $imap->getFolders('',$globalName);
                $counter = $imap->examineFolder($globalName);
            }
            if($globalName == 'INBOX' || $globalName == 'user')
                $folder[$globalName]['parent'] = '';
            else
                $folder[$globalName]['parent'] = substr($globalName, strrpos($globalName,self::IMAPDELIMITER));
            
            return new Felamimail_Model_Folder(array(
                    'id' => $_id,
                    'account_id' => Tinebase_Core::getPreference('Felamimail')->{Felamimail_Preference::DEFAULTACCOUNT},
                    'localname' => Felamimail_Model_Folder::decodeFolderName($folder[$globalName]['localName']),
                    'globalname' => $folder[$globalName]['globalName'],
                    'parent' => '',
                    'delimiter' => $folder[$globalName]['delimiter'],
                    'is_selectable' => $folder[$globalName]['isSelectable'],
                    'has_children' => $folder[$globalName]['hasChildren'],
                    'system_folder' => 0,
                    'imap_status' => Felamimail_Model_Folder::IMAP_STATUS_OK,
                    'imap_uidvalidity' => $counter['uidvalidity'],
                    'imap_totalcount' => $counter['exists'],
                    'imap_timestamp' => Tinebase_DateTime::now(),
                    'cache_status' => 'complete',
                    'cache_totalcount' => $counter['exists'],
                    'cache_recentcount' => $counter['recent'],
                    'cache_unreadcount' => $counter['unseen'],
                    'cache_timestamp' => Tinebase_DateTime::now(),
                    'cache_job_lowestuid' => 0,
                    'cache_job_startuid' => 0,
                    'cache_job_actions_est' => 0,
                    'cache_job_actions_done' => 0
                ));
    }
    
    
    
    
     /**
      * Deletes entries
      * 
      * @param string|integer|Tinebase_Record_Interface|array $_id
      * @return void
      * @return int The number of affected rows.
      */
    public function delete($_id) 
    {
/*        
Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . ' Folder delete = $_id ' . print_r($_id,true));
*/ 
        $aux = new Felamimail_Backend_Cache_Sql_Folder();        
        $retorno = $aux->delete($_id);
        
//Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . 'Folder delete = $retorno ' . print_r($retorno,true));
        return $retorno;
    }
    
    /**
     * Get multiple entries
     *
     * @param string|array $_id Ids
     * @param array $_containerIds all allowed container ids that are added to getMultiple query
     * @return Tinebase_Record_RecordSet
     * 
     * @todo get custom fields here as well
     */
    public function getMultiple($_id, $_containerIds = NULL) 
    {
/*        
Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . ' Folder getMultiple = $_id ' . print_r($_id,true));
Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . ' Folder getMultiple = $_containerIds ' . print_r($_containerIds,true));
*/ 
        $aux = new Felamimail_Backend_Cache_Sql_Folder();        
        $retorno = $aux->getMultiple($_id, $_containerIds = NULL);
        
//Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . 'Folder delete = $retorno ' . print_r($retorno,true));
        return $retorno;
    }

    /**
     * Creates new entry
     *
     * @param   Tinebase_Record_Interface $_record
     * @return  Tinebase_Record_Interface
     * @throws  Tinebase_Exception_InvalidArgument
     * @throws  Tinebase_Exception_UnexpectedValue
     * 
     * @todo    remove autoincremental ids later
     */
    public function create(Tinebase_Record_Interface $_record)
    {
/*        
Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . ' Folder create = $_record ' . print_r($_record,true));
*/ 
        $aux = new Felamimail_Backend_Cache_Sql_Folder();        
        $retorno = $aux->create($_record);
        
//Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . 'Folder create = $retorno ' . print_r($retorno,true));
        return $retorno;        
    }
    
/*************************** interface functions ****************************/     
    /**
     * get folder cache counter like total and unseen
     *
     * @param  string  $_folderId  the folderid
     * @return array
     */
    public function getFolderCounter($_folderId)
    {
        $globalName = $self::decodeFolderUid($_folderId);
        $imap = Felamimail_Backend_ImapFactory::factory(Tinebase_Core::getPreference('Felamimail')->{Felamimail_Preference::DEFAULTACCOUNT});
        $counter = $imap->examineFolder($globalName);
      
         return array(
            'cache_totalcount'  => $counter['exists'],
            'cache_unreadcount' => $counter['unseen']
        );
    }

    /**
     * Sql cache specific function. In the IMAPAdapter always returns true
     *
     * @param  Felamimail_Model_Folder  $_folder  the folder to lock
     * @return bool true if locking was successful, false if locking was not possible
     */
    public function lockFolder(Felamimail_Model_Folder $_folder)
    {
        //return true;
        /**
         *TODO: remove the comment above, delete the lines bellow
         */
        $aux = new Felamimail_Backend_Cache_Sql_Folder();        
        return $aux->lockFolder($_folder);
    }

    /**
     * increment/decrement folder counter on sql backend
     *
     * @param  mixed  $_folderId
     * @param  array  $_counters
     * @return Felamimail_Model_Folder
     * @throws Tinebase_Exception_InvalidArgument
     */
     public function updateFolderCounter($_folderId, array $_counters)
    {
/*        
Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . ' Folder create = $_folderId ' . print_r($_folderId,true));
Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . ' Folder create = $_counters ' . print_r($_counters,true));
*/ 
        $aux = new Felamimail_Backend_Cache_Sql_Folder();        
        $retorno = $aux->updateFolderCounter($_folderId, $_counters);
        
//Tinebase_Core::getLogger()->alert(__METHOD__ . '#####::#####' . __LINE__ . 'Folder create = $retorno ' . print_r($retorno,true));
        return $retorno;
    }
    
    /**
     * Encode the folder name to be passed on the calls
     * @param string $_folder
     * @return string 
     */
    public function encodeFolderUid($_folder)
    {
        $folder = base64_encode($_folder);
        $count = substr_count($folder, '=');
      return substr($folder,0, (strlen($folder) - $count)) . $count;
    }
    
    /**
     * Decode the folder previously encoded by encoderFolderUid
     * @param type $_folder
     * @return type 
     */
    public function decodeFolderUid($_folder)
    {
        return base64_decode(str_pad(substr($_folder, 0, -1), substr($_folder, -1), '='));
    }
    
}