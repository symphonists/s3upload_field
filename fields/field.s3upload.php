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

	private function getUniqueFilename($filename) {
		// since unix timestamp is 10 digits, the unique filename will be limited to ($crop+1+10) characters;
		$crop  = '33';
		return preg_replace("/(.*)(\.[^\.]+)/e", "substr('$1', 0, $crop).'-'.time().'$2'", $filename);
	}



	public function displaySettingsPanel(&$wrapper, $errors = null) {
		field::displaySettingsPanel($wrapper, $errors);

		## bucket Folder
		$ignore = array(
			'/workspace/events',
			'/workspace/data-sources',
			'/workspace/text-formatters',
			'/workspace/pages',
			'/workspace/utilities'
			);
		$directories = General::listDirStructure(WORKSPACE, null, 'asc', DOCROOT, $ignore);

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
		
		$label = Widget::Label(__('CNAME (optional)'));
		$label->appendChild(Widget::Input('fields[' . $this->get('sortorder') . '][cname]'));
		$div->appendChild($label);

		
		$wrapper->appendChild($div);



		$this->buildValidationSelect($wrapper, $this->get('validator'), 'fields['.$this->get('sortorder').'][validator]', 'upload');
		//$this->buildValidationSelect($wrapper, $this->_driver->getAmazonS3AccessKeyId(), 'fields['.$this->get('sortorder').'][validator]', 'upload');


		$this->appendRequiredCheckbox($wrapper);
		$this->appendShowColumnCheckbox($wrapper);

	}

	public function displayPublishPanel(&$wrapper, $data=NULL, $flagWithError=NULL, $fieldnamePrefix=NULL, $fieldnamePostfix=NULL){


		$label = Widget::Label($this->get('label'));
		$class = 'file';
		$label->setAttribute('class', $class);
		if($this->get('required') != 'yes') $label->appendChild(new XMLElement('i', __('Optional')));

		$span = new XMLElement('span');
		$span->setAttribute('class','frame');
		if($data['file']) $span->appendChild(Widget::Anchor($data['file'], $data['file']));

		$span->appendChild(Widget::Input('fields'.$fieldnamePrefix.'['.$this->get('element_name').']'.$fieldnamePostfix, $data['file'], ($data['file'] ? 'hidden' : 'file')));

		$label->appendChild($span);

		if($flagWithError != NULL) $wrapper->appendChild(Widget::wrapFormElementWithError($label, $flagWithError));
		else $wrapper->appendChild($label);

	}

	public function processRawFieldData($data, &$status, $simulate=false, $entry_id=NULL){

		$status = self::__OK__;

		## Its not an array, so just retain the current data and return
		if(!is_array($data)){

			$status = self::__OK__;
		}

		if($simulate) return;

		if($data['error'] == UPLOAD_ERR_NO_FILE || $data['error'] != UPLOAD_ERR_OK) return;

		## Sanitize the filename
		$data['name'] = Lang::createFilename($data['name']);

		## Upload the new file
		try {
			$this->S3->putObject(
				$this->S3->inputResource(fopen($data['tmp_name'], 'rb'), filesize($data['tmp_name'])), 
				$this->get('bucket'), 
				$data['name'], 
				'public-read'
				);
		}
		catch (Exception $e) {
			$status = self::__INVALID_FIELDS__;
			return array(
				'file' => NULL,
				'mimetype' => NULL,
				'size' => NULL,
				'meta' => NULL
				);
		}

		$status = self::__OK__;

		//$file = rtrim($rel_path, '/') . '/' . trim($data['name'], '/');
		$file = "http://" . $this->get('bucket') . ".s3.amazonaws.com/" . $data['name'];


		if($entry_id){
			$row = $this->Database->fetchRow(0, "SELECT * FROM `tbl_entries_data_".$this->get('id')."` WHERE `entry_id` = '$entry_id' LIMIT 1");
			$existing_file = rtrim($rel_path, '/') . '/' . trim(basename($row['file']), '/');

			if((strtolower($existing_file) != strtolower($file)) && file_exists(WORKSPACE . $existing_file)){
				General::deleteFile(WORKSPACE . $existing_file);
			}
		}

		## If browser doesn't send MIME type (e.g. .flv in Safari)
		if (strlen(trim($data['type'])) == 0){
			$data['type'] = 'unknown';
		}


		return array(
			'file' => $file,
			'size' => $data['size'],
			'mimetype' => $data['type'],
			'meta' => serialize(parent::getMetaInfo($data['tmp_name'], $data['type']))
			);

	}

	public function checkFields(&$errors, $checkForDuplicates=true){


		if(!is_array($errors)) $errors = array();

		if(!preg_match('/[^\.]/i')) {
			$errors['cname'] = __('This is an invalid CNAME');
		}

		// Check if a related section has been selected
		if($this->get('bucket') == '') {
			$errors['bucket'] = __('You have not setup your S3 Access keys yet. Please do so <a href="'.SYMPHONY_URL.'/system/preferences/">here</a>.');
		}

		// parent::checkFields($errors, $checkForDuplicates);

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

		if($this->get('validator') != NULL){
			$rule = $this->get('validator');

			if(!General::validateString($data['name'], $rule)){
				$message = __("File chosen in '%s' does not match allowable file types for that field.", array($this->get('label')));
				return self::__INVALID_FIELDS__;
			}

		}

		$abs_path = "http://" . $this->get('bucket') . "s3.amazonaws.com/";
		$new_file = $abs_path . '/' . $data['name'];
		$existing_file = NULL;

		if($entry_id){
			$row = $this->Database->fetchRow(0, "SELECT * FROM `tbl_entries_data_".$this->get('id')."` WHERE `entry_id` = '$entry_id' LIMIT 1");
			$existing_file = $abs_path . '/' . basename($row['file'], '/');
		}

		return self::__OK__;		

	}

	function appendFormattedElement(&$wrapper, $data){
		$item = new XMLElement($this->get('element_name'));

		$item->setAttributeArray(array(
			'url' => $data['file'],
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

		$fields = array();

		$fields['element_name'] = Lang::createHandle($this->get('label'));
		if(is_numeric($fields['element_name']{0})) $fields['element_name'] = 'field-' . $fields['element_name'];

		$fields['label'] = $this->get('label');
		$fields['parent_section'] = $this->get('parent_section');
		$fields['location'] = $this->get('location');
		$fields['required'] = $this->get('required');
		$fields['type'] = $this->_handle;
		$fields['show_column'] = $this->get('show_column');
		$fields['sortorder'] = (string)$this->get('sortorder');


		if($id = $this->get('id')){
			$s3fields['field_id'] = $id;
			$s3fields['bucket'] = $this->get('bucket');
			$s3fields['validator'] = ($fields['validator'] == 'custom' ? NULL : $this->get('validator'));

			Symphony::Database()->query("DELETE FROM `tbl_fields_".$this->handle()."` WHERE `field_id` = '$id' LIMIT 1");		
			return Symphony::Database()->insert($s3fields, 'tbl_fields_' . $this->handle());

		}

		elseif($id = $this->_Parent->add($fields)){
			$this->set('id', $id);
			$this->createTable();
			return true;
		}
		return false;
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
			KEY `file` (`file`),
			KEY `mimetype` (`mimetype`)
			) ENGINE=MyISAM;"

			);
	}

	}
