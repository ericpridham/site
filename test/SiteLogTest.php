<?php
require_once('PHPUnit/Framework.php');
require_once(dirname(__FILE__).'/../site.php');

class SiteLogTest extends PHPUnit_Framework_TestCase {
  public function setUp()
  {
    //file_put_contents(dirname(__FILE__).'/blank.yaml', "");
  }

  public function testLogging()
  {
    $site = new Site();
    $site->log->info('testinfo');
    $site->log->warn('testwarn');
    $site->log->error('testerror');
    $site->log->debug('testdebug');

    $msgs = array_map(function ($l) { return $l['msg']; }, $site->log->get());

    $this->assertTrue(in_array('testinfo', $msgs));
    $this->assertTrue(in_array('testwarn', $msgs));
    $this->assertTrue(in_array('testerror', $msgs));
    $this->assertTrue(in_array('testdebug', $msgs));
    $this->assertFalse(in_array('testdoesntexist', $msgs));
  }

  public function tearDown()
  {
    //unlink(dirname(__FILE__).'/blank.yaml');
  }
}
?>
