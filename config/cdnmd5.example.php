<?php
/**
 * Configuration File for Cdnmd5
 * EXAMPLE
 *
 * cp app/plugins/cdnmd5/config/cdnmd5.example.php app/config/cdnmd5.php
 *
 * Edit to match your CDN and needs
 */

$config = array(
	'Cdnmd5' => array(
		// config for the views, the url() method
		'Presentation' => array(
			// setting this to true at any point, disables the url() method only
			//   a good way to develop locally before sending to CDN
			'disabled' => false,
			// setting this to true bypasses logic for figuring out if you are on
			//   http or https
			'alwaysUseHttps' => false,
		),
		// config for the config files/dirs
		'Config' => array(
			// path to the config directory where you want to store the md5 hashes
			'path' => /* APP */ 'Config/cdnmd5/',
		),
		// configuration for the CDN
        // S3 example
        'CDN' => array(
            'type' => 'S3',
            // the CDN container
            'directory' => 'cdnmd5', // top level directory in the bucket
            'region' => 'us-east-2',
            'bucket' => Configure::read('env') != 'prod' ? 'qa-assets' : 'assets',
            'http' => Configure::read('env') != 'prod' ? 'http://assets-qa.mysite.com' : 'http://assets.mysite.com',
            'https' => Configure::read('env') != 'prod' ? 'https://assets-qa.mysite.com' : 'https://assets.mysite.com',
            'auth' => array(
                'access_key' => Configure::read('AWS.access_key'), // adjust as needed
                'secret_key' => Configure::read('AWS.secret_key'), // adjust as needed
            ),
        ),
		// configuration for purging/cleanup
		'Purge' => array(
			// what's the default age of old files to start considering
			// NOTE: this would never purge any files with current md5hashes
			//   (regardless of age)
			// NOTE: thi sis not called automatically, you would have to call
			//   the purge method manually or on a cron
			'defaultOlderThan' => '-6 months',
		),
	),
);


