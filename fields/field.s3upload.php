<?php

if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');

require_once(TOOLKIT . '/fields/field.upload.php');
require_once(EXTENSIONS .'/s3upload_field/lib/S3.php');

class FieldS3Upload extends FieldUpload {

	private $S3;
	public function __construct(&$parent){
		parent::__construct($parent);
		$this->_name = 'S3 Upload';
		$this->_driver = $this->_engine->ExtensionManager->create('s3upload_field');

		$this->S3 = new S3($this->_driver->getAmazonS3AccessKeyId(), $this->_driver->getAmazonS3SecretAccessKey());


	}


	public function displaySettingsPanel(&$wrapper, $errors = null) {
		field::displaySettingsPanel($wrapper, $errors);

		// ## bucket Folder
		// $ignore = array(
		// 	'/workspace/events',
		// 	'/workspace/data-sources',
		// 	'/workspace/text-formatters',
		// 	'/workspace/pages',
		// 	'/workspace/utilities'
		// 	);
		// $directories = General::listDirStructure(WORKSPACE, null, 'asc', DOCROOT, $ignore);

		$label = Widget::Label(__('Bucket'));

		try {
			$buckets = $this->S3->listBuckets();				
		}
		catch (Exception $e){
		}

		$options = array();
		if(!empty($buckets) && is_array($buckets)){
			foreach($buckets as $b) {
				$options[] = array($b, ($this->get('bucket') == $b), $b);
			}	
		}

		$label->appendChild(Widget::Select('fields['.$this->get('sortorder').'][bucket]', $options));

		$div = new XMLElement('div', NULL, array('class' => 'group'));


		if(isset($errors['bucket'])) {
			$div->appendChild(Widget::wrapFormElementWithError($label, $errors['bucket']));	
		}
		else {
			$div->appendChild($label);
		}			
		
		$label = Widget::Label(__('CNAME'));
		$label->appendChild(new XMLElement('i', __('Optional')));
		$label->appendChild(Widget::Input('fields['.$this->get('sortorder').'][cname]', htmlspecialchars($this->get('cname'))));

		
		if (isset($errors['cname'])) {
			$div->appendChild(Widget::wrapFormElementWithError($label, $errors['cname']));
		}
		else {
			$div->appendChild($label);
		}

		
		$wrapper->appendChild($div);

		$div = new XMLElement('div', NULL, array('class' => 'group'));

		$this->buildValidationSelect($wrapper, $this->get('validator'), 'fields['.$this->get('sortorder').'][validator]', 'upload');

		$setting = new XMLElement('label', '<input name="fields[' . $this->get('sortorder') . '][ssl_option]" value="1" type="checkbox"' . (($this->get('ssl_option') != 0 || $this->get('ssl_option') == null) ? ' checked="checked"' : '') . '/> ' . __('Build links using https://'));
		$div->appendChild($setting);


		$setting = new XMLElement('label', '<input name="fields[' . $this->get('sortorder') . '][unique_filename]" value="1" type="checkbox"' . (($this->get('unique_filename') != 0 || $this->get('unique_filename') == null) ? ' checked="checked"' : '') . '/> ' . __('Automatically give the files a unique filename'));
		$div->appendChild($setting);


		$wrapper->appendChild($div);
		$div = new XMLElement('div', NULL, array('class' => 'group'));

		$setting = new XMLElement('label', '<input name="fields[' . $this->get('sortorder') . '][remove_from_bucket]" value="1" type="checkbox"' . (($this->get('remove_from_bucket') != 0 || $this->get('remove_from_bucket') == null) ? ' checked="checked"' : '') . '/> ' . __('Remove file from S3 upon deletion of entry'));
		$div->appendChild($setting);
		$this->appendRequiredCheckbox($div);

		$wrapper->appendChild($div);
		$div = new XMLElement('div', NULL, array('class' => 'group'));

		$this->appendShowColumnCheckbox($div);

		$wrapper->appendChild($div);


	}

	public function displayPublishPanel(&$wrapper, $data=NULL, $flagWithError=NULL, $fieldnamePrefix=NULL, $fieldnamePostfix=NULL){


		$label = Widget::Label($this->get('label'));
		$class = 'file';
		$label->setAttribute('class', $class);
		if($this->get('required') != 'yes') $label->appendChild(new XMLElement('i', __('Optional')));

		$span = new XMLElement('span');
		$span->setAttribute('class','frame');
		if($data['file']) $span->appendChild(Widget::Anchor($this->getUrl($data['file']), $this->getUrl($data['file'])));

		$span->appendChild(Widget::Input('fields'.$fieldnamePrefix.'['.$this->get('element_name').']'.$fieldnamePostfix, $data['file'], ($data['file'] ? 'hidden' : 'file')));

		$label->appendChild($span);

		if($flagWithError != NULL) $wrapper->appendChild(Widget::wrapFormElementWithError($label, $flagWithError));
		else $wrapper->appendChild($label);

	}

	public function prepareTableValue($data, XMLElement $link=NULL){
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

	public function processRawFieldData($data, &$status, $simulate=false, $entry_id=NULL){

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

			$status = self::__OK__;

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


		if ($this->get('unique_filename') == true && isset($data['name'])) $this->getUniqueFilename($data['name']);

		// Editing an entry: Where we're uploading a new file and getting rid of the old one
		if($entry_id){
			$row = $this->Database->fetchRow(0, "SELECT * FROM `tbl_entries_data_".$this->get('id')."` WHERE `entry_id` = '$entry_id' LIMIT 1");
			$existing_file = $row['file'];
			if ((!is_null($existing_file) && strtolower($existing_file) != strtolower($data['file'])) || ($data['error'] == UPLOAD_ERR_NO_FILE && !is_null($existing_file))) {
				$this->S3->deleteObject($this->get('bucket'), basename($existing_file));
			}

		}

		if($data['error'] == UPLOAD_ERR_NO_FILE || $data['error'] != UPLOAD_ERR_OK) return;


		

		## Upload the new file
		$headers = array('Content-Type' => $data['type']);
		if ($this->_driver->getCacheControl() != false) $headers['Cache-Control'] = "max-age=".$this->_driver->getCacheControl();
		
		try {
			$this->S3->putObject(
				$this->S3->inputResource(fopen($data['tmp_name'], 'rb'), filesize($data['tmp_name'])), 
				$this->get('bucket'), 
				$data['name'], 
				'public-read',
				array(),
				$headers
			);
		}
		catch (Exception $e) {
			$status = self::__ERROR_CUSTOM__;
			return array(
				'file' => NULL,
				'mimetype' => NULL,
				'size' => NULL,
				'meta' => NULL
				);
		}

		$status = self::__OK__;




		## If browser doesn't send MIME type (e.g. .flv in Safari)
		if (strlen(trim($data['type'])) == 0){
			$data['type'] = 'unknown';
		}


		// all we need is the path and name, the domain is abstracted depending on whether or not it has a cname
		return array(
			'file' => $data['name'],
			'size' => $data['size'],
			'mimetype' => $data['type'],
			'meta' => serialize(parent::getMetaInfo($data['tmp_name'], $data['type']))
			);

	}
	
	public function entryDataCleanup($entry_id, $data){
		if ($this->get('remove_from_bucket') == true) {
			try {
				if (!is_null($data['file']))
					$this->S3->deleteObject($this->get('bucket'), basename($data['file']));
			}
			catch (Exception $e) {	;}
		}

		Field::entryDataCleanup($entry_id);

		return true;
	}

	public function checkFields(&$errors, $checkForDuplicates=true){


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

	function checkPostFieldData($data, &$message, $entry_id=NULL){

		/*
			UPLOAD_ERR_OK
			Value: 0; There is no error, the file uploaded with success.

			UPLOAD_ERR_INI_SIZE
			Value: 1; The uploaded file exceeds the upload_max_filesize directive in php.ini.

			UPLOAD_ERR_FORM_SIZE
			Value: 2; The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.

			UPLOAD_ERR_PARTIAL
			Value: 3; The uploaded file was only partially uploaded.

			UPLOAD_ERR_NO_FILE
			Value: 4; No file was uploaded.

			UPLOAD_ERR_NO_TMP_DIR
			Value: 6; Missing a temporary folder. Introduced in PHP 4.3.10 and PHP 5.0.3.

			UPLOAD_ERR_CANT_WRITE
			Value: 7; Failed to write file to disk. Introduced in PHP 5.1.0.

			UPLOAD_ERR_EXTENSION
			Value: 8; File upload stopped by extension. Introduced in PHP 5.2.0.
			*/

		//	Array
		//	(
			//	    [name] => filename.pdf
			//	    [type] => application/pdf
			//	    [tmp_name] => /tmp/php/phpYtdlCl
			//	    [error] => 0
			//	    [size] => 16214
			//	)

		$message = NULL;

		try {
			$this->S3->getBucket($this->get('bucket'));
		}
		catch (Exception $e) {
			$message = __('The bucket %s doesn\'t exist! Please update this section.', array($this->get('bucket')));
			return self::__INVALID_FIELDS__;	
		}

		if(empty($data) || $data['error'] == UPLOAD_ERR_NO_FILE) {


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
		$row = Symphony::Database()->fetchRow(0, "SELECT * FROM `tbl_entries_data_".$this->get('id')."` WHERE `file`='".$data['name']."'");
		if (isset($row['file'])) {
			$message = __('A file with the name %1$s already exists at that bucket. Please rename the file first, or choose another.', array($data['name']));
			return self::__INVALID_FIELDS__;			
		}
		return self::__OK__;		

	}

	function appendFormattedElement(&$wrapper, $data){
		$item = new XMLElement($this->get('element_name'));


		$url = $this->getUrl($data['file']);

		$item->setAttributeArray(array(
			'url' => $url,
			));
		$item->appendChild(new XMLElement('filename', General::sanitize(basename($data['file']))));
		$item->appendChild(new XMLElement('size', $data['size']));
		$item->appendChild(new XMLElement('mimetype', $data['mimetype']));		

		$m = unserialize($data['meta']);

		if(is_array($m) && !empty($m)){
			$item->appendChild(new XMLElement('meta', NULL, $m));
		}

		$wrapper->appendChild($item);
	}



	function commit(){

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

		Symphony::Database()->query("DELETE FROM `tbl_fields_".$this->handle()."` WHERE `field_id` = '$id' LIMIT 1");
		return Symphony::Database()->insert($fields, 'tbl_fields_' . $this->handle());

	}	
	
	private function getUrl($file) {
		$protocol = ($this->get('ssl_option') == true ? 'https://' : 'http://');
		
		if ($this->get('cname') == '') {
			$url = $protocol . $this->get('bucket') . ".s3.amazonaws.com/" . $file;
		}
		else {
			$url = $protocol . $this->get('cname') . "/" . $file;
		}
		return $url;
	}
	
	private function getUniqueFilename(&$file) {
	    ## since uniqid() is 13 bytes, the unique filename will be limited to ($crop+1+13) characters;
	    $crop  = '30';
	    $file = preg_replace("/(.*)(\.[^\.]+)/e", "substr('$1', 0, $crop).'-'.uniqid().'$2'", $file);
	}
	
	public function createTable(){

		return $this->Database->query(
		"CREATE TABLE IF NOT EXISTS `tbl_entries_data_" . $this->get('id') . "` (
			`id` int(11) unsigned NOT NULL auto_increment,
			`entry_id` int(11) unsigned NOT NULL,
			`file` varchar(255) default NULL,
			`size` int(11) unsigned NULL,
			`mimetype` varchar(50) default NULL,
			`meta` varchar(255) default NULL,
			PRIMARY KEY  (`id`),
			KEY `entry_id` (`entry_id`),
			UNIQUE KEY `file` (`file`),
			KEY `mimetype` (`mimetype`)
			) ENGINE=MyISAM;"

			);
	}

	}
