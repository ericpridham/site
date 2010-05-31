<?php
require_once('PHPUnit/Framework.php');
require_once(dirname(__FILE__).'/../site.php');
require_once('testing.php');

class SiteDBsTest extends PHPUnit_Framework_TestCase {
  protected $backupGlobals = false;

  public function setUp()
  {
  }

  public function testDBs()
  {
    newConf('dbs', "
components:
  dbs:
    rw:
      model: true
      pool:
        - host: localhost
          username: fake
          password: fake
          database: fake
        - host: localhost
          username: sitetest
          password: st123
          database: sitetest_rw2
    ro:
      model: true
      host: localhost
      username: sitetest
      password: st123
      database: sitetest_ro1

    rw1:
      model: true
      host: localhost
      username: sitetest
      password: st123
      database: sitetest_rw1
    ro1:
      model: true
      host: localhost
      username: sitetest
      password: st123
      database: sitetest_ro1
");

    $site = new Site(getConf('dbs'));
    $this->assertType('SiteDatabase', $site->dbs->rw);
    $this->assertType('SiteDatabase', $site->dbs->ro);
    $this->assertFalse($site->dbs->rw === $site->dbs->ro);
    $this->assertType('null', $site->dbs->notadb);

    $site->dbs->ro1->ro1->all(1);
    $site->dbs->rw1->rw1->all(1);

    killConf('dbs');
  }

  public function tearDown()
  {
  }
}

?>
