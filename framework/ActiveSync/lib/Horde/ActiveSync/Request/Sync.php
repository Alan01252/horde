<?php
/**
 * Horde_ActiveSync_Request_Sync::
 *
 * Portions of this class were ported from the Z-Push project:
 *   File      :   wbxml.php
 *   Project   :   Z-Push
 *   Descr     :   WBXML mapping file
 *
 *   Created   :   01.10.2007
 *
 *   © Zarafa Deutschland GmbH, www.zarafaserver.de
 *   This file is distributed under GPL-2.0.
 *   Consult COPYING file for details
 *
 * @license   http://www.horde.org/licenses/gpl GPLv2
 *            NOTE: According to sec. 8 of the GENERAL PUBLIC LICENSE (GPL),
 *            Version 2, the distribution of the Horde_ActiveSync module in or
 *            to the United States of America is excluded from the scope of this
 *            license.
 * @copyright 2009-2013 Horde LLC (http://www.horde.org)
 * @author    Michael J Rubinsky <mrubinsk@horde.org>
 * @package   ActiveSync
 */
/**
 * Handle Sync requests
 *
 * @license   http://www.horde.org/licenses/gpl GPLv2
 *            NOTE: According to sec. 8 of the GENERAL PUBLIC LICENSE (GPL),
 *            Version 2, the distribution of the Horde_ActiveSync module in or
 *            to the United States of America is excluded from the scope of this
 *            license.
 * @copyright 2009-2013 Horde LLC (http://www.horde.org)
 * @author    Michael J Rubinsky <mrubinsk@horde.org>
 * @package   ActiveSync
 */
class Horde_ActiveSync_Request_Sync extends Horde_ActiveSync_Request_SyncBase
{
    /* Status */
    const STATUS_SUCCESS                = 1;
    const STATUS_VERSIONMISM            = 2;
    const STATUS_KEYMISM                = 3;
    const STATUS_PROTERROR              = 4;
    const STATUS_SERVERERROR            = 5;
    const STATUS_NOTFOUND               = 8;

    // 12.1
    const STATUS_FOLDERSYNC_REQUIRED    = 12;
    const STATUS_REQUEST_INCOMPLETE     = 13;
    const STATUS_INVALID_WAIT_HEARTBEAT = 14;

    /* Maximum window size (12.1 only) */
    const MAX_WINDOW_SIZE    = 512;

    /* Maximum HEARTBEAT value (seconds) (12.1 only) */
    const MAX_HEARTBEAT      = 3540;

    /**
     * Collection of all collection arrays for the current SYNC request.
     *
     * @var Horde_ActiveSync_Collections
     */
    protected $_collections;

    /**
     * Handle the sync request
     *
     * @return boolean
     * @throws Horde_ActiveSync_Exception
     */
    protected function _handle()
    {
        $this->_logger->info(sprintf(
            '[%s] Handling SYNC command.',
            $this->_procid)
        );

        // Check policy
        if (!$this->checkPolicyKey($this->_activeSync->getPolicyKey(), Horde_ActiveSync::SYNC_SYNCHRONIZE)) {
            return true;
        }

        // Check global errors.
        if ($error = $this->_activeSync->checkGlobalError()) {
            $this->_statusCode = $error;
            $this->_handleGlobalSyncError();
            return true;
        }

        // Defaults
        $this->_statusCode = self::STATUS_SUCCESS;
        $partial = false;

        try {
            $this->_collections = $this->_activeSync->getCollectionsObject();
        } catch (Horde_ActiveSync_Exception $e) {
            $this->_statusCode = self::STATUS_SERVERERROR;
            $this->_handleGlobalSyncError();
            return true;
        }

        // Sanity check
        if ($this->_device->version >= Horde_ActiveSync::VERSION_TWELVEONE) {
            // We don't have a previous FOLDERSYNC.
            if (!$this->_collections->haveHierarchy()) {
                $this->_logger->debug('No HIERARCHY SYNCKEY in sync_cache, invalidating.');
                $this->_statusCode = self::STATUS_FOLDERSYNC_REQUIRED;
                $this->_handleGlobalSyncError();
                return true;
            }
        }

        // Start decoding request
        if (!$this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_SYNCHRONIZE)) {
            if ($this->_device->version >= Horde_ActiveSync::VERSION_TWELVEONE) {
                $this->_logger->info(sprintf(
                    '[%s] Empty Sync request taking info from SyncCache.',
                    $this->_procid));
                if ($this->_collections->cachedCollectionCount() == 0) {
                    $this->_logger->err(sprintf(
                        '[%s] Empty SYNC request but no SyncCache or SyncCache with no collections.',
                        $this->_procid));
                    $this->_statusCode = self::STATUS_REQUEST_INCOMPLETE;
                    $this->_handleGlobalSyncError();
                    return true;
                } else {
                    $this->_collections->loadCollectionsFromCache();
                    $csk = $this->_collections->confirmed_synckeys;
                    if (count($csk) > 0) {
                        $this->_logger->err(sprintf(
                            '[%s] Unconfirmed synckeys, but handling a short request. Request full SYNC.',
                            $this->_procid));
                        $this->_statusCode = self::STATUS_REQUEST_INCOMPLETE;
                        $this->_handleGlobalSyncError();
                        return true;
                    }
                    $this->_collections->shortSyncRequest = true;
                    $this->_collections->save();
                }
            } else {
                $this->_statusCode = self::STATUS_REQUEST_INCOMPLETE;
                $this->_handleGlobalSyncError();
                $this->_logger->err('Empty Sync request and protocolversion < 12.1');
                return true;
            }
        } else {
            if ($this->_device->version >= Horde_ActiveSync::VERSION_TWELVEONE) {
                $this->_collections->setHeartbeat(array('wait' => false, 'hbinterval' => false));
            }

            // Start decoding request.
            while (($sync_tag = ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_WINDOWSIZE) ? Horde_ActiveSync::SYNC_WINDOWSIZE :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_FOLDERS) ? Horde_ActiveSync::SYNC_FOLDERS :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_PARTIAL) ? Horde_ActiveSync::SYNC_PARTIAL :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_WAIT) ? Horde_ActiveSync::SYNC_WAIT :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_HEARTBEATINTERVAL) ? Horde_ActiveSync::SYNC_HEARTBEATINTERVAL :
                   -1)))))) != -1 ) {

                switch($sync_tag) {
                case Horde_ActiveSync::SYNC_HEARTBEATINTERVAL:
                    if ($hbinterval = $this->_decoder->getElementContent()) {
                        $this->_collections->setHeartbeat(array('hbinterval' => $hbinterval));
                        $this->_decoder->getElementEndTag();
                        if ($hbinterval > (self::MAX_HEARTBEAT)) {
                            $this->_logger->err(sprintf(
                                '[%s] HeartbeatInterval outside of allowed range.',
                                $this->_procid)
                            );
                            $this->_statusCode = self::STATUS_INVALID_WAIT_HEARTBEATINTERVAL;
                            $this->_handleGlobalSyncError(self::MAX_HEARTBEAT);
                            return true;
                        }
                    }
                    break;
                case Horde_ActiveSync::SYNC_WAIT:
                    if ($wait = $this->_decoder->getElementContent()) {
                        $this->_collections->setHeartbeat(array('wait' => $wait));
                        $this->_decoder->getElementEndTag();
                        if ($wait > (self::MAX_HEARTBEAT / 60)) {
                            $this->_logger->err(sprintf(
                                '[%s] Wait value outside of allowed range.',
                                $this->_procid)
                            );
                            $this->_statusCode = self::STATUS_INVALID_WAIT_HEARTBEATINTERVAL;
                            $this->_handleGlobalSyncError(self::MAX_HEARBEAT / 60);
                            return true;
                        }
                    }
                    break;
                case Horde_ActiveSync::SYNC_PARTIAL:
                    if ($this->_decoder->getElementContent(Horde_ActiveSync::SYNC_PARTIAL)) {
                        $this->_decoder->getElementEndTag();
                    }
                    $partial = true;
                    break;
                case Horde_ActiveSync::SYNC_WINDOWSIZE:
                    $window_size = $this->_decoder->getElementContent();
                    $this->_collections->setDefaultWindowSize($window_size);
                    if (!$this->_decoder->getElementEndTag()) {
                        $this->_logger->err('PROTOCOL ERROR');
                        return false;
                    }
                    break;
                case Horde_ActiveSync::SYNC_FOLDERS:
                    if (!$this->_parseSyncFolders()) {
                        // Any errors are handled in _parseSyncFolders() and
                        // appropriate error codes sent to device.
                        return true;
                    }
                }
            }

            // Preparation for EAS >= 12.1
            if ($this->_device->version >= Horde_ActiveSync::VERSION_TWELVEONE) {

                // These are not allowed in the same request.
                if ($this->_collections->hbinterval !== false &&
                    $this->_collections->wait !== false) {

                    $this->_logger->err('Received both HBINTERVAL and WAIT interval in same request. VIOLATION.');
                    $this->_statusCode = Horde_ActiveSync_Status::INVALID_XML;
                    $this->_handleGlobalSyncError();
                    return true;
                }

                // Fill in missing information from cache.
                $this->_collections->validateFromCache();
            }

            // Check for inifinite sync loops.
            if (!$this->_collections->checkLoopCounters()) {
                $this->_statusCode = Horde_ActiveSync_Status::SERVER_ERROR;
                $this->_handleGlobalSyncError();
            }

            // Ensure the FILTERTYPE hasn't changed. If so, we need to invalidate
            // the client's synckey to force a sync reset. This is the only
            // reliable way of fetching an older set of data from the backend.
            if (!$this->_collections->checkFilterType()) {
                $this->_statusCode = self::STATUS_KEYMISM;
                $this->_handleError($collection);
                return true;
            }

            // Full or partial sync request?
            if ($partial === true) {
                $this->_logger->info(sprintf(
                    '[%s] Executing a PARTIAL SYNC.',
                    $this->_procid));
                if (!$this->_collections->initPartialSync()) {
                    $this->_statusCode = self::STATUS_REQUEST_INCOMPLETE;
                    $this->_handleGlobalSyncError();
                    return true;
                }

                // Fill in any missing collections that were already sent.
                // @TODO: Can we move this to initPartialSync()?
                $this->_collections->getMissingCollectionsFromCache();

            } else {
                // Full request.
                $this->_collections->initFullSync();
            }

            // End SYNC tag.
            if (!$this->_decoder->getElementEndTag()) {
                $this->_statusCode = self::STATUS_PROTERROR;
                $this->_handleGlobalSyncError();
                $this->_logger->err('PROTOCOL ERROR: Missing closing SYNC tag');
                return false;
            }

            // We MUST have syncable collections by now.
            if (!$this->_collections->haveSyncableCollections($this->_device->version)) {
                $this->_statusCode = self::STATUS_KEYMISM;
                $this->_handleGlobalSyncError();
                return true;
            }

            // Update the syncCache with the new collection data.
            $this->_collections->updateCache();
            // Refresh collections that might have changed in another process.
            // $this->_collections->updateCollectionsFromCache();
            // Save.
            $this->_collections->save();

            $this->_logger->info('All synckeys confirmed. Continuing with SYNC');
        }

        // If this is >= 12.1, see if we want a looping SYNC.
        if ($this->_collections->canDoLoopingSync() &&
            $this->_device->version >= Horde_ActiveSync::VERSION_TWELVEONE &&
            $this->_statusCode == self::STATUS_SUCCESS) {

            // Calculate the heartbeat
            $pingSettings = $this->_driver->getHeartbeatConfig();
            if (!$heartbeat = $this->_collections->getHeartbeat()) {
                $heartbeat = !empty($pingSettings['heartbeatdefault'])
                    ? $pingSettings['heartbeatdefault']
                    : 10;
            }

            // Wait for changes.
            $changes = $this->_collections->pollForChanges($heartbeat, $pingSettings['waitinterval']);
            if ($changes !== true && $changes !== false) {
                switch ($changes) {
                case Horde_ActiveSync_Collections::COLLECTION_ERR_STALE:
                    $this->_logger->err(sprintf(
                        '[%s] Changes in cache detected during looping SYNC exiting here.',
                        $this->_procid));
                    return true;
                case Horde_ActiveSync_Collections::COLLECTION_ERR_FOLDERSYNC_REQUIRED;
                    $this->_statusCode = self::STATUS_FOLDERSYNC_REQUIRED;
                    $this->_handleGlobalSyncError();
                    return true;
                case Horde_ActiveSync_Collections::COLLECTION_ERR_SYNC_REQUIRED;
                    $this->_statusCode = self::STATUS_REQUEST_INCOMPLETE;
                    $this->_handleGlobalSyncError();
                    return true;
                default:
                    $this->_statusCode = self::STATUS_SERVERERROR;
                    $this->_handleGlobalSyncError();
                    return true;
                }
            }
        }

        // See if we can do an empty response
        if ($this->_device->version >= Horde_ActiveSync::VERSION_TWELVEONE &&
            $this->_statusCode == self::STATUS_SUCCESS &&
            empty($changes) &&
            $this->_collections->canSendEmptyResponse()) {

            $this->_logger->info('Sending an empty SYNC response.');
            $this->_collections->lastsyncendnormal = time();
            $this->_collections->save();
            return true;
        }

        // Start output to PIM
        $this->_encoder->startWBXML();
        $this->_encoder->startTag(Horde_ActiveSync::SYNC_SYNCHRONIZE);
        $this->_encoder->startTag(Horde_ActiveSync::SYNC_STATUS);
        $this->_encoder->content(self::STATUS_SUCCESS);
        $this->_encoder->endTag();
        $this->_encoder->startTag(Horde_ActiveSync::SYNC_FOLDERS);

        $exporter = new Horde_ActiveSync_Connector_Exporter(
            $this->_activeSync,
            $this->_encoder);

        foreach ($this->_collections as $id => $collection) {
            $statusCode = self::STATUS_SUCCESS;
            $changecount = 0;
            try {
                $this->_collections->initCollectionState($collection);
            } catch (Horde_ActiveSync_Exception_StateGone $e) {
                $this->_logger->err(sprintf(
                    '[%s] SYNC terminating, state not found',
                    $this->_procid)
                );
                $statusCode = self::STATUS_KEYMISM;
            } catch (Horde_ActiveSync_Exception_FolderGone $e) {
                // This isn't strictly correct, but at least some versions of
                // iOS need this in order to catch missing state.
                $this->_logger->err($e->getMessage());
                $statusCode = self::STATUS_FOLDERSYNC_REQUIRED;
            } catch (Horde_ActiveSync_Exception $e) {
                $this->_logger->err($e->getMessage());
                return false;
            }

            if ($statusCode == self::STATUS_SUCCESS &&
                (!empty($collection['getchanges']) ||
                 (!isset($collection['getchanges']) && $collection['synckey'] != '0'))) {

                try {
                    $changecount = $this->_collections->getCollectionChangeCount();
                } catch (Horde_ActiveSync_Exception_StaleState $e) {
                    $this->_logger->err(sprintf(
                        '[%s] Force restting of state for %s: %s',
                        $this->_procid,
                        $id,
                        $e->getMessage()));
                    $this->_state->loadState(
                        array(),
                        null,
                        Horde_ActiveSync::REQUEST_TYPE_SYNC,
                        $id);
                    $statusCode = self::STATUS_KEYMISM;
                } catch (Horde_ActiveSync_Exception_StateGone $e) {
                    $this->_logger->err(sprintf(
                        '[%s] SYNCKEY not found. Reset required.', $this->_procid));
                        $statusCode = self::STATUS_KEYMISM;
                } catch (Horde_ActiveSync_Exception_FolderGone $e) {
                    $this->_logger->err(sprintf(
                        '[%s] FOLDERSYNC required, collection gone.',
                        $this->_procid));
                    $statusCode = self::STATUS_FOLDERSYNC_REQUIRED;
                }
            }

            // Get new synckey if needed. We need a new synckey if there were
            // any changes (incoming or outgoing), if this is during the
            // initial sync pairing of the collection, or if we received a
            // SYNC due to changes found during a PING (since those changes
            // may be changes to items that never even made it to the PIM in
            // the first place (See Bug: 12075).
            if ($statusCode == self::STATUS_SUCCESS &&
                (!empty($collection['importedchanges']) ||
                !empty($changecount) ||
                $collection['synckey'] == '0' ||
                $this->_state->getSyncKeyCounter($collection['synckey']) == 1 ||
                !empty($collection['fetchids']) ||
                $this->_collections->hasPingChangeFlag($id))) {

                // Increment the loop detection counter.
                $this->_collections->incrementLoopCounter($id, $collection['synckey']);

                try {
                    $collection['newsynckey'] = $this->_state->getNewSyncKey($collection['synckey']);
                    $this->_logger->info(sprintf(
                        'Old SYNCKEY: %s, New SYNCKEY: %s',
                        $collection['synckey'],
                        $collection['newsynckey'])
                    );
                } catch (Horde_ActiveSync_Exception $e) {
                    $statusCode = self::STATUS_KEYMISM;
                }
            }

            $this->_encoder->startTag(Horde_ActiveSync::SYNC_FOLDER);

            // Not sent in > 12.0
            if ($this->_device->version <= Horde_ActiveSync::VERSION_TWELVE) {
                $this->_encoder->startTag(Horde_ActiveSync::SYNC_FOLDERTYPE);
                $this->_encoder->content($collection['class']);
                $this->_encoder->endTag();
            }

            $this->_encoder->startTag(Horde_ActiveSync::SYNC_SYNCKEY);
            if (!empty($collection['newsynckey'])) {
                $this->_encoder->content($collection['newsynckey']);
            } else {
                $this->_encoder->content($collection['synckey']);
            }
            $this->_encoder->endTag();

            $this->_encoder->startTag(Horde_ActiveSync::SYNC_FOLDERID);
            $this->_encoder->content($collection['id']);
            $this->_encoder->endTag();

            $this->_encoder->startTag(Horde_ActiveSync::SYNC_STATUS);
            $this->_encoder->content($statusCode);
            $this->_encoder->endTag();

            // Check the mimesupport because we need it for advanced emails
            if (!isset($collection['mimesupport'])) {
                $collection['mimesupport'] = 0;
            }

            if ($statusCode == self::STATUS_SUCCESS) {
                if (!empty($collection['clientids']) || !empty($collection['fetchids']) || !empty($collection['missing'])) {
                    $this->_encoder->startTag(Horde_ActiveSync::SYNC_REPLIES);

                    // Output any errors from missing messages in REMOVE requests.
                    if (!empty($collection['missing'])) {
                        foreach ($collection['missing'] as $uid) {
                            $this->_encoder->startTag(Horde_ActiveSync::SYNC_REMOVE);
                            $this->_encoder->startTag(Horde_ActiveSync::SYNC_CLIENTENTRYID);
                            $this->_encoder->content($uid);
                            $this->_encoder->endTag();
                            $this->_encoder->startTag(Horde_ActiveSync::SYNC_STATUS);
                            $this->_encoder->content(self::STATUS_NOTFOUND);
                            $this->_encoder->endTag();
                            $this->_encoder->endTag();
                        }
                    }

                    // Output server IDs for new items we received and added from PIM
                    if (!empty($collection['clientids'])) {
                        foreach ($collection['clientids'] as $clientid => $serverid) {
                            $this->_encoder->startTag(Horde_ActiveSync::SYNC_ADD);
                            $this->_encoder->startTag(Horde_ActiveSync::SYNC_CLIENTENTRYID);
                            $this->_encoder->content($clientid);
                            $this->_encoder->endTag();
                            $this->_encoder->startTag(Horde_ActiveSync::SYNC_SERVERENTRYID);
                            $this->_encoder->content($serverid);
                            $this->_encoder->endTag();
                            $this->_encoder->startTag(Horde_ActiveSync::SYNC_STATUS);
                            $this->_encoder->content(self::STATUS_SUCCESS);
                            $this->_encoder->endTag();
                            $this->_encoder->endTag();
                        }
                    }

                    if (!empty($collection['fetchids'])) {
                        // Output any FETCH requests
                        foreach ($collection['fetchids'] as $fetch_id) {
                            try {
                                $data = $this->_driver->fetch($collection['id'], $fetch_id, $collection);
                                $this->_encoder->startTag(Horde_ActiveSync::SYNC_FETCH);
                                $this->_encoder->startTag(Horde_ActiveSync::SYNC_SERVERENTRYID);
                                $this->_encoder->content($fetch_id);
                                $this->_encoder->endTag();
                                $this->_encoder->startTag(Horde_ActiveSync::SYNC_STATUS);
                                $this->_encoder->content(self::STATUS_SUCCESS);
                                $this->_encoder->endTag();
                                $this->_encoder->startTag(Horde_ActiveSync::SYNC_DATA);
                                $data->encodeStream($this->_encoder);
                                $this->_encoder->endTag();
                                $this->_encoder->endTag();
                            } catch (Horde_Exception_NotFound $e) {
                                $this->_logger->err(sprintf(
                                    '[%s] Unable to fetch %s',
                                    $this->_procid,
                                    $fetch_id)
                                );
                                $this->_encoder->startTag(Horde_ActiveSync::SYNC_FETCH);
                                $this->_encoder->startTag(Horde_ActiveSync::SYNC_SERVERENTRYID);
                                $this->_encoder->content($fetch_id);
                                $this->_encoder->endTag();
                                $this->_encoder->startTag(Horde_ActiveSync::SYNC_STATUS);
                                $this->_encoder->content(self::STATUS_NOTFOUND);
                                $this->_encoder->endTag();
                                $this->_encoder->endTag();
                            }
                        }
                    }

                    $this->_encoder->endTag();
                }

                // Send server changes to PIM
                if ($statusCode == self::STATUS_SUCCESS &&
                    (!empty($collection['getchanges']) ||
                     (!isset($collection['getchanges']) && !empty($collection['synckey'])))) {

                    if (!empty($collection['windowsize']) && !empty($changecount) && $changecount > $collection['windowsize']) {
                        $this->_encoder->startTag(Horde_ActiveSync::SYNC_MOREAVAILABLE, false, true);
                    }

                    if (!empty($changecount)) {
                        $exporter->setChanges($this->_collections->getCollectionChanges(), $collection);
                        $this->_encoder->startTag(Horde_ActiveSync::SYNC_COMMANDS);
                        while ($progress = $exporter->sendNextChange()) { }
                        $this->_encoder->endTag();
                    }
                }

                // Save the sync state for the next time
                if (!empty($collection['newsynckey'])) {
                    if (!empty($sync) || !empty($importer) || $collection['synckey'] == 0)  {
                        $this->_state->setNewSyncKey($collection['newsynckey']);
                        $this->_state->save();
                    } else {
                        $this->_logger->err(sprintf(
                            '[%s] Error saving %s - no state information available.',
                            $this->_procid,
                            $collection['newsynckey'])
                        );
                    }

                    // Do we need to add the new synckey to the syncCache?
                    if ($collection['newsynckey'] != $collection['synckey']) {
                        $this->_collections->addConfirmedKey($collection['newsynckey']);
                    }
                    $this->_collections->updateCollection(
                        $collection,
                        array('newsynckey' => true, 'unsetChanges' => true, 'unsetPingChangeFlag' => true)
                    );
                }
            }
            $this->_encoder->endTag();
        }

        $this->_encoder->endTag();
        $this->_encoder->endTag();

        if ($this->_device->version >= Horde_ActiveSync::VERSION_TWELVEONE) {
            if ($this->_collections->checkStaleRequest()) {
                $this->_logger->info('Changes detected in sync_cache during wait interval, exiting without updating cache.');
                return true;
            } else {
                $this->_collections->lastsyncendnormal = time();
                $this->_collections->save();
            }
        } else {
            $this->_collections->save();
        }

        return true;
    }

    /**
     * Helper method for parsing incoming SYNC_FOLDERS nodes.
     *
     */
    protected function _parseSyncFolders()
    {
        while ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_FOLDER)) {
            $collection = $this->_collections->getNewCollection();
            while (($folder_tag = ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_FOLDERTYPE) ? Horde_ActiveSync::SYNC_FOLDERTYPE :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_SYNCKEY) ? Horde_ActiveSync::SYNC_SYNCKEY :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_FOLDERID) ? Horde_ActiveSync::SYNC_FOLDERID :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_WINDOWSIZE) ? Horde_ActiveSync::SYNC_WINDOWSIZE :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_CONVERSATIONMODE) ? Horde_ActiveSync::SYNC_CONVERSATIONMODE :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_SUPPORTED) ? Horde_ActiveSync::SYNC_SUPPORTED :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_DELETESASMOVES) ? Horde_ActiveSync::SYNC_DELETESASMOVES :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_GETCHANGES) ? Horde_ActiveSync::SYNC_GETCHANGES :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_OPTIONS) ? Horde_ActiveSync::SYNC_OPTIONS :
                   ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_COMMANDS) ? Horde_ActiveSync::SYNC_COMMANDS :
                   -1))))))))))) != -1) {

                switch ($folder_tag) {
                case Horde_ActiveSync::SYNC_FOLDERTYPE:
                    // Not sent in 12.1 requests.
                    $collection['class'] = $this->_decoder->getElementContent();
                    if (!$this->_decoder->getElementEndTag()) {
                        throw new Horde_ActiveSync_Exception('Protocol error');
                    }
                    break;

                case Horde_ActiveSync::SYNC_SYNCKEY:
                    $collection['synckey'] = $this->_decoder->getElementContent();
                    if (!$this->_decoder->getElementEndTag()) {
                        throw new Horde_ActiveSync_Exception('Protocol error');
                    }
                    break;

                case Horde_ActiveSync::SYNC_FOLDERID:
                    $collection['id'] = $this->_decoder->getElementContent();
                    if (!$this->_decoder->getElementEndTag()) {
                        throw new Horde_ActiveSync_Exception('Protocol error');
                    }
                    if ($this->_device->version >= Horde_ActiveSync::VERSION_TWELVEONE) {
                        $collection['class'] = $this->_collections->getCollectionClass($collection['id']);
                        // Ensure we can grab a collection class if needed,
                        // require a FOLDERSYNC if not.
                        if (empty($collection['class'])) {
                            $this->_statusCode = self::STATUS_FOLDERSYNC_REQUIRED;
                            $this->_handleGlobalSyncError();
                            return false;
                        }
                    }
                    break;

                case Horde_ActiveSync::SYNC_WINDOWSIZE:
                    $collection['windowsize'] = $this->_decoder->getElementContent();
                    if (!$this->_decoder->getElementEndTag()) {
                        $this->_statusCode = self::STATUS_PROTERROR;
                        $this->_handleError($collection);
                        return false;
                    }
                    if ($collection['windowsize'] < 1 || $collection['windowsize'] > self::MAX_WINDOW_SIZE) {
                        $this->_logger->err(sprintf(
                            '[%s] Bad windowsize sent, defaulting to 100',
                            $this->_procid));
                        $collection['windowsize'] = 100;
                    }
                    break;

                case Horde_ActiveSync::SYNC_CONVERSATIONMODE:
                    // Optional element, but if it's present with an empty value
                    // it defaults to true.
                    $collection['conversationmode'] = $this->_decoder->getElementContent();
                    if ($collection['conversationmode'] !== false && !$this->_decoder->getElementEndTag()) {
                        throw new Horde_ActiveSync_Exception('Protocol Error');
                    } elseif ($collection['conversationmode'] === false) {
                        $collection['conversationmode'] = true;
                    }

                    break;

                case Horde_ActiveSync::SYNC_SUPPORTED:
                    // Only allowed on initial sync request
                    if ($collection['synckey'] != '0') {
                        $this->_statusCode = self::STATUS_PROTERROR;
                        $this->_handleError($collection);
                        return false;
                    }
                    while (1) {
                        $el = $this->_decoder->getElement();
                        if ($el[Horde_ActiveSync_Wbxml::EN_TYPE] == Horde_ActiveSync_Wbxml::EN_TYPE_ENDTAG) {
                            break;
                        }
                        $collection['supported'][] = $el[2];
                    }
                    if (!empty($collection['supported'])) {
                        // Initial sync and we have SUPPORTED data - save it
                        if (empty($this->_device->supported)) {
                            $this->_device->supported = array();
                        }
                        $this->_device->supported[$collection['class']] = $collection['supported'];
                        $this->_state->setDeviceInfo($this->_device);
                    }
                    break;

                case Horde_ActiveSync::SYNC_DELETESASMOVES:
                    // Optional element, but if it's present with an empty value
                    // it defaults to true.
                    $collection['deletesasmoves'] = $this->_decoder->getElementContent();
                    if ($collection['deletesasmoves'] !== false && !$this->_decoder->getElementEndTag()) {
                        throw new Horde_ActiveSync_Exception('Protocol Error');
                    } elseif ($collection['deletesasmoves'] === false) {
                        $collection['deletesasmoves'] = true;
                    }
                    break;

                case Horde_ActiveSync::SYNC_GETCHANGES:
                    // Optional element, but if it's present with an empty value
                    // it defaults to true. Also, not sent by EAS 14
                    $collection['getchanges'] = $this->_decoder->getElementContent();
                    if ($collection['getchanges'] !== false && !$this->_decoder->getElementEndTag()) {
                        throw new Horde_ActiveSync_Exception('Protocol Error');
                    } elseif ($collection['getchanges'] === false) {
                        $collection['getchanges'] = true;
                    }
                    break;

                case Horde_ActiveSync::SYNC_OPTIONS:
                    $this->_parseSyncOptions($collection);
                    break;

                case Horde_ActiveSync::SYNC_COMMANDS:
                    if (!$this->_parseSyncCommands($collection)) {
                        return false;
                    }
                }
            }

            if (!$this->_decoder->getElementEndTag()) {
                $this->_statusCode = self::STATUS_PROTERROR;
                $this->_handleError($collection);
                return false;
            }

            $this->_collections->addCollection($collection);
            if (!empty($collection['importedchanges'])) {
                $this->_collections->importedChanges = true;
            }
            if ($collection['fetchids']) {
                $this->_fetchids = true;
            }
            if ($this->_collections->collectionExists($collection['id']) && !empty($collection['windowsize'])) {
                $this->_collections->updateWindowSize($collection['id'], $collection['windowsize']);
            }
        }

        if (!$this->_decoder->getElementEndTag()) {
            $this->_logger->err('Parsing Error');
            return false;
        }

        return true;
    }

    /**
     * Handle incoming SYNC nodes
     *
     * @param array $collection  The current collection array.
     *
     * @return boolean
     */
    protected function _parseSyncCommands(&$collection)
    {
        // Some broken clients send SYNC_COMMANDS with a synckey of 0.
        // This is a violation of the spec, and could lead to all kinds
        // of data integrity issues.
        if (empty($collection['synckey'])) {
            $this->_logger->err(sprintf(
                '[%s] Attempting a SYNC_COMMANDS, but device failed to send synckey. Ignoring.',
                $this->_procid));
        }

        try {
            $this->_collections->initCollectionState($collection);
        } catch (Horde_ActiveSync_Exception_StateGone $e) {
            $this->_logger->err('State not found sending STATUS_KEYMISM');
            $this->_statusCode = self::STATUS_KEYMISM;
            $this->_handleError($collection);
            return false;
        } catch (Horde_ActiveSync_Exception $e) {
            $this->_statusCode = self::STATUS_SERVERERROR;
            $this->_handleGlobalSyncError();
            return false;
        }

        // Configure importer with last state
        if (!empty($collection['synckey'])) {
            $importer = $this->_getImporter();
            $importer->init(
                $this->_state, $collection['id'], $collection['conflict']);
        }
        $nchanges = 0;
        while (1) {
            // MODIFY or REMOVE or ADD or FETCH
            $element = $this->_decoder->getElement();
            if ($element[Horde_ActiveSync_Wbxml::EN_TYPE] != Horde_ActiveSync_Wbxml::EN_TYPE_STARTTAG) {
                $this->_decoder->_ungetElement($element);
                break;
            }
            $nchanges++;

            if ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_SERVERENTRYID)) {
                $serverid = $this->_decoder->getElementContent();
                if (!$this->_decoder->getElementEndTag()) { // end serverid
                    $this->_statusCode = self::STATUS_PROTERROR;
                    $this->_handleGlobalSyncError();
                    $this->_logger->err('Parsing Error');
                    return false;
                }
            } else {
                $serverid = false;
            }

            if ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_CLIENTENTRYID)) {
                $clientid = $this->_decoder->getElementContent();
                if (!$this->_decoder->getElementEndTag()) { // end clientid
                    $this->_statusCode = self::STATUS_PROTERROR;
                    $this->_handleGlobalSyncError();
                    $this->_logger->err('PARSING ERROR');
                    return false;
                }
            } else {
                $clientid = false;
            }

            // Create Message object from messages passed from PIM
            if ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_DATA)) {
                switch ($collection['class']) {
                case Horde_ActiveSync::CLASS_EMAIL:
                    $appdata = Horde_ActiveSync::messageFactory('Mail');
                    $appdata->decodeStream($this->_decoder);
                    break;
                case Horde_ActiveSync::CLASS_CONTACTS:
                    $appdata = Horde_ActiveSync::messageFactory('Contact');
                    $appdata->decodeStream($this->_decoder);
                    break;
                case Horde_ActiveSync::CLASS_CALENDAR:
                    $appdata = Horde_ActiveSync::messageFactory('Appointment');
                    $appdata->decodeStream($this->_decoder);
                    break;
                case Horde_ActiveSync::CLASS_TASKS:
                    $appdata = Horde_ActiveSync::messageFactory('Task');
                    $appdata->decodeStream($this->_decoder);
                    break;
                case Horde_ActiveSync::CLASS_NOTES:
                    $appdata = Horde_ActiveSync::messageFactory('Note');
                    $appdata->decodeStream($this->_decoder);
                    break;
                }
                if (!$this->_decoder->getElementEndTag()) {
                    // End application data
                    $this->_statusCode = self::STATUS_PROTERROR;
                    $this->_handleGlobalSyncError();
                    return false;
                }
            }

            if (!empty($collection['synckey'])) {
                switch ($element[Horde_ActiveSync_Wbxml::EN_TAG]) {
                case Horde_ActiveSync::SYNC_MODIFY:
                    if (isset($appdata)) {
                        $importer->importMessageChange(
                            $serverid, $appdata, $this->_device, false);
                        $collection['importedchanges'] = true;
                    }
                    break;

                case Horde_ActiveSync::SYNC_ADD:
                    if (isset($appdata)) {
                        $id = $importer->importMessageChange(
                            false, $appdata, $this->_device, $clientid);
                        if ($clientid && $id) {
                            $collection['clientids'][$clientid] = $id;
                            $collection['importedchanges'] = true;
                        }
                    }
                    break;

                case Horde_ActiveSync::SYNC_REMOVE:
                    $collection['removes'][] = $serverid;
                    break;

                case Horde_ActiveSync::SYNC_FETCH:
                    $collection['fetchids'][] = $serverid;
                    break;
                }
            }

            if (!$this->_decoder->getElementEndTag()) {
                // end change/delete/move
                $this->_statusCode = self::STATUS_PROTERROR;
                $this->_handleGlobalSyncError();
                $this->_logger->err('PARSING ERROR');
                return false;
            }
        }

        // Do all the SYNC_REMOVE requests at once
        if (!empty($collection['removes']) &&
            !empty($collection['synckey'])) {
            if (!empty($collection['deletesasmoves']) && $folderid = $this->_driver->getWasteBasket($collection['class'])) {
                $results = $importer->importMessageMove($collection['removes'], $folderid);
            } else {
                $results = $importer->importMessageDeletion($collection['removes'], $collection['class']);
                if (is_array($results['results'])) {
                    $results['results'] = $results;
                    $results['missing'] = array_diff($collection['removes'], $results['results']);
                }
            }
            if (!empty($results['missing'])) {
                $collection['missing'] = $results['missing'];
            }
            unset($collection['removes']);
            $collection['importedchanges'] = true;
        }

        $this->_logger->info(sprintf(
            '[%s] Processed %d incoming changes',
            $this->_procid,
            $nchanges));

        if (!$this->_decoder->getElementEndTag()) {
            // end commands
            $this->_statusCode = self::STATUS_PROTERROR;
            $this->_handleGlobalSyncError();
            $this->_logger->err('PARSING ERROR');
            return false;
        }

        return true;
    }

    /**
     * Helper method to handle incoming OPTIONS nodes.
     *
     * @param array $collection  The current collection array.
     */
    public function _parseSyncOptions(&$collection)
    {
        while(1) {
            if ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_FILTERTYPE)) {
                $collection['filtertype'] = $this->_decoder->getElementContent();
                if (!$this->_decoder->getElementEndTag()) {
                    $this->_statusCode = self::STATUS_PROTERROR;
                    $this->_handleError($collection);
                    exit;
                }
            }
            if ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_TRUNCATION)) {
                $collection['truncation'] = $this->_decoder->getElementContent();
                if (!$this->_decoder->getElementEndTag()) {
                    $this->_statusCode = self::STATUS_PROTERROR;
                    $this->_handleError($collection);
                    exit;
                }
            }
            if ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_RTFTRUNCATION)) {
                $collection['rtftruncation'] = $this->_decoder->getElementContent();
                if (!$this->_decoder->getElementEndTag()) {
                    $this->_statusCode = self::STATUS_PROTERROR;
                    $this->_handleError($collection);
                    exit;
                }
            }

            if ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_MIMESUPPORT)) {
                $this->_mimeSupport($collection);
            }
            if ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_MIMETRUNCATION)) {
                $collection['mimetruncation'] = $this->_decoder->getElementContent();
                if (!$this->_decoder->getElementEndTag()) {
                    $this->_statusCode = self::STATUS_PROTERROR;
                    $this->_handleError($collection);
                    exit;
                }
            }

            if ($this->_decoder->getElementStartTag(Horde_ActiveSync::SYNC_CONFLICT)) {
                $collection['conflict'] = $this->_decoder->getElementContent();
                if (!$this->_decoder->getElementEndTag()) {
                    $this->_statusCode = self::STATUS_PROTERROR;
                    $this->_handleError;
                    exit;
                }
            }

            // BODYPREFERENCE
            if ($this->_decoder->getElementStartTag(Horde_ActiveSync::AIRSYNCBASE_BODYPREFERENCE)) {
                $this->_bodyPrefs($collection);
            }

            // EAS 14.1
            if ($this->_device->version >= Horde_ActiveSync::VERSION_FOURTEENONE) {
                if ($this->_decoder->getElementStartTag(Horde_ActiveSync::RM_SUPPORT)) {
                    $this->_rightsManagement($collection);
                }
                if ($this->_decoder->getElementStartTag(Horde_ActiveSync::AIRSYNCBASE_BODYPARTPREFERENCE)) {
                    $this->_bodyPartPrefs($collection);
                }
            }

            $e = $this->_decoder->peek();
            if ($e[Horde_ActiveSync_Wbxml::EN_TYPE] == Horde_ActiveSync_Wbxml::EN_TYPE_ENDTAG) {
                $this->_decoder->getElementEndTag();
                break;
            }
        }
    }

    /**
     * Helper for sending error status results.
     *
     * @param boolean $limit  Send the SYNC_LIMIT error if true.
     */
    protected function _handleGlobalSyncError($limit = false)
    {
        $this->_encoder->StartWBXML();
        $this->_encoder->startTag(Horde_ActiveSync::SYNC_SYNCHRONIZE);
        $this->_encoder->startTag(Horde_ActiveSync::SYNC_STATUS);
        $this->_encoder->content($this->_statusCode);
        $this->_encoder->endTag();
        if ($limit !== false) {
            $this->_encoder->startTag(Horde_ActiveSync::SYNC_LIMIT);
            $this->_encoder->content($limit);
            $this->_encoder->endTag();
        }
        $this->_encoder->endTag();
    }

    /**
     * Helper for handling sync errors
     *
     * @param array $collection
     */
    protected function _handleError(array $collection)
    {
        $this->_encoder->startWBXML();
        $this->_encoder->startTag(Horde_ActiveSync::SYNC_SYNCHRONIZE);

        $this->_encoder->startTag(Horde_ActiveSync::SYNC_FOLDERS);

        // Get new synckey if needed
        if ($this->_statusCode == self::STATUS_KEYMISM ||
            !empty($collection['importedchanges']) ||
            !empty($collection['getchanges']) ||
            $collection['synckey'] == '0') {

            $collection['newsynckey'] = Horde_ActiveSync_State_Base::getNewSyncKey(($this->_statusCode == self::STATUS_KEYMISM) ? 0 : $collection['synckey']);
            if ($collection['synckey'] != '0') {
                $this->_state->removeState(array('synckey' => $collection['synckey']));
            }
        }

        $this->_encoder->startTag(Horde_ActiveSync::SYNC_FOLDER);

        $this->_encoder->startTag(Horde_ActiveSync::SYNC_FOLDERTYPE);
        $this->_encoder->content($collection['class']);
        $this->_encoder->endTag();

        $this->_encoder->startTag(Horde_ActiveSync::SYNC_SYNCKEY);
        if (!empty($collection['newsynckey'])) {
            $this->_encoder->content($collection['newsynckey']);
        } else {
            $this->_encoder->content($collection['synckey']);
        }
        $this->_encoder->endTag();

        $this->_encoder->startTag(Horde_ActiveSync::SYNC_FOLDERID);
        $this->_encoder->content($collection['id']);
        $this->_encoder->endTag();

        $this->_encoder->startTag(Horde_ActiveSync::SYNC_STATUS);
        $this->_encoder->content($this->_statusCode);
        $this->_encoder->endTag();

        $this->_encoder->endTag(); // Horde_ActiveSync::SYNC_FOLDER
        $this->_encoder->endTag();
        $this->_encoder->endTag();
    }

}
