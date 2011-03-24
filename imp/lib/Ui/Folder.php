<?php
/**
 * This class provides a place to store common code shared among IMP's various
 * UI views for folder manipulation.
 *
 * Copyright 2011 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author   Michael Slusarz <slusarz@horde.org>
 * @category Horde
 * @license  http://www.fsf.org/copyleft/gpl.html GPL
 * @package  IMP
 */
class IMP_Ui_Folder
{
    /**
     * Download folder(s) into a MBOX file.
     *
     * @param array $flist  The folder list.
     * @param boolean $zip  Compress with zip?
     *
     * @throws Horde_Exception
     */
    public function downloadMbox($flist, $zip = false)
    {
        global $browser, $injector;

        $mbox = $injector->getInstance('IMP_Folder')->generateMbox($flist);

        if ($zip) {
            $horde_compress = Horde_Compress::factory('zip');
            try {
                $data = $horde_compress->compress(array(array(
                    'data' => $mbox,
                    'name' => reset($flist) . '.mbox'
                )), array(
                    'stream' => true
                ));
                fclose($mbox);
            } catch (Horde_Exception $e) {
                fclose($mbox);
                throw $e;
            }

            fseek($data, 0, SEEK_END);

            $browser->downloadHeaders(reset($flist) . '.zip', 'application/zip', false, ftell($data));
        } else {
            $data = $mbox;
            fseek($data, 0, SEEK_END);
            $browser->downloadHeaders(reset($flist) . '.mbox', null, false, ftell($data));
        }

        rewind($data);
        fpassthru($data);
        exit;
    }

    /**
     * Import a MBOX file into a mailbox.
     *
     * @param string $mbox       The mailbox name to import into.
     * @param string $form_name  The form field name that contains the MBOX
     *                           data.
     *
     * @return string  Notification message.
     * @throws Horde_Exception
     */
    public function importMbox($mbox, $form_name)
    {
        global $browser, $injector;

        $browser->wasFileUploaded($form_name, _("mailbox file"));
        $res = $injector
            ->getInstance('IMP_Folder')
            ->importMbox(Horde_String::convertCharset($mbox, 'UTF-8', 'UTF7-IMAP'),
                         $_FILES[$form_name]['tmp_name'],
                         $_FILES[$form_name]['type']);
        $mbox_name = basename(Horde_Util::dispelMagicQuotes($_FILES[$form_name]['name']));

        if ($res === false) {
            throw new IMP_Exception(sprintf(_("There was an error importing %s."), $mbox_name));
        }

        return sprintf(_("Imported %d messages from %s."), $res, $mbox_name);
    }

}
