<?php

require_once('phar://' . EXTENSIONS .'/s3upload_field/lib/aws.phar');

use Aws\S3\S3Client;
use Guzzle\Http\EntityBody;

if (!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');

class S3Facade {

	private $s3;
	public function __construct($accessKey = null, $secretKey = null) {
		
		$this->s3 = S3Client::factory(array(
			'key' => $accessKey,
			'secret' => $secretKey
		));
	}

	public function listBuckets() {

		$result = $this->s3->listBuckets();

		return $result['Buckets'];
	}

	public function deleteObject($bucket, $key) {

		return $this->s3->deleteObject(array(
			'Bucket' => $bucket,
			'Key' => $key
		)); 
	}

	/**
	 * 
	 */
	public function putObject($bucket, $key, $filePath, $options) {

		$options['Bucket'] = $bucket;
		$options['Key'] = $key;
		$options['Body'] = EntityBody::factory(fopen($filePath, 'r+'));

		return $this->s3->putObject($options);
	}

	public function doesBucketExist($bucket) {
		
		return $this->s3->doesBucketExist($bucket);
	}


}
