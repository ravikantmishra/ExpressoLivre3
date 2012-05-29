<?php
/**
 * Tine 2.0
 * @package     HumanResources
 * @subpackage  Frontend
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Alexander Stintzing <a.stintzing@metaways.de>
 * @copyright   Copyright (c) 2012 Metaways Infosystems GmbH (http://www.metaways.de)
 * 
 */

/**
 *
 * This class handles all Json requests for the HumanResources application
 *
 * @package     HumanResources
 * @subpackage  Frontend
 */
class HumanResources_Frontend_Json extends Tinebase_Frontend_Json_Abstract
{
    /**
     * the controller
     *
     * @var HumanResources_Controller_Employee
     */
    protected $_controller = NULL;
    
    /**
     * user fields (created_by, ...) to resolve in _multipleRecordsToJson and _recordToJson
     *
     * @var array
     */
    protected $_resolveUserFields = array(
        'HumanResources_Model_Employee' => array('created_by', 'last_modified_by')
    );
    
    /**
     * the constructor
     *
     */
    public function __construct()
    {
        $this->_applicationName = 'HumanResources';
    }
    
    /**
     * Search for records matching given arguments
     *
     * @param  array $filter
     * @param  array $paging
     * @return array
     */
    public function searchEmployees($filter, $paging)
    {
        return $this->_search($filter, $paging, HumanResources_Controller_Employee::getInstance(), 'HumanResources_Model_EmployeeFilter');
    }     
    
    /**
     * Return a single record
     *
     * @param   string $id
     * @return  array record data
     */
    public function getEmployee($id)
    {
        return $this->_get($id, HumanResources_Controller_Employee::getInstance());
    }

    /**
     * creates/updates a record
     *
     * @param  array $recordData
     * @return array created/updated record
     */
    public function saveEmployee($recordData)
    {
        return $this->_save($recordData, HumanResources_Controller_Employee::getInstance(), 'Employee');
    }
    
    /**
     * deletes existing records
     *
     * @param  array  $ids 
     * @return string
     */
    public function deleteEmployees($ids)
    {
        return $this->_delete($ids, HumanResources_Controller_Employee::getInstance());
    }

    /**
     * Search for records matching given arguments
     *
     * @param  array $filter
     * @param  array $paging
     * @return array
     */
    public function searchWorkingTimes($filter, $paging)
    {
        
        return $this->_search($filter, $paging, HumanResources_Controller_WorkingTime::getInstance(), 'HumanResources_Model_WorkingTimeFilter');
    }     
    
    /**
     * Return a single record
     *
     * @param   string $id
     * @return  array record data
     */
    public function getWorkingTime($id)
    {
        return $this->_get($id, HumanResources_Controller_WorkingTime::getInstance());
    }

    /**
     * creates/updates a record
     *
     * @param  array $recordData
     * @return array created/updated record
     */
    public function saveWorkingTime($recordData)
    {    
        return $this->_save($recordData, HumanResources_Controller_WorkingTime::getInstance(), 'WorkingTime');
    }
    
    /**
     * deletes existing records
     *
     * @param  array  $ids 
     * @return string
     */
    public function deleteWorkingTime($ids)
    {
        return $this->_delete($ids, HumanResources_Controller_WorkingTime::getInstance());
    }


    /**
     * Search for records matching given arguments
     *
     * @param  array $filter
     * @param  array $paging
     * @return array
     */
    public function searchFreeTimes($filter, $paging)
    {
        return $this->_search($filter, $paging, HumanResources_Controller_FreeTime::getInstance(), 'HumanResources_Model_FreeTimeFilter');
    }     
    
    /**
     * Return a single record
     *
     * @param   string $id
     * @return  array record data
     */
    public function getFreeTime($id)
    {
        return $this->_get($id, HumanResources_Controller_FreeTime::getInstance());
    }

    /**
     * creates/updates a record
     *
     * @param  array $recordData
     * @return array created/updated record
     */
    public function saveFreeTime($recordData)
    {    
        return $this->_save($recordData, HumanResources_Controller_FreeTime::getInstance(), 'FreeTime');
    }
    
    /**
     * deletes existing records
     *
     * @param  array  $ids 
     * @return string
     */
    public function deleteFreeTime($ids)
    {
        return $this->_delete($ids, HumanResources_Controller_FreeTime::getInstance());
    }
    
    /**
     * returns record prepared for json transport
     *
     * @param Tinebase_Record_Interface $_record
     * @return array record data
     */
    protected function _recordToJson($_record)
    {
        switch (get_class($_record)) {
            case 'HumanResources_Model_Employee':
                $_record['contact_id'] = !empty($_record['contact_id']) ? Addressbook_Controller_Contact::getInstance()->get($_record['contact_id'])->toArray() : null;
                $filter = new HumanResources_Model_ElayerFilter(array(), 'AND');
                $filter->addFilter(new Tinebase_Model_Filter_Id('employee_id', 'equals', $_record['id']));
                $recs = HumanResources_Controller_Elayer::getInstance()->search($filter, $paging);
                $this->_resolveMultipleWorkingTimes($recs);
                $_record['elayers'] = $recs;
                break;
            case 'HumanResources_Model_FreeTime':
                $_record['employee_id'] = !empty($_record['employee_id']) ? HumanResources_Controller_Employee::getInstance()->get($_record['employee_id'])->toArray() : null;
                $filter = new HumanResources_Model_FreeDayFilter(array(), 'AND');
                $filter->addFilter(new Tinebase_Model_Filter_Text('freetime_id', 'equals', $_record['id']));
                $recs = HumanResources_Controller_FreeDay::getInstance()->search($filter);
                $recs->sort('date', 'ASC');
                $_record['freedays'] = $this->_multipleRecordsToJson($recs);
                break;
            case 'HumanResources_Model_Elayer':
                $_record['employee_id'] = !empty($_record['employee_id']) ? HumanResources_Controller_Employee::getInstance()->get($_record['employee_id'])->toArray() : null;
                break;
        }

        return parent::_recordToJson($_record);
    }
    /**
     * resolves multiple records
     * @param Tinebase_Record_RecordSet $_records
     */
    protected function _multipleRecordsToJson(Tinebase_Record_RecordSet $_records)
    {
        switch ($_records->getRecordClassName()) {
            case 'HumanResources_Model_FreeTime':
                $this->_resolveMultipleEmployees($_records);
                break;
            case 'HumanResources_Model_Elayer':
                $this->_resolveMultipleEmployees($_records);
                $this->_resolveMultipleWorkingTimes($_records);
                break;
//             case 'HumanResources_Model_WorkingTime':
//                 $this->_resolveMultipleEmployees($_records);
//                 break;
        }
//                             die(var_dump($_records->toArray()));
        return parent::_multipleRecordsToJson($_records);
    }

    /**
     * resolves multiple working times
     * @param Tinebase_Record_RecordSet $_records
     */
    protected function _resolveMultipleWorkingTimes(Tinebase_Record_RecordSet $_records)
    {
        $wIds = array_unique($_records->workingtime_id);
        $wt = HumanResources_Controller_WorkingTime::getInstance()->getMultiple($wIds);
        foreach ($_records as $record) {
            $idx = $wt->getIndexById($record->workingtime_id);
            if(isset($idx)) {
                $record->workingtime_id = $wt[$idx];
            } else {
                $record->workingtime_id = NULL;
            }
        }
    }

    /**
     * resolves multiple contacts
     * @param Tinebase_Record_RecordSet $_records
     */
    protected function _resolveMultipleEmployees(Tinebase_Record_RecordSet $_records)
    {
        $eIds = array_unique($_records->employee_id);
        $e = HumanResources_Controller_Employee::getInstance()->getMultiple($eIds);
        foreach ($_records as $record) {
            $id = $e->getIndexById($record->employee_id);
            if(isset($id)) {
                $record->employee_id = $e[$id];
            } else {
                $record->employee_id = NULL;
            }
        }
    }    
    
//     /**
//      * returns multiple records prepared for json transport
//      *
//      * NOTE: we can't use parent::_multipleRecordsToJson here because of the different container handling
//      *
//      * @param Tinebase_Record_RecordSet $_records
//      * @return array data
//      */
//     protected function _multipleRecordsToJson(Tinebase_Record_RecordSet $_records, $_filter=NULL)
//     {
//         if (count($_records) == 0) {
//             return array();
//         }

//         switch ($_records->getRecordClassName()) {
//             case 'IPAccounting_Model_IPVolume':
//             case 'IPAccounting_Model_IPAggregate':
//                 $ipnetIds = $_records->netid;
//                 $ipnets = $this->_ipnetController->getMultiple(array_unique(array_values($ipnetIds)), true);

//                 foreach ($_records as $record) {
//                     $idx = $ipnets->getIndexById($record->netid);
//                     if ($idx !== FALSE) {
//                         $record->netid = $ipnets[$idx];
//                     } else {
//                         Tinebase_Core::getLogger()->warn(__METHOD__ . '::' . __LINE__ . ' Could not resolve ipnet (id: ' . $record->netid . '). No permission?');
//                     }
//                 }
//                 break;
                
//             case 'IPAccounting_Model_IPNet':
//                 break;
//         }

//         $recordArray = $_records->toArray();

//         foreach($recordArray as &$rec) {
//             $rec['account_grants'] = $this->defaultGrants;
//         }
        
//         return $recordArray;
//     }
    
    
    
    
    
    
//     /**
//      * Returns registry data
//      * 
//      * @return array
//      */
//     public function getRegistryData()
//     {
//         $defaultContainerArray = Tinebase_Container::getInstance()->getDefaultContainer($this->_applicationName)->toArray();
//         $defaultContainerArray['account_grants'] = Tinebase_Container::getInstance()->getGrantsOfAccount(Tinebase_Core::getUser(), $defaultContainerArray['id'])->toArray();
        
//         return array(
//             'defaultContainer' => $defaultContainerArray
//         );
//     }
}
