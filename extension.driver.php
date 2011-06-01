<?php

	require_once(TOOLKIT . '/class.entrymanager.php');
	require_once(EXTENSIONS .'/s3upload_field/lib/S3.php');

	Class extension_s3upload_field extends Extension {

		public function about() {
			return array(
				'name'			=> 'Field: Amazon S3 File Upload',
				'version'		=> '0.6.4',
				'release-date'	=> '2011-05-31',
				'author'		=> array(
					array(
						'name'			=> 'Scott Tesoriere',
						'website'		=> 'http://tesoriere.com',
						'email'			=> 'scott@tesoriere.com'
					),
					array(
						'name'			=> 'Andrew Shooner and Brian Zerangue',
						'website'		=> 'http://andrewshooner.com',
						'email'			=> 'ashooner@gmail.com'
					),
				),
				'description'	=> 'Upload files to Amazon S3. Based on Brian Zerangue\'s version, based on Michael E\'s upload field.'
			);
		}

		public function getSubscribedDelegates(){
					return array(
						array(
							'page' => '/system/preferences/',
							'delegate' => 'CustomActions',
							'callback' => 'savePreferences'
						),
						array(
							'page' => '/system/preferences/',
							'delegate' => 'AddCustomPreferenceFieldsets',
							'callback' => 'appendPreferences'
						),
					);
		}


		public function appendPreferences($context){
					$group = new XMLElement('fieldset');
					$group->setAttribute('class', 'settings');
					$group->appendChild(new XMLElement('legend', 'Amazon S3 Security Credentials'));

					$div = new XMLElement('div', NULL, array('class' => 'group'));

					$label = Widget::Label('Access Key ID');
					$label->appendChild(Widget::Input('settings[s3upload_field][access-key-id]', General::Sanitize($this->getAmazonS3AccessKeyId())));
					$div->appendChild($label);

					$label = Widget::Label('Secret Access Key');
					$label->appendChild(Widget::Input('settings[s3upload_field][secret-access-key]', General::Sanitize($this->getAmazonS3SecretAccessKey()), 'password'));
					$div->appendChild($label);

					$group->appendChild($div);

					$group->appendChild(new XMLElement('p', 'Get a Access Key ID and Secret Access Key from the <a href="http://aws.amazon.com">Amazon Web Services site</a>.', array('class' => 'help')));


					$label = Widget::Label('Default cache expiry time (in seconds)');
					$label->appendChild(Widget::Input('settings[s3upload_field][cache-control]', General::Sanitize($this->getCacheControl())));

					$group->appendChild($label);


					$context['wrapper']->appendChild($group);

				}

		public function uninstall() {
			$this->_Parent->Database->query("DROP TABLE `tbl_fields_s3upload`");
			Symphony::Configuration()->remove('s3upload_field');
			Administration::instance()->saveConfig();

		}

		public function install() {
			return $this->_Parent->Database->query("CREATE TABLE `tbl_fields_s3upload` (
				`id` int(11) unsigned NOT NULL auto_increment,
				`field_id` int(11) unsigned NOT NULL,
				`bucket` varchar(255) NOT NULL,
				`cname` varchar(255),
				`remove_from_bucket` tinyint(1) DEFAULT '1',
				`unique_filename` tinyint(1) DEFAULT '1',
				`ssl_option` tinyint(1) DEFAULT '0',
				`validator` varchar(50),
				PRIMARY KEY (`id`),
				KEY `field_id` (`field_id`))"
			);
		}

		public function update($previousVersion) {
			if(version_compare($previousVersion, '0.6.4', '<')) {
				// Add new row:
				Administration::instance()->Database->query(
					"ALTER TABLE `tbl_fields_s3upload` ADD `unique_filename` tinyint(1) DEFAULT '1'"
				);
				Administration::instance()->Database->query(
					"ALTER TABLE `tbl_fields_s3upload` ADD `ssl_option` tinyint(1) DEFAULT '0'"
				);

			}
		}

		public function getCacheControl() {
			$val = Symphony::Configuration()->get('cache-control', 's3upload_field');
			if ($val == '' || !preg_match('/^[\d]+$/', $val)) return '864000';
			else return $val;
		}

		public function getAmazonS3AccessKeyId(){
			return Symphony::Configuration()->get('access-key-id', 's3upload_field');
		}

		public function getAmazonS3SecretAccessKey(){
			return Symphony::Configuration()->get('secret-access-key', 's3upload_field');
		}

	}
