<?php
	
	if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');
	
	require_once(TOOLKIT . '/fields/field.upload.php');
	
	/*
	  This code below is using michael-e's unique field upload as a guide. I just don't know where to go next.
	  I'm trying to add change the destination field to point to my bucket which is selected in Symphony's system
	  preferences where I have a field for Access Key, Secret Access Key, and Bucket.
	
	
	  DON'T KNOW WHERE TO GO NEXT.
	*/

	class FieldS3Upload extends FieldUpload {
		public function __construct(&$parent){
			parent::__construct($parent);
			$this->_name = 'S3 Upload';
		}

		private function getUniqueFilename($filename) {
			// since unix timestamp is 10 digits, the unique filename will be limited to ($crop+1+10) characters;
			$crop  = '33';
			return preg_replace("/(.*)(\.[^\.]+)/e", "substr('$1', 0, $crop).'-'.time().'$2'", $filename);
		}

		public function checkPostFieldData($data, &$message, $entry_id = NULL) {
			if (is_array($data) and isset($data['name'])) $data['name'] = $this->getUniqueFilename($data['name']);
			return parent::checkPostFieldData($data, $message, $entry_id);
		}

		public function processRawFieldData($data, &$status, $simulate = false, $entry_id = NULL) {
			if (is_array($data) and isset($data['name'])) $data['name'] = $this->getUniqueFilename($data['name']);
			return parent::processRawFieldData($data, $status, $simulate, $entry_id);
		}
	}
