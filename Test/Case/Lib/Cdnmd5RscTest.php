<?php
/**
 * This is a test for the Cdnmd5Rsc Lib
 *   (an adaptor extension of Cdnmd5 Lib)
 *
 */
App::Import('Lib', 'Cdnmd5.Cdnmd5');

class Cdnmd5RscTest extends CakeTestCase {

	// set in startTest
	public $path_config = null;
	public $testfilepath = null;
	public $testfilehash = null;

	/**
	 * setup tests
	 */
	public function startTest($method) {
		parent::startTest($method);
	}

	/**
	 * tear down test
	 */
	public function endTest($method) {
		parent::endTest($method);
	}

	// TODO: build out tests specific to RSC adaptor
}
