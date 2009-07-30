<?php
require_once('PHPUnit/Framework.php');
require_once(dirname(__FILE__).'/../site.php');

class SiteTest extends PHPUnit_Framework_TestCase {
  public function setUp()
  {
    file_put_contents(dirname(__FILE__).'/blank.yaml', "
"
    );

    file_put_contents(dirname(__FILE__).'/garbage.yaml', "
::0)Sf0--
"
    );

    file_put_contents(dirname(__FILE__).'/root_path_rel.yaml', "
root_path: siteplay/
"
    );
    file_put_contents(dirname(__FILE__).'/root_path_abs.yaml', "
root_path: /var/www/siteplay/
"
    );

    file_put_contents(dirname(__FILE__).'/components.yaml', "
components:
  - blank:
"
    );
  }

  /**
    * @expectedException Exception
    **/
  public function testConfLoadNotExists()
  {
    $site = new Site(dirname(__FILE__).'/bar.yaml');
  }

  /**
    * @expectedException Exception
    **/
  public function testConfLoadGarbage()
  {
    $site = new Site(dirname(__FILE__).'/garbage.yaml');
  }

  public function testConfLoadSuccess()
  {
    $site = new Site(dirname(__FILE__).'/blank.yaml');
    $this->assertType('array',  $site->getConf());

    $this->assertEquals($site->getConf('root_path'), $_SERVER['DOCUMENT_ROOT']);

    $site = new Site(dirname(__FILE__).'/root_path_rel.yaml');
    $this->assertEquals($site->getConf('root_path'), $_SERVER['DOCUMENT_ROOT'].'/siteplay/');

    $site = new Site(dirname(__FILE__).'/root_path_abs.yaml');
    $this->assertEquals($site->getConf('root_path'), '/var/www/siteplay/');
  }

  public function tearDown()
  {
    unlink(dirname(__FILE__).'/blank.yaml');
    unlink(dirname(__FILE__).'/garbage.yaml');
    unlink(dirname(__FILE__).'/root_path_rel.yaml');
    unlink(dirname(__FILE__).'/root_path_abs.yaml');
    unlink(dirname(__FILE__).'/components.yaml');
  }
}

?>
