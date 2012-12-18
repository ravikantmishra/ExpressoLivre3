<?php
/**
 * Tine 2.0
 *
 * @package     Felamimail
 * @subpackage  Backend
 * @license     http://www.gnu.org/licenses/agpl.html AGPL Version 3
 * @author      Cassiano Dal Pizzol <cassiano.dalpizzol@serpro.gov.br>
 * @copyright   Copyright (c) 2009-2013 Serpro (http://www.serpro.gov.br)
 *
 */

abstract class Felamimail_Controller_Cache_Message_Abstract extends Felamimail_Controller_Message_Abstract
{
    /**
     * number of imported messages in one caching step
     *
     * @var integer
     */
    protected $_importCountPerStep = 50;
    
    /**
     * number of fetched messages for one step of flag sync
     *
     * @var integer
     */
    protected $_flagSyncCountPerStep = 1000;
    
    /**
     * max size of message to cache body for
     * 
     * @var integer
     */
    protected $_maxMessageSizeToCacheBody = 2097152;
    
    /**
     * initial cache status (used by updateCache and helper funcs)
     * 
     * @var string
     */
    protected $_initialCacheStatus = NULL;

    /**
     * message sequence in cache (used by updateCache and helper funcs)
     * 
     * @var integer
     */
    protected $_cacheMessageSequence = NULL;

    /**
     * message sequence on imap server (used by updateCache and helper funcs)
     * 
     * @var integer
     */
    protected $_imapMessageSequence = NULL;

    /**
     * start of cache update in seconds+microseconds/unix timestamp (used by updateCache and helper funcs)
     * 
     * @var float
     */
    protected $_timeStart = NULL;
    
    /**
     * time elapsed in seconds (used by updateCache and helper funcs)
     * 
     * @var integer
     */
    protected $_timeElapsed = 0;

    /**
     * time for update in seconds (used by updateCache and helper funcs)
     * 
     * @var integer
     */
    protected $_availableUpdateTime = 0;

    /**
     * returns true on uidvalidity mismatch
     * 
     * @param Felamimail_Model_Folder $_folder
     * @return boolean
     * 
     * @todo remove int casting when http://forge.tine20.org/mantisbt/view.php?id=5764 is resolved
     */
    protected function _cacheIsInvalid($_folder)
    {
        return (isset($_folder->cache_uidvalidity) && (int) $_folder->imap_uidvalidity !== (int) $_folder->cache_uidvalidity);
    }
    
    /**
     * returns true if there are messages in cache but not in folder on IMAP
     * 
     * @param Felamimail_Model_Folder $_folder
     * @return boolean
     */
    protected function _messagesInCacheButNotOnIMAP($_folder)
    {
        return ($_folder->imap_totalcount == 0 && $_folder->cache_totalcount > 0);
    }
    
    /**
     * returns true if there are messages deleted on IMAP but not in cache
     * 
     * @param Felamimail_Model_Folder $_folder
     * @return boolean
     */
    protected function _messagesDeletedOnIMAP($_folder)
    {
        return ($_folder->imap_totalcount > 0 && $this->_cacheMessageSequence > $this->_imapMessageSequence);
    }
    
    /**
     * returns true if there are new messages on IMAP
     * 
     * @param Felamimail_Model_Folder $_folder
     * @return boolean
     */
    protected function _messagesToBeAddedToCache($_folder)
    {
        return ($_folder->imap_totalcount > 0 && $this->_imapMessageSequence < $_folder->imap_totalcount);
    }
    
    /**
     * returns true if there are messages on IMAP that are missing from the cache
     * 
     * @param Felamimail_Model_Folder $_folder
     * @return boolean
     */
    protected function _messagesMissingFromCache($_folder)
    {
        return ($_folder->imap_totalcount > 0 && $_folder->cache_totalcount < $_folder->imap_totalcount);
    }
    
    /**
     * checks if cache update should not commence / fencing
     * 
     * @param Felamimail_Model_Folder $_folder
     * @param boolean $_lockFolder
     * @return boolean
     */
    protected function _doNotUpdateCache(Felamimail_Model_Folder $_folder, $_lockFolder = TRUE)
    {
        if ($_folder->is_selectable == false) {
            // nothing to be done
            return FALSE;
        }
        
        if (Felamimail_Controller_Cache_Folder::getInstance()->updateAllowed($_folder, $_lockFolder) !== true) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .  " update of folder {$_folder->globalname} currently not allowed. do nothing!");
            return FALSE;
        }
    }
    
    /**
     * expunge cache folder
     * 
     * @param Felamimail_Model_Folder $_folder
     * @param Felamimail_Backend_ImapProxy $_imap
     * @throws Felamimail_Exception_IMAPFolderNotFound
     */
    protected function _expungeCacheFolder(Felamimail_Model_Folder $_folder, Felamimail_Backend_ImapProxy $_imap)
    {
        try {
            $_imap->expunge(Felamimail_Model_Folder::encodeFolderName($_folder->globalname));
        } catch (Zend_Mail_Storage_Exception $zmse) {
            if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ . ' Removing no longer existing folder ' . $_folder->globalname . ' from cache. ' .$zmse->getMessage() );
            Felamimail_Controller_Cache_Folder::getInstance()->delete($_folder->getId());
            throw new Felamimail_Exception_IMAPFolderNotFound('Folder not found: ' . $_folder->globalname);
        }
    }
    
    /**
     * init cache update process
     * 
     * @param Felamimail_Model_Folder $_folder
     * @return void
     */
    protected function _initUpdate(Felamimail_Model_Folder $_folder)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .  " status of folder {$_folder->globalname}: {$_folder->cache_status}");
        
        $this->_initialCacheStatus = $_folder->cache_status;
        
        // reset cache counter when transitioning from Felamimail_Model_Folder::CACHE_STATUS_COMPLETE or 
        if ($_folder->cache_status == Felamimail_Model_Folder::CACHE_STATUS_COMPLETE || $_folder->cache_status == Felamimail_Model_Folder::CACHE_STATUS_EMPTY) {
            $_folder->cache_job_actions_est = 0;
            $_folder->cache_job_actions_done     = 0;
            $_folder->cache_job_startuid         = 0;
        }
        
        $_folder = Felamimail_Controller_Cache_Folder::getInstance()->getIMAPFolderCounter($_folder);
        
        if ($this->_cacheIsInvalid($_folder)) {
            if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' uidvalidity changed => clear cache: ' . print_r($_folder->toArray(), TRUE));
            $_folder = $this->clear($_folder);
        }
        
        if ($this->_messagesInCacheButNotOnIMAP($_folder)) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .  " folder is empty on imap server => clear cache of folder {$_folder->globalname}");
            $_folder = $this->clear($_folder);
        }
        
        $_folder->cache_status    = Felamimail_Model_Folder::CACHE_STATUS_UPDATING;
        $_folder->cache_timestamp = Tinebase_DateTime::now();
        
        $this->_timeStart = microtime(true);
    }
    
    /**
     * at which sequence is the message with the highest messageUid (cache + imap)?
     * 
     * @param Felamimail_Model_Folder $_folder
     * @param Felamimail_Backend_ImapProxy $_imap
     * @param boolean $_updateFolder
     * @throws Felamimail_Exception
     * @throws Felamimail_Exception_IMAPMessageNotFound
     */
    protected function _updateMessageSequence(Felamimail_Model_Folder $_folder, Felamimail_Backend_ImapProxy $_imap, $_updateFolder = TRUE)
    {
        if ($_folder->imap_totalcount > 0) { 
            $transactionId = Tinebase_TransactionManager::getInstance()->startTransaction(Tinebase_Core::getDb());
        
            $lastFailedUid   = null;
            $messageSequence = null;
            $decrementMessagesCounter = 0;
            $decrementUnreadCounter   = 0;
            
            while ($messageSequence === null) {
                $latestMessageUidArray = $this->_getLatestMessageUid($_folder);
                
                if (is_array($latestMessageUidArray)) {
                    $latestMessageId  = key($latestMessageUidArray);
                    $latestMessageUid = current($latestMessageUidArray);
                    
                    if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .  " $latestMessageId  $latestMessageUid");
                    
                    if ($latestMessageUid === $lastFailedUid) {
                        throw new Felamimail_Exception('Failed to delete invalid messageuid from cache');
                    }
                    
                    if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .  " Check messageUid {$latestMessageUid} in folder " . $_folder->globalname);
                    
                    try {
                        $this->_imapMessageSequence  = $_imap->resolveMessageUid($latestMessageUid);
                        $this->_cacheMessageSequence = $_folder->cache_totalcount;
                        $messageSequence             = $this->_imapMessageSequence + 1;
                    } catch (Zend_Mail_Protocol_Exception $zmpe) {
                        if (! $_updateFolder) {
                            throw new Felamimail_Exception_IMAPMessageNotFound('Message not found on IMAP');
                        }
                        
                        // message does not exist on imap server anymore, remove from local cache
                        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " messageUid {$latestMessageUid} not found => remove from cache");
                        
                        $lastFailedUid = $latestMessageUid;
                        
                        $latestMessage = $this->_backend->get($latestMessageId);
                        $this->_backend->delete($latestMessage);
                        
                        $decrementMessagesCounter++;
                        if (! $latestMessage->hasSeenFlag()) {
                            $decrementUnreadCounter++;
                        }
                    }
                } else {
                    $this->_imapMessageSequence = 0;
                    $messageSequence = 1;
                }
                
                if (! $this->_timeLeft()) {
                    $_folder->cache_status = Felamimail_Model_Folder::CACHE_STATUS_INCOMPLETE;
                    break;
                }
            }
            
            Tinebase_TransactionManager::getInstance()->commitTransaction($transactionId);
            
            if ($decrementMessagesCounter > 0 || $decrementUnreadCounter > 0) {
                Felamimail_Controller_Folder::getInstance()->updateFolderCounter($_folder, array(
                    'cache_totalcount'  => "-$decrementMessagesCounter",
                    'cache_unreadcount' => "-$decrementUnreadCounter",
                ));
            }
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " Cache status cache total count: {$_folder->cache_totalcount} imap total count: {$_folder->imap_totalcount} cache sequence: $this->_cacheMessageSequence imap sequence: $this->_imapMessageSequence");
    }
    
    /**
     * get message with highest messageUid from cache 
     * 
     * @param  mixed  $_folderId
     * @return Felamimail_Model_Message
     */
    protected function _getLatestMessageUid($_folderId) 
    {
        $folderId = ($_folderId instanceof Felamimail_Model_Folder) ? $_folderId->getId() : $_folderId;
        
        $filter = new Felamimail_Model_MessageFilter(array(
            array(
                'field'    => 'folder_id', 
                'operator' => 'equals', 
                'value'    => $folderId
            )
        ));
        $pagination = new Tinebase_Model_Pagination(array(
            'limit' => 1,
            'sort'  => 'messageuid',
            'dir'   => 'DESC'
        ));
        
        $result = $this->_backend->searchMessageUids($filter, $pagination);
        
        if (count($result) === 0) {
            return null;
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' Got last message uid: ' . print_r($result, TRUE));
        
        return $result;
    }
    
    /**
     * do we have time left for update (updates elapsed time)?
     * 
     * @return boolean
     */
    protected function _timeLeft()
    {
        if ($this->_availableUpdateTime === NULL) {
            // "infinite" time
            return TRUE;
        }
        
        $this->_timeElapsed = round(((microtime(true)) - $this->_timeStart));
        return ($this->_timeElapsed < $this->_availableUpdateTime);
    }
    
    /**
     * delete messages in cache
     * 
     *   - if the latest message on the cache has a different sequence number then on the imap server
     *     then some messages before the latest message(from the cache) got deleted
     *     we need to remove them from local cache first
     *     
     *   - $folder->cache_totalcount equals to the message sequence of the last message in the cache
     * 
     * @param Felamimail_Model_Folder $_folder
     * @param Felamimail_Backend_ImapProxy $_imap
     */
    protected function _deleteMessagesInCache(Felamimail_Model_Folder $_folder, Felamimail_Backend_ImapProxy $_imap)
    {
        if ($this->_messagesDeletedOnIMAP($_folder)) {

            $messagesToRemoveFromCache = $this->_cacheMessageSequence - $this->_imapMessageSequence;
            
            if ($this->_initialCacheStatus == Felamimail_Model_Folder::CACHE_STATUS_COMPLETE || $this->_initialCacheStatus == Felamimail_Model_Folder::CACHE_STATUS_EMPTY) {
                $_folder->cache_job_actions_est += $messagesToRemoveFromCache;
            }        
            
            $_folder->cache_status = Felamimail_Model_Folder::CACHE_STATUS_INCOMPLETE;
            
            if ($this->_timeElapsed < $this->_availableUpdateTime) {
            
                $begin = $_folder->cache_job_startuid > 0 ? $_folder->cache_job_startuid : $_folder->cache_totalcount;
                
                $firstMessageSequence = 0;
                 
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .  " $messagesToRemoveFromCache message to remove from cache. starting at $begin");
                
                for ($i=$begin; $i > 0; $i -= $this->_importCountPerStep) {
                    $firstMessageSequence = ($i-$this->_importCountPerStep) >= 0 ? $i-$this->_importCountPerStep : 0;
                    if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .  " fetching from $firstMessageSequence");
                    $cachedMessageUids = $this->_getCachedMessageUidsChunked($_folder, $firstMessageSequence);

                    // $cachedMessageUids can be empty if we fetch the last chunk
                    if (count($cachedMessageUids) > 0) {
                        $messageUidsOnImapServer = $_imap->messageUidExists($cachedMessageUids);
                        
                        $difference = array_diff($cachedMessageUids, $messageUidsOnImapServer);
                        $removedMessages = $this->_deleteMessagesByIdAndUpdateCounters(array_keys($difference), $_folder);
                        $messagesToRemoveFromCache -= $removedMessages;
                        
                        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
                            . " Cache status cache total count: {$_folder->cache_totalcount} imap total count: {$_folder->imap_totalcount} messages to remove: $messagesToRemoveFromCache");
                        
                        if ($messagesToRemoveFromCache <= 0) {
                            $_folder->cache_job_startuid = 0;
                            $_folder->cache_status = Felamimail_Model_Folder::CACHE_STATUS_UPDATING;
                            break;
                        }
                    }
                    
                    if (! $this->_timeLeft()) {
                        $_folder->cache_job_startuid = $i;
                        break;
                    }
                }
                
                if ($firstMessageSequence === 0) {
                    $_folder->cache_status = Felamimail_Model_Folder::CACHE_STATUS_UPDATING;
                }
            }
        }
        
        $this->_cacheMessageSequence = $_folder->cache_totalcount;
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " Cache status cache total count: {$_folder->cache_totalcount} imap total count: {$_folder->imap_totalcount} cache sequence: $this->_cacheMessageSequence imap sequence: $this->_imapMessageSequence");
                
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Folder cache status: ' . $_folder->cache_status);      
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Folder cache actions to be done yet: ' . ($_folder->cache_job_actions_est - $_folder->cache_job_actions_done));        
    }
    
    /**
     * delete messages from cache
     * 
     * @param array $_ids
     * @param Felamimail_Model_Folder $_folder
     * @return integer number of removed messages
     */
    protected function _deleteMessagesByIdAndUpdateCounters($_ids, Felamimail_Model_Folder $_folder)
    {
        if (count($_ids) == 0) {
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' No messages to delete.');
            return 0;
        }
        
        $decrementMessagesCounter = 0;
        $decrementUnreadCounter   = 0;
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .  
            ' Delete ' . count($_ids) . ' messages'
        );
        
        $messagesToBeDeleted = $this->_backend->getMultiple($_ids);
        
        $transactionId = Tinebase_TransactionManager::getInstance()->startTransaction(Tinebase_Core::getDb());
        
        foreach ($messagesToBeDeleted as $messageToBeDeleted) {
            $this->_backend->delete($messageToBeDeleted);
            
            $_folder->cache_job_actions_done++;
            $decrementMessagesCounter++;
            if (! $messageToBeDeleted->hasSeenFlag()) {
                $decrementUnreadCounter++;
            }
        }
        
        Tinebase_TransactionManager::getInstance()->commitTransaction($transactionId);
        
        $_folder = Felamimail_Controller_Folder::getInstance()->updateFolderCounter($_folder, array(
            'cache_totalcount'  => "-$decrementMessagesCounter",
            'cache_unreadcount' => "-$decrementUnreadCounter",
        ));
        
        return $decrementMessagesCounter;
    }
    
    /**
     * get message with highest messageUid from cache 
     * 
     * @param  mixed  $_folderId
     * @return array
     */
    protected function _getCachedMessageUidsChunked($_folderId, $_firstMessageSequnce) 
    {
        $folderId = ($_folderId instanceof Felamimail_Model_Folder) ? $_folderId->getId() : $_folderId;
        
        $filter = new Felamimail_Model_MessageFilter(array(
            array(
                'field'    => 'folder_id', 
                'operator' => 'equals', 
                'value'    => $folderId
            )
        ));
        $pagination = new Tinebase_Model_Pagination(array(
            'start' => $_firstMessageSequnce,
            'limit' => $this->_importCountPerStep,
            'sort'  => 'messageuid',
            'dir'   => 'ASC'
        ));
        
        $result = $this->_backend->searchMessageUids($filter, $pagination);
        
        return $result;
    }
    
    /**
     * add new messages to cache
     * 
     * @param Felamimail_Model_Folder $_folder
     * @param Felamimail_Backend_ImapProxy $_imap
     * 
     * @todo split into smaller parts
     */
    protected function _addMessagesToCache(Felamimail_Model_Folder $_folder, Felamimail_Backend_ImapProxy $_imap)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
            .  " cache sequence: {$this->_imapMessageSequence} / imap count: {$_folder->imap_totalcount}");
    
        if ($this->_messagesToBeAddedToCache($_folder)) {
                        
            if ($this->_initialCacheStatus == Felamimail_Model_Folder::CACHE_STATUS_COMPLETE || $this->_initialCacheStatus == Felamimail_Model_Folder::CACHE_STATUS_EMPTY) {
                $_folder->cache_job_actions_est += ($_folder->imap_totalcount - $this->_imapMessageSequence);
            }
            
            $_folder->cache_status = Felamimail_Model_Folder::CACHE_STATUS_INCOMPLETE;
            
            if ($this->_fetchAndAddMessages($_folder, $_imap)) {
                $_folder->cache_status = Felamimail_Model_Folder::CACHE_STATUS_UPDATING;
            }
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
            . " Cache status cache total count: {$_folder->cache_totalcount} imap total count: {$_folder->imap_totalcount} cache sequence: $this->_cacheMessageSequence imap sequence: $this->_imapMessageSequence");
    }
    
    /**
     * fetch messages from imap server and add them to cache until timelimit is reached or all messages have been fetched
     * 
     * @param Felamimail_Model_Folder $_folder
     * @param Felamimail_Backend_ImapProxy $_imap
     * @return boolean finished
     */
    protected function _fetchAndAddMessages(Felamimail_Model_Folder $_folder, Felamimail_Backend_ImapProxy $_imap)
    {
        $messageSequenceStart = $this->_imapMessageSequence + 1;
        
        // add new messages
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
            . " Retrieve message from $messageSequenceStart to {$_folder->imap_totalcount}");
        
        while ($messageSequenceStart <= $_folder->imap_totalcount) {
            if (! $this->_timeLeft()) {
                return FALSE;
            }
            
            $messageSequenceEnd = (($_folder->imap_totalcount - $messageSequenceStart) > $this->_importCountPerStep ) 
                ? $messageSequenceStart+$this->_importCountPerStep : $_folder->imap_totalcount;
            
            if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
                .  " Fetch message from $messageSequenceStart to $messageSequenceEnd $this->_timeElapsed / $this->_availableUpdateTime");
            
            try {
                $messages = $_imap->getSummary($messageSequenceStart, $messageSequenceEnd, false);
            } catch (Zend_Mail_Protocol_Exception $zmpe) {
                // imap server might have gone away during update
                Tinebase_Core::getLogger()->notice(__METHOD__ . '::' . __LINE__ 
                    . ' IMAP protocol error during message fetching: ' . $zmpe->getMessage());
                return FALSE;
            }

            $this->_addMessagesToCacheAndIncreaseCounters($messages, $_folder);
            
            $messageSequenceStart = $messageSequenceEnd + 1;
            
            Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Folder cache status: ' . $_folder->cache_status);           
        }
        
        return ($messageSequenceEnd == $_folder->imap_totalcount);
    }

    /**
     * add imap messages to cache and increase counters
     * 
     * @param array $_messages
     * @param Felamimail_Model_Folder $_folder
     * @return Felamimail_Model_Folder
     */
    protected function _addMessagesToCacheAndIncreaseCounters($_messages, $_folder)
    {
        $incrementMessagesCounter = 0;
        $incrementUnreadCounter   = 0;
        
        $transactionId = Tinebase_TransactionManager::getInstance()->startTransaction(Tinebase_Core::getDb());
        
        foreach ($_messages as $uid => $message) {
            if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ .  " Add message $uid to cache");
            $addedMessage = $this->addMessage($message, $_folder, false);
            
            if ($addedMessage) {
                $_folder->cache_job_actions_done++;
                $incrementMessagesCounter++;
                if (! $addedMessage->hasSeenFlag()) {
                    $incrementUnreadCounter++;
                }
            }
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " Added $incrementMessagesCounter ($incrementUnreadCounter) new (unread) messages to cache.");
        
        Tinebase_TransactionManager::getInstance()->commitTransaction($transactionId);
        
        $_folder = Felamimail_Controller_Folder::getInstance()->updateFolderCounter($_folder, array(
            'cache_totalcount'  => "+$incrementMessagesCounter",
            'cache_unreadcount' => "+$incrementUnreadCounter",
        ));
    }
    
    /**
     * maybe there are some messages missing before $this->_imapMessageSequence
     * 
     * @param Felamimail_Model_Folder $_folder
     * @param Felamimail_Backend_ImapProxy $_imap
     */
    protected function _checkForMissingMessages(Felamimail_Model_Folder $_folder, Felamimail_Backend_ImapProxy $_imap)
    {
        if ($this->_messagesMissingFromCache($_folder)) {
            
            if ($this->_initialCacheStatus == Felamimail_Model_Folder::CACHE_STATUS_COMPLETE || $this->_initialCacheStatus == Felamimail_Model_Folder::CACHE_STATUS_EMPTY) {
                $_folder->cache_job_actions_est += ($_folder->imap_totalcount - $_folder->cache_totalcount);
            }
            
            $_folder->cache_status = Felamimail_Model_Folder::CACHE_STATUS_INCOMPLETE;
            
            if ($this->_timeLeft()) { 
                // add missing messages
                if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .  " Retrieve message from {$_folder->imap_totalcount} to 1");
                
                $begin = $_folder->cache_job_lowestuid > 0 ? $_folder->cache_job_lowestuid : $this->_imapMessageSequence;
                
                for ($i = $begin; $i > 0; $i -= $this->_importCountPerStep) { 
                    
                    $messageSequenceStart = (($i - $this->_importCountPerStep) > 0 ) ? $i - $this->_importCountPerStep : 1;
                    
                    if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ .  " Fetch message from $messageSequenceStart to $i $this->_timeElapsed / $this->_availableUpdateTime");
                    
                    $messageUidsOnImapServer = $_imap->resolveMessageSequence($messageSequenceStart, $i);
                    
                    $missingUids = $this->_getMissingMessageUids($_folder, $messageUidsOnImapServer);
                    
                    if (count($missingUids) != 0) {
                        $messages = $_imap->getSummary($missingUids);
                        $this->_addMessagesToCacheAndIncreaseCounters($messages, $_folder);
                    }
                    
                    if ($_folder->cache_totalcount == $_folder->imap_totalcount || $messageSequenceStart == 1) {
                        $_folder->cache_job_lowestuid = 0;
                        $_folder->cache_status = Felamimail_Model_Folder::CACHE_STATUS_UPDATING;
                        break;
                    }
                    
                    if (! $this->_timeLeft()) {
                        $_folder->cache_job_lowestuid = $messageSequenceStart;
                        break;
                    }
                    Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Folder cache status: ' . $_folder->cache_status);           
                }
                
                if (defined('messageSequenceStart') && $messageSequenceStart === 1) {
                    $_folder->cache_status = Felamimail_Model_Folder::CACHE_STATUS_UPDATING;
                }
            }
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . " Cache status cache total count: {$_folder->cache_totalcount} imap total count: {$_folder->imap_totalcount} cache sequence: $this->_cacheMessageSequence imap sequence: $this->_imapMessageSequence");
                
        Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Folder cache status: ' . $_folder->cache_status);        
    }
    
    /**
     * create new message for the cache
     * 
     * @param array $_message
     * @param Felamimail_Model_Folder $_folder
     * @return Felamimail_Model_Message
     */
    protected function _createMessageToCache(array $_message, Felamimail_Model_Folder $_folder)
    {
        $message = new Felamimail_Model_Message(array(
            'account_id'    => $_folder->account_id,
            'messageuid'    => $_message['uid'],
            'folder_id'     => $_folder->getId(),
            'timestamp'     => Tinebase_DateTime::now(),
            'received'      => Felamimail_Message::convertDate($_message['received']),
            'size'          => $_message['size'],
            'flags'         => $_message['flags'],
        ));

        $message->parseStructure($_message['structure']);
        $message->parseHeaders($_message['header']);
        $message->parseBodyParts();
        $message->parseSmime($_message['structure']);
        
        $attachments = Felamimail_Controller_Message::getInstance()->getAttachments($message);
        $message->has_attachment = (count($attachments) > 0) ? true : false;
        
        return $message;
    }

    /**
     * add message to cache backend
     * 
     * @param Felamimail_Model_Message $_message
     * @return Felamimail_Model_Message|bool
     */
    protected function _addMessageToCache(Felamimail_Model_Message $_message)
    {
        try {
            $result = $this->_backend->create($_message);
        } catch (Zend_Db_Statement_Exception $zdse) {
            if (Tinebase_Core::isLogLevel(Zend_Log::NOTICE)) Tinebase_Core::getLogger()->notice(__METHOD__ . '::' . __LINE__ . ' ' . $zdse->getMessage());
            if (preg_match("/String data, right truncated: 1406 Data too long for column 'from_(email|name)'/", $zdse->getMessage())) {
                // try to trim email fields
                $_message->from_email = substr($_message->from_email, 0, 254);
                $_message->from_name = substr($_message->from_name, 0, 254);
                $result = $this->_addMessageToCache($_message);
            } else {
                // perhaps we already have this message in our cache (duplicate)
                $result = FALSE;
            }
        }
        
        return $result;
    }
    
    /**
     * save message in tinebase cache
     * - only cache message headers if received during the last day
     * 
     * @param Felamimail_Model_Message $_message
     * @param Felamimail_Model_Folder $_folder
     * @param array $_messageData
     * 
     * @todo do we need the headers in the Tinebase cache?
     */
    protected function _saveMessageInTinebaseCache(Felamimail_Model_Message $_message, Felamimail_Model_Folder $_folder, $_messageData)
    {
        if (! $_message->received->isLater(Tinebase_DateTime::now()->subDay(3))) {
            return;
        }
        
        $memory = (function_exists('memory_get_peak_usage')) ? memory_get_peak_usage(true) : memory_get_usage(true);
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
            . ' caching message ' . $_message->getId() . ' / memory usage: ' . $memory/1024/1024 . ' MBytes');
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' ' . print_r($_message->toArray(), TRUE));
        
        $cacheId = 'getMessageHeaders' . $_message->getId();
        Tinebase_Core::getCache()->save($_messageData['header'], $cacheId, array('getMessageHeaders'));
    
        // prefetch body to cache
        if ($_message->size < $this->_maxMessageSizeToCacheBody) {
            $account = Felamimail_Controller_Account::getInstance()->get($_folder->account_id);
            $mimeType = ($account->display_format == Felamimail_Model_Account::DISPLAY_HTML || $account->display_format == Felamimail_Model_Account::DISPLAY_CONTENT_TYPE)
                ? Zend_Mime::TYPE_HTML
                : Zend_Mime::TYPE_TEXT;
            Felamimail_Controller_Message::getInstance()->getMessageBody($_message, null, $mimeType, $account);
        }
    }
    
    /**
     * update folder status and counters
     * 
     * @param Felamimail_Model_Folder $_folder
     */
    protected function _updateFolderStatus(Felamimail_Model_Folder $_folder)
    {
        if ($_folder->cache_status == Felamimail_Model_Folder::CACHE_STATUS_UPDATING) {
            $_folder->cache_status               = Felamimail_Model_Folder::CACHE_STATUS_COMPLETE;
            $_folder->cache_job_actions_est      = 0;
            $_folder->cache_job_actions_done     = 0;
            $_folder->cache_job_lowestuid        = 0;
            $_folder->cache_job_startuid         = 0;
        }
        
        if ($_folder->cache_status == Felamimail_Model_Folder::CACHE_STATUS_COMPLETE) {
            $this->_checkAndUpdateFolderCounts($_folder);
        }
        
        $_folder = Felamimail_Controller_Folder::getInstance()->update($_folder);
        
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' Folder values after import of all messages: ' . print_r($_folder->toArray(), TRUE));
    }
    
    /**
     * check and update mismatching folder counts (totalcount + unreadcount)
     * 
     * @param Felamimail_Model_Folder $_folder
     */
    protected function _checkAndUpdateFolderCounts(Felamimail_Model_Folder $_folder)
    {
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Checking foldercounts.');
        
        $updatedCounters = Felamimail_Controller_Cache_Folder::getInstance()->getCacheFolderCounter($_folder);
        
        if ($this->_countMismatch($_folder, $updatedCounters)) {
            if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ .
                ' something went wrong while in/decrementing counters => recalculate cache counters by counting rows in database.' .
                " Cache status cache total count: {$_folder->cache_totalcount} imap total count: {$_folder->imap_totalcount}");
                        
            Felamimail_Controller_Folder::getInstance()->updateFolderCounter($_folder, $updatedCounters);
        }
        
        if ($updatedCounters['cache_totalcount'] != $_folder->imap_totalcount) {
            if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->info(__METHOD__ . '::' . __LINE__ 
                . ' There are still messages missing in the cache: setting status to INCOMPLETE');
            
            $_folder->cache_status == Felamimail_Model_Folder::CACHE_STATUS_INCOMPLETE;
        }
    }
    
    /**
     * returns true if one if these counts mismatch: 
     * 	- imap_totalcount/cache_totalcount
     *  - $_updatedCounters_totalcount/cache_totalcount
     *  - $_updatedCounters_unreadcount/cache_unreadcount
     * 
     * @param Felamimail_Model_Folder $_folder
     * @param array $_updatedCounters
     * @return boolean
     */
    protected function _countMismatch($_folder, $_updatedCounters)
    {
        return ($_folder->cache_totalcount != $_folder->imap_totalcount
            || $_updatedCounters['cache_totalcount'] != $_folder->cache_totalcount 
            || $_updatedCounters['cache_unreadcount'] != $_folder->cache_unreadcount
        );
    }
    
    /**
     * get uids missing from cache
     * 
     * @param  mixed  $_folderId
     * @param  array $_messageUids
     * @return array
     */
    protected function _getMissingMessageUids($_folderId, array $_messageUids) 
    {
        $folderId = ($_folderId instanceof Felamimail_Model_Folder) ? $_folderId->getId() : $_folderId;
        
        $filter = new Felamimail_Model_MessageFilter(array(
            array(
                'field'    => 'folder_id', 
                'operator' => 'equals', 
                'value'    => $folderId
            ),
            array(
                'field'    => 'messageuid', 
                'operator' => 'in', 
                'value'    => $_messageUids
            )
        ));
        
        $messageUidsInCache = $this->_backend->search($filter, NULL, array('messageuid'));
        
        $result = array_diff($_messageUids, array_keys($messageUidsInCache));
        
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' ' . print_r($result, TRUE));
        
        return $result;
    }
    
    /**
     * set flags on cache if different
     * 
     * @param Tinebase_Record_RecordSet $_messages
     * @param array $_flags
     * @param string $_folderId
     * 
     * @todo check which flags our imap server supports and allow more
     */
    protected function _setFlagsOnCache($_messages, $_flags, $_folderId)
    {
        $transactionId = Tinebase_TransactionManager::getInstance()->startTransaction(Tinebase_Core::getDb());
        $supportedFlags = array_keys(Felamimail_Controller_Message_Flags::getInstance()->getSupportedFlags(FALSE));
        
        $updateCount = 0;
        foreach ($_messages as $cachedMessage) {
            if (array_key_exists($cachedMessage->messageuid, $_flags)) {
                $newFlags = array_intersect($_flags[$cachedMessage->messageuid]['flags'], $supportedFlags);
                $cachedFlags = array_intersect($cachedMessage->flags, $supportedFlags);
                $diff1 = array_diff($cachedFlags, $newFlags);
                $diff2 = array_diff($newFlags, $cachedFlags);
                if (count($diff1) > 0 || count($diff2) > 0) {
                    $this->_backend->setFlags(array($cachedMessage->getId()), $newFlags, $_folderId);
                    $updateCount++;
                }
            }
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ . ' Updated ' . $updateCount . ' flags.');
        
        Tinebase_TransactionManager::getInstance()->commitTransaction($transactionId);        
    }
    
    /**
     * update folder quota (check if server supports QUOTA first)
     * 
     * @param Felamimail_Model_Folder $_folder
     * @param Felamimail_Backend_ImapProxy $_imap
     */
    protected function _updateFolderQuota(Felamimail_Model_Folder $_folder, Felamimail_Backend_ImapProxy $_imap)
    {
        // only do it for INBOX
        if ($_folder->localname !== 'INBOX') {
            return;
        }
        
        $account = Felamimail_Controller_Account::getInstance()->get($_folder->account_id);
        if (! $account->hasCapability('QUOTA')) {
            if (Tinebase_Core::isLogLevel(Zend_Log::INFO)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
                . ' Account ' . $account->name . ' has no QUOTA capability'); 
            return;
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::DEBUG)) Tinebase_Core::getLogger()->debug(__METHOD__ . '::' . __LINE__ 
            . ' Getting quota for INBOX ' . $_folder->getId());
            
        // get quota and save in folder
        $quota = $_imap->getQuota($_folder->localname);
        
        if (! empty($quota) && isset($quota['STORAGE'])) {
            $_folder->quota_usage = $quota['STORAGE']['usage'];
            $_folder->quota_limit = $quota['STORAGE']['limit'];
        } else {
            $_folder->quota_usage = 0;
            $_folder->quota_limit = 0;
        }
        
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' ' . print_r($quota, TRUE)); 
    }
    
    /**
     * get messages befora a date 
     * 
     * @param  mixed  $_folderId
     * @param  string $_date
     * @return array
     */
    public function SelectBeforeDate($_folderId,$_date) 
    {
        $folderId = ($_folderId instanceof Felamimail_Model_Folder) ? $_folderId->getId() : $_folderId;
        $imapbbackend = Felamimail_Controller_Message::getInstance()->_getBackendAndSelectFolder($folderId);
        $filter = new Felamimail_Model_MessageFilter(array(
            array(
                'field'    => 'folder_id', 
                'operator' => 'equals', 
                'value'    => $folderId
            ),
           array(
                'field'    => 'received', 
                'operator' => 'before', 
                'value'    => $_date
            )            
        ));
        
        $result = $this->_backend->searchMessageUids($filter);
        
        if (count($result) === 0) {
            return null;
        }
        
        $temp_result = array();
        
        foreach ($result as $key => $value) {
            $imapbbackend->addFlags($value, array('\\Deleted'));
            $temp_result[] = $key;
        }
        
        $result = $this->_deleteMessagesByIdAndUpdateCounters($temp_result, $_folderId);
        
        if (Tinebase_Core::isLogLevel(Zend_Log::TRACE)) Tinebase_Core::getLogger()->trace(__METHOD__ . '::' . __LINE__ . ' Got Messages before date: ' . print_r($temp_result, TRUE));
        
        return $result;
    }
}