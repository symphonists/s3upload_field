<?php

if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');

require_once(TOOLKIT . '/fields/field.upload.php');
require_once(EXTENSIONS .'/s3upload_field/lib/class.s3Facade.php');

class FieldS3Upload extends FieldUpload
{

    /**
     * @var S3Facade
     */
    private $s3;

    public function __construct()
    {
        parent::__construct();
        $this->_name = 'S3 Upload';
        $this->_driver = Symphony::ExtensionManager()->create('s3upload_field');

        $this->s3 = new S3Facade(
            $this->_driver->getAmazonS3AccessKeyId(),
            $this->_driver->getAmazonS3SecretAccessKey()
        );
    }

    /*-------------------------------------------------------------------------
        Setup:
    -------------------------------------------------------------------------*/

    public function createTable()
    {
        return Symphony::Database()->query(
        "CREATE TABLE IF NOT EXISTS `tbl_entries_data_" . $this->get('id') . "` (
            `id` int(11) unsigned NOT NULL auto_increment,
            `entry_id` int(11) unsigned NOT NULL,
            `file` varchar(255) default NULL,
            `size` int(11) unsigned NULL,
            `mimetype` varchar(255) default NULL,
            `meta` varchar(255) default NULL,
            PRIMARY KEY  (`id`),
            KEY `entry_id` (`entry_id`),
            UNIQUE KEY `file` (`file`),
            KEY `mimetype` (`mimetype`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;"
        );
    }

    /*-------------------------------------------------------------------------
        Utilities:
    -------------------------------------------------------------------------*/

    public function entryDataCleanup($entry_id, $data = null)
    {
        if ($this->get('remove_from_bucket') == true) {
            try {
                if (!is_null($data['file']))
                    $this->s3->deleteObject($this->get('bucket'), basename($data['file']));
            }
            catch (Exception $e) {  }
        }

        Field::entryDataCleanup($entry_id, $data);

        return true;
    }

    private function getUrl($file)
    {
        $protocol = ($this->get('ssl_option') == true ? 'https://' : 'http://');

        if ($this->get('cname') == '') {
            $url = $protocol . "s3.amazonaws.com/" . $this->get('bucket') . "/" . $file;
        }
        else {
            $url = $protocol . $this->get('cname') . "/" . $file;
        }
        return $url;
    }

    private function getUniqueFilename(&$file)
    {
        ## since uniqid() is 13 bytes, the unique filename will be limited to ($crop+1+13) characters;
        $crop  = '30';
        $file = preg_replace("/(.*)(\.[^\.]+)/e", "substr('$1', 0, $crop).'-'.uniqid().'$2'", $file);
    }

    /*-------------------------------------------------------------------------
        Settings:
    -------------------------------------------------------------------------*/

    public function displaySettingsPanel(XMLElement &$wrapper, $errors = null)
    {
        Field::displaySettingsPanel($wrapper, $errors);
        $options = array();

        $div = new XMLElement('div', NULL, array('class' => 'two columns'));

        try {
            $buckets = $this->s3->listBuckets();

            $options = array();
            if(!empty($buckets) && is_array($buckets)){
                foreach($buckets as $b) {
                    $bucketName = $b['Name'];
                    $options[] = array($bucketName, ($this->get('bucket') == $bucketName), $bucketName);
                }
            }
        }
        catch (Exception $e){}

        $label = Widget::Label(__('Bucket'));
        $label->setAttribute('class', 'column');
        $label->appendChild(Widget::Select('fields['.$this->get('sortorder').'][bucket]', $options));

        if(isset($errors['bucket'])) {
            $div->appendChild(Widget::Error($label, $errors['bucket']));
        }
        else {
            $div->appendChild($label);
        }

        $label = Widget::Label(__('CNAME'));
        $label->setAttribute('class', 'column');
        $label->appendChild(new XMLElement('i', __('Optional')));
        $label->appendChild(Widget::Input('fields['.$this->get('sortorder').'][cname]', htmlspecialchars($this->get('cname'))));

        if (isset($errors['cname'])) {
            $div->appendChild(Widget::Error($label, $errors['cname']));
        }
        else {
            $div->appendChild($label);
        }

        $wrapper->appendChild($div);

        $this->buildValidationSelect($wrapper, $this->get('validator'), 'fields['.$this->get('sortorder').'][validator]', 'upload');

        $div = new XMLElement('div', NULL, array('class' => 'two columns'));
        $setting = new XMLElement('label', '<input name="fields[' . $this->get('sortorder') . '][ssl_option]" value="1" type="checkbox"' . (($this->get('ssl_option') != 0 || $this->get('ssl_option') == null) ? ' checked="checked"' : '') . '/> ' . __('Build links using https://'), array('class' => 'column'));
        $div->appendChild($setting);

        $setting = new XMLElement('label', '<input name="fields[' . $this->get('sortorder') . '][unique_filename]" value="1" type="checkbox"' . (($this->get('unique_filename') != 0 || $this->get('unique_filename') == null) ? ' checked="checked"' : '') . '/> ' . __('Automatically give the files a unique filename'), array('class' => 'column'));
        $div->appendChild($setting);

        $wrapper->appendChild($div);
        $div = new XMLElement('div', NULL, array('class' => 'two columns'));

        $setting = new XMLElement('label', '<input name="fields[' . $this->get('sortorder') . '][remove_from_bucket]" value="1" type="checkbox"' . (($this->get('remove_from_bucket') != 0 || $this->get('remove_from_bucket') == null) ? ' checked="checked"' : '') . '/> ' . __('Remove file from S3 upon deletion of entry'), array('class' => 'column'));
        $div->appendChild($setting);
        $this->appendRequiredCheckbox($div);

        $wrapper->appendChild($div);
        $div = new XMLElement('div', NULL, array('class' => 'two columns'));

        $this->appendShowColumnCheckbox($div);

        $wrapper->appendChild($div);
    }

    public function checkFields(array &$errors, $checkForDuplicates=true)
    {
        if(!is_array($errors)) $errors = array();
        if($this->get('cname') != '' && !preg_match('/([.]+)/i',$this->get('cname'))) {
            $errors['cname'] = __('This is an invalid CNAME. Don\'t include the protocol (http/s).');
        }

        // Check if a related section has been selected
        if($this->get('bucket') == '') {
            $errors['bucket'] = __('You have not setup your S3 Access keys yet. Please do so <a href="'.SYMPHONY_URL.'/system/preferences/">here</a>.');
        }

        return Field::checkFields($errors, $checkForDuplicates);
    }

    public function commit()
    {
        if(!Field::commit()) return false;

        $id = $this->get('id');

        if($id === false) return false;

        $fields = array();

        $fields['field_id'] = $id;
        $fields['bucket'] = $this->get('bucket');
        $fields['cname'] = $this->get('cname');
        $fields['remove_from_bucket'] = ($this->get('remove_from_bucket') == '' ? '0' : '1');
        $fields['unique_filename'] = ($this->get('unique_filename') == '' ? '0' : '1');
        $fields['ssl_option'] = ($this->get('ssl_option') == '' ? '0' : '1');
        $fields['validator'] = ($fields['validator'] == 'custom' ? NULL : $this->get('validator'));

        return FieldManager::saveSettings($id, $fields);
    }

    /*-------------------------------------------------------------------------
        Publish:
    -------------------------------------------------------------------------*/

    public function displayPublishPanel(XMLElement &$wrapper, $data = null, $flagWithError = null, $fieldnamePrefix = null, $fieldnamePostfix = null, $entry_id = null)
    {
        $label = Widget::Label($this->get('label'));
        $class = 'file';
        $label->setAttribute('class', $class);
        if($this->get('required') != 'yes') $label->appendChild(new XMLElement('i', __('Optional')));

        $span = new XMLElement('span');
        $span->setAttribute('class','frame');
        if($data['file']) $span->appendChild(Widget::Anchor($this->getUrl($data['file']), $this->getUrl($data['file'])));

        $span->appendChild(Widget::Input('fields'.$fieldnamePrefix.'['.$this->get('element_name').']'.$fieldnamePostfix, $data['file'], ($data['file'] ? 'hidden' : 'file')));

        $label->appendChild($span);

        if($flagWithError != NULL) $wrapper->appendChild(Widget::Error($label, $flagWithError));
        else $wrapper->appendChild($label);
    }

    public function checkPostFieldData($data, &$message, $entry_id=NULL)
    {
        $message = NULL;

        if ($this->s3->doesBucketExist($this->get('bucket')) == false) {

            $message = __('The bucket %s doesn\'t exist! Please update this section.', array($this->get('bucket')));
            return self::__INVALID_FIELDS__;
        }

        if(empty($data) || (isset($data['error']) && $data['error'] == UPLOAD_ERR_NO_FILE)) {
            if($this->get('required') == 'yes'){
                $message = __("'%s' is a required field.", array($this->get('label')));
                return self::__MISSING_FIELDS__;
            }

            return self::__OK__;
        }

        ## Its not an array, so just retain the current data and return
        if(!is_array($data)) return self::__OK__;

        if($data['error'] != UPLOAD_ERR_NO_FILE && $data['error'] != UPLOAD_ERR_OK){

            switch($data['error']){

                case UPLOAD_ERR_INI_SIZE:
                $message = __('File chosen in "%1$s" exceeds the maximum allowed upload size of %2$s specified by your host.', array($this->get('label'), (is_numeric(ini_get('upload_max_filesize')) ? General::formatFilesize(ini_get('upload_max_filesize')) : ini_get('upload_max_filesize'))));
                break;

                case UPLOAD_ERR_FORM_SIZE:
                $message = __('File chosen in "%1$s" exceeds the maximum allowed upload size of %2$s, specified by Symphony.', array($this->get('label'), General::formatFilesize(Symphony::Configuration()->get('max_upload_size', 'admin'))));
                break;

                case UPLOAD_ERR_PARTIAL:
                $message = __("File chosen in '%s' was only partially uploaded due to an error.", array($this->get('label')));
                break;

                case UPLOAD_ERR_NO_TMP_DIR:
                $message = __("File chosen in '%s' was only partially uploaded due to an error.", array($this->get('label')));
                break;

                case UPLOAD_ERR_CANT_WRITE:
                $message = __("Uploading '%s' failed. Could not write temporary file to disk.", array($this->get('label')));
                break;

                case UPLOAD_ERR_EXTENSION:
                $message = __("Uploading '%s' failed. File upload stopped by extension.", array($this->get('label')));
                break;

            }

            return self::__ERROR_CUSTOM__;

        }

        ## Sanitize the filename
        $data['name'] = Lang::createFilename($data['name']);

        ## uniq the filename
        if ($this->get('unique_filename') == true && isset($data['name'])) $this->getUniqueFilename($data['name']);

        if($this->get('validator') != NULL){
            $rule = $this->get('validator');

            if(!General::validateString($data['name'], $rule)){
                $message = __("File chosen in '%s' does not match allowable file types for that field.", array($this->get('label')));
                return self::__INVALID_FIELDS__;
            }
        }

        ## check if the file exists since we can't check directly through the s3 library, the file field is unique
        $row = Symphony::Database()->fetchRow(0, sprintf("
            SELECT * FROM `tbl_entries_data_%d` WHERE `file` = '%s'
        ",
            $this->get('id'),
            $data['name']
        ));

        if (isset($row['file'])) {
            $message = __('A file with the name %1$s already exists at that bucket. Please rename the file first, or choose another.', array($data['name']));
            return self::__INVALID_FIELDS__;
        }

        return self::__OK__;
    }

    public function processRawFieldData($data, &$status, &$message=null, $simulate=false, $entry_id=NULL)
    {

        $status = self::__OK__;

        //fixes bug where files are deleted, but their database entries are not.
        if($data === NULL){
            return array(
                'file' => NULL,
                'mimetype' => NULL,
                'size' => NULL,
                'meta' => NULL
            );
        }

        ## Its not an array, so just retain the current data and return (the case where we're not uploading a new file)
        if(!is_array($data)){
            $result = array(
                'file' => $data,
                'mimetype' => NULL,
                'size' => NULL,
                'meta' => NULL
            );

            // Grab the existing entry data to preserve the MIME type and size information
            if(isset($entry_id) && !is_null($entry_id)){
                $row = Symphony::Database()->fetchRow(0, sprintf(
                    "SELECT `file`, `mimetype`, `size`, `meta` FROM `tbl_entries_data_%d` WHERE `entry_id` = %d",
                    $this->get('id'),
                    $entry_id
                ));
                if(!empty($row)){
                    $result = $row;
                }
            }

            return $result;
        }

        if ($this->get('unique_filename') == true && isset($data['name'])) {
            $this->getUniqueFilename($data['name']);
        }

        // Editing an entry: Where we're uploading a new file and getting rid of the old one
        if (is_null($entry_id) === false) {
            $row = Symphony::Database()->fetchRow(0, sprintf("
                SELECT * FROM `tbl_entries_data_%d` WHERE `entry_id` = %d LIMIT 1
            ",
                $this->get('id'),
                $entry_id
            ));
            $existing_file = $row['file'];

            if (
                (!is_null($existing_file) && strtolower($existing_file) != strtolower($data['file']))
                || ($data['error'] == UPLOAD_ERR_NO_FILE && !is_null($existing_file))
            ) {
                $this->s3->deleteObject($this->get('bucket'), basename($existing_file));
            }
        }

        if($data['error'] == UPLOAD_ERR_NO_FILE || $data['error'] != UPLOAD_ERR_OK) {
            return false;
        }

        // Sanitize the filename
        $data['name'] = Lang::createFilename($data['name']);

        ## Upload the new file
        $options = array(
            'ACL' => 'public-read',
            'ContentType' => $data['type']
        );

        if ($this->_driver->getCacheControl() != false) {
            $options['CacheControl'] = "max-age=".$this->_driver->getCacheControl();
        }

        try {
            $this->s3->putObject(
                $this->get('bucket'),
                $data['name'],
                $data['tmp_name'],
                $options
            );
        }
        catch (Exception $e) {
            $status = self::__ERROR_CUSTOM__;
            $message = __(
                __('There was an error while trying to upload the file %s to the bucket %s.'),
                array(
                    '<code>' . $data['name'] . '</code>',
                    '<code>'. $this->get('bucket') . '</code>'
                )
            );

            return array(
                'file' => NULL,
                'mimetype' => NULL,
                'size' => NULL,
                'meta' => NULL
            );
        }

        $status = self::__OK__;

        // Get the mimetype, don't trust the browser. RE: #1609
        $data['type'] = General::getMimeType($data['tmp_name']);

        // all we need is the path and name, the domain is abstracted depending on whether or not it has a cname
        return array(
            'file' => $data['name'],
            'size' => $data['size'],
            'mimetype' => $data['type'],
            'meta' => serialize(parent::getMetaInfo($data['tmp_name'], $data['type']))
        );
    }

    /*-------------------------------------------------------------------------
        Output:
    -------------------------------------------------------------------------*/

    public function appendFormattedElement(XMLElement &$wrapper, $data, $encode = false, $mode = null, $entry_id = null)
    {
        // It is possible an array of null data will be passed in. Check for this.
        if (!is_array($data) || !isset($data['file']) || is_null($data['file'])) {
            return;
        }

        $item = new XMLElement($this->get('element_name'));
        $url = $this->getUrl($data['file']);
        $filesize = $data['size'];

        $item->setAttributeArray(array(
            'size' =>   !is_null($filesize) ? General::formatFilesize($filesize) : 'unknown',
            'bytes' => !is_null($filesize) ? $filesize : 'unknown',
            'url' => $url,
            'type' => $data['mimetype']
        ));
        $item->appendChild(new XMLElement('filename', General::sanitize(basename($data['file']))));
        // These are 'deprecated', should use the attributes
        $item->appendChild(new XMLElement('size', $data['size']));
        $item->appendChild(new XMLElement('mimetype', $data['mimetype']));

        $m = unserialize($data['meta']);

        if(is_array($m) && !empty($m)){
            $item->appendChild(new XMLElement('meta', NULL, $m));
        }

        $wrapper->appendChild($item);
    }

    public function prepareTableValue($data, XMLElement $link = null, $entry_id = null)
    {
        if(!$file = $data['file']) return NULL;

        if($link){
            $link->setValue(basename($file));
            return $link->generate();
        }

        else{
            $link = Widget::Anchor($this->getUrl($file),$this->getUrl($file));
            return $link->generate();
        }
    }
}
