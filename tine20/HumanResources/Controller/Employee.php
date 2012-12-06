<?php
/**
 * Employee controller for HumanResources application
 *
 * @package     HumanResources
 * @subpackage  Controller
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Alexander Stintzing <a.stintzing@metaways.de>
 * @copyright   Copyright (c) 2012 Metaways Infosystems GmbH (http://www.metaways.de)
 *
 */

/**
 * Employee controller class for HumanResources application
 *
 * @package     HumanResources
 * @subpackage  Controller
 */
class HumanResources_Controller_Employee extends Tinebase_Controller_Record_Abstract
{

    protected $_duplicateCheckFields = array(array('account_id'), array('number'));
    
    /**
     * the constructor
     *
     * don't use the constructor. use the singleton
     */
    private function __construct()
    {
        $this->_applicationName = 'HumanResources';
        $this->_backend = new HumanResources_Backend_Employee();
        $this->_modelName = 'HumanResources_Model_Employee';
        $this->_purgeRecords = FALSE;
        // activate this if you want to use containers
        $this->_doContainerACLChecks = FALSE;
    }

    /**
     * holds the instance of the singleton
     *
     * @var HumanResources_Controller_Employee
     */
    private static $_instance = NULL;

    /**
     * the singleton pattern
     *
     * @return HumanResources_Controller_Employee
     */
    public static function getInstance()
    {
        if (static::$_instance === NULL) {
            static::$_instance = new HumanResources_Controller_Employee();
        }

        return static::$_instance;
    }

    /**
     * inspect creation of one record (after create)
     *
     * @param   Tinebase_Record_Interface $_createdRecord
     * @param   Tinebase_Record_Interface $_record
     * @return  void
     * 
     * @todo $_record->contracts should be a Tinebase_Record_RecordSet
     */
    protected function _inspectAfterCreate($_createdRecord, Tinebase_Record_Interface $_record)
    {
        if(! $_record->has('contracts')) {
            return;
        }

        $contracts = new Tinebase_Record_RecordSet('HumanResources_Model_Contract');
        $createdContracts = new Tinebase_Record_RecordSet('HumanResources_Model_Contract');
        
        if (! empty($_record->contracts) && is_array($_record->contracts)) {
            foreach ($_record->contracts as $contractArray) {
                if (is_array($contractArray['workingtime_id'])) {
                    $contractArray['workingtime_id'] = $contractArray['workingtime_id']['id'];
                }
                if (is_array($contractArray['cost_center_id'])) {
                    $contractArray['cost_center_id'] = $contractArray['cost_center_id']['id'];
                }
                if (is_array($contractArray['feast_calendar_id'])) {
                    $contractArray['feast_calendar_id'] = $contractArray['feast_calendar_id']['id'];
                }
                $contractArray['employee_id'] = $_createdRecord->getId();
                $contract = new HumanResources_Model_Contract($contractArray);
                $contracts->addRecord($contract);
            }
        }
        $contracts->sort('start_date', 'ASC');
        foreach ($contracts as $contract) {
            $createdContracts->addRecord(HumanResources_Controller_Contract::getInstance()->create($contract));
        }
        $_createdRecord->contracts = $createdContracts;
    }

    /**
     * inspect update of one record (before update)
     *
     * @param   Tinebase_Record_Interface $_record      the update record
     * @param   Tinebase_Record_Interface $_oldRecord   the current persistent record
     * @return  void
     * 
     * @todo $_record->contracts should be a Tinebase_Record_RecordSet
     * @todo use getMigration()
     */
    protected function _inspectBeforeUpdate($_record, $_oldRecord)
    {
        if(is_array($_record->account_id)) {
            $_record->account_id = $_record->account_id['accountId'];
        }

        if(! $_record->has('contracts')) {
            return;
        }

        $contracts = new Tinebase_Record_RecordSet('HumanResources_Model_Contract');
        $ec = HumanResources_Controller_Contract::getInstance();
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' '
            . print_r($_record->contracts, TRUE));

        if (! empty($_record->contracts) && is_array($_record->contracts)) {
            foreach ($_record->contracts as $contractArray) {
                if (is_array($contractArray['workingtime_id'])) {
                    $contractArray['workingtime_id'] = $contractArray['workingtime_id']['id'];
                }
                if (isset($contractArray['cost_center_id']) && is_array($contractArray['cost_center_id'])) {
                    $contractArray['cost_center_id'] = $contractArray['cost_center_id']['id'];
                }
                if (isset($contractArray['feast_calendar_id']) && is_array($contractArray['feast_calendar_id'])) {
                    $contractArray['feast_calendar_id'] = $contractArray['feast_calendar_id']['id'];
                }
                $contractArray['employee_id'] = $_oldRecord->getId();
                $contract = new HumanResources_Model_Contract($contractArray);
                if ($contract->id) {
                    if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' '
                        . 'Updating contract ' . $contract->id);
                    $contracts->addRecord($ec->update($contract));
                } else {
                    $contracts->addRecord($ec->create($contract));
                }
            }
        }
            
        $filter = new HumanResources_Model_ContractFilter(array(), 'AND');
        $filter->addFilter(new Tinebase_Model_Filter_Text('employee_id', 'equals', $_record['id']));
        $filter->addFilter(new Tinebase_Model_Filter_Id('id', 'notin', $contracts->id));
        $deleteContracts = HumanResources_Controller_Contract::getInstance()->search($filter);
        // update first date
        $contracts->sort('start_date', 'DESC');
        $ec->delete($deleteContracts->id);
        $_record->contracts = $contracts->toArray();
    }
    
    /**
     * delete linked objects (notes, relations, ...) of record
     *
     * @param Tinebase_Record_Interface $_record
     */
    protected function _deleteLinkedObjects(Tinebase_Record_Interface $_record)
    {
        // use textfilter for employee_id 
        $eFilter = new Tinebase_Model_Filter_Text(array('field' => 'employee_id', 'operator' => 'equals', 'value' => $_record->getId()));
        
        // delete free times
        $filter = new HumanResources_Model_FreeTimeFilter(array(
            ), 'AND');
        $filter->addFilter($eFilter);
        
        HumanResources_Controller_FreeTime::getInstance()->deleteByFilter($filter);
        
        // delete contracts
        $filter = new HumanResources_Model_ContractFilter(array(
            ), 'AND');
        $filter->addFilter($eFilter);
        
        HumanResources_Controller_FreeTime::getInstance()->deleteByFilter($filter);
        
        parent::_deleteLinkedObjects($_record);
    }
    /**
     * returns the highest employee number of all employees
     * @return integer
     */
    public function getLastEmployeeNumber()
    {
        $filter = new HumanResources_Model_EmployeeFilter();
        $pagination = new Tinebase_Model_Pagination(array("sort" => "number","dir" => "DESC"));
        if($employee = $this->search($filter, $pagination)->getFirstRecord()) {
            return (int) $employee->number;
        } else {
            return 0;
        }
    }

    /**
     * transfers user accounts to employee records
     * 
     * @param boolean $_deletePrivateInfo should private information be removed from contacts
     */
    public function transferUserAccounts($_deletePrivateInfo = FALSE, $_feastCalendarId = NULL, $_workingTimeModelId = NULL, $_vacationDays = NULL, $cliCall = FALSE)
    {
        $lastNumber = $this->getLastEmployeeNumber();
        
        // get all active accounts
        $filter = new Addressbook_Model_ContactFilter(array(
                array('field' => 'type', 'operator' => 'equals', 'value' => 'user'),
                array('field' => 'is_deleted', 'operator' => 'equals', 'value' => false)
            ), 'AND'
        );
        
        $filter->addFilter(new Addressbook_Model_ContactDisabledFilter(1));
        $accounts = Addressbook_Controller_Contact::getInstance()->search($filter);
        $nextNumber = $lastNumber + 1;
        
        $countCreated = 0;
        
        foreach ($accounts as $account) {
            $filter = new HumanResources_Model_EmployeeFilter(array(array(
                'field' => 'account_id', 'operator' => 'equals', 'value' => $account->account_id
            )), 'AND');
            
            // if not already exists
            if(($lastNumber == 0) || ($this->search($filter)->count() === 0)) {
                
                $employee = new HumanResources_Model_Employee(array(
                    'number'      => $nextNumber,
                    'account_id'  => $account->account_id,
                    'countryname' => $account->adr_two_countryname,
                    'locality'    => $account->adr_two_locality,
                    'postalcode'  => $account->adr_two_postalcode,
                    'region'      => $account->adr_two_region,
                    'street'      => $account->adr_two_street,
                    'street2'     => $account->adr_two_street2,
                    'email'       => $account->email_home,
                    'tel_home'    => $account->tel_home,
                    'tel_cell'    => $account->tel_cell_private,
                    'n_fn'        => $account->n_fn,
                ));
                if($createContract) {
                    $contract = $this->createContractDataForEmployee(array(
                        'feastCalendarId' => $_feastCalendarId,
                        'workingTimeModelId' => $_workingTimeModelId,
                        'vacationDays' => $_vacationDays,
                    ), $cliCall);
                    $employee->contracts = array($contract);
                }
                
                $countCreated++;
                
                if($cliCall) {
                    echo 'Creating Employee "'. $account->n_fn . '"' . chr(10);
                }
                $this->create($employee);
                $nextNumber++;
            } else {
                if($cliCall) {
                    echo 'Employee "'. $account->n_fn . '" already exists. Skipping...' . chr(10);
                }
            }
            
            if($_deletePrivateInfo) {
                $account->adr_two_countryname = NULL;
                $account->adr_two_locality = NULL;
                $account->adr_two_postalcode = NULL;
                $account->adr_two_region = NULL;
                $account->adr_two_street = NULL;
                $account->adr_two_street2 = NULL;
                $account->email_home = NULL;
                $account->tel_home = NULL;
                $account->tel_cell_private = NULL;
                // do not remove contact image
                $account->jpegphoto = NULL;
                
                if($cliCall) {
                    echo 'Removing private information of employee "'. $account->n_fn .'"' . chr(10);
                }
                Addressbook_Controller_Contact::getInstance()->update($account);
            }
        }
        if($cliCall) {
            echo 'Created ' . $countCreated . ' employees.' . chr(10);
            echo 'Transfer OK' . chr(10);
        }
    }
    
    /**
     * create contract data
     * 
     * @param array $contractData
     * @param boolean $cliCall
     * @return array
     */
    public function createContractDataForEmployee($contractData = array(), $cliCall = FALSE)
    {
        if (isset($contractData['feastCalendarId'])) {
            try {
                $feastCalendar = Tinebase_Container::getInstance()->get($contractData['feastCalendarId']);
            } catch (Exception $e) {
                if ($cliCall) {
                    die('The Calendar with the id ' . $contractData['feastCalendarId'] . ' could not be found!' . chr(10));
                } else {
                    throw $e;
                }
            }
            if ($cliCall) {
                echo 'Found Calendar ' . $feastCalendar->name . chr(10);
            }
        } else {
            $feastCalendar = NULL;
        }
        
        if (isset($contractData['workingTimeModelId'])) {
            try {
                $workingTimeModel = HumanResources_Controller_WorkingTime::getInstance()->get($contractData['workingTimeModelId']);
            } catch (Exception $e) {
                if ($cliCall) {
                    die('The Working Time Model with the id ' . $contractData['workingTimeModelId'] . ' could not be found!' . chr(10));
                } else {
                    throw $e;
                }
            }
            if ($cliCall) {
                echo 'Found Working Time Model "' . $workingTimeModel->title . '"' . chr(10);
            }
        } else {
            $workingTimeModel = NULL;
        }
        
        if (isset($contractData['costCenterId'])) {
            $costCenter = Sales_Controller_CostCenter::getInstance()->get($contractData['costCenterId']);
        } else {
            $costCenter = NULL;
        }
        
        return array(
            'feast_calendar_id'  => $feastCalendar ? $feastCalendar->toArray() : NULL,
            'workingtime_id'     => $workingTimeModel ? $workingTimeModel->toArray() : NULL,
            'vacation_days'      => isset($contractData['vacationDays']) ? $contractData['vacationDays'] : NULL,
            'cost_center_id'     => $costCenter ? $costCenter->toArray() : NULL,
        );
    }
}