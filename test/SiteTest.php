<?php
require_once('PHPUnit/Framework.php');
require_once(dirname(__FILE__).'/../site.php');
require_once('testing.php');

class SiteTest extends PHPUnit_Framework_TestCase {
  public function setUp()
  {
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
    newConf('garbage', "
::0)Sf0--
"
    );
    $site = new Site(dirname(__FILE__).'/garbage.yaml');
    killConf('garbage');
  }

  public function testConfLoadSuccess()
  {
    newConf('blank', '');
    $site = new Site(getConf('blank'));
    $this->assertType('array',  $site->getConf());
    killConf('blank');

    $this->assertEquals($site->getConf('root_path'), $_SERVER['DOCUMENT_ROOT']);

    newConf('root_path_rel', "
root_path: siteplay/
"
    );
    $site = new Site(getConf('root_path_rel'));
    $this->assertEquals($site->getConf('root_path'), $_SERVER['DOCUMENT_ROOT'].'/siteplay/');
    killConf('root_path_rel');

    newConf('root_path_abs', "
root_path: /var/www/siteplay/
"
    );
    $site = new Site(getConf('root_path_abs'));
    $this->assertEquals($site->getConf('root_path'), '/var/www/siteplay/');
    killConf('root_path_abs');
  }

  public function testAddon()
  {
    newConf('addon', "
addon_path: " . dirname(__FILE__) . "/addons/
"
    );

    if (!file_exists(dirname(__FILE__).'/addons/')) {
      mkdir(dirname(__FILE__).'/addons/');
    }
    file_put_contents(dirname(__FILE__).'/addons/addon.php', '
<?php
class SiteAddon extends SiteComponent {
}
?>
');

    $site = new Site(getConf('addon'));
    $this->assertType('SiteAddon', $site->addon);

    unlink(dirname(__FILE__).'/addons/addon.php');
    rmdir(dirname(__FILE__).'/addons/');

    killConf('addon');
  }


  public function testAddons()
  {
    newConf('addons', "
addon_path: " . dirname(__FILE__) . "/addons1/:" . dirname(__FILE__) . "/addons2/
"
    );

    if (!file_exists(dirname(__FILE__).'/addons1/')) {
      mkdir(dirname(__FILE__).'/addons1/');
    }
    if (!file_exists(dirname(__FILE__).'/addons2/')) {
      mkdir(dirname(__FILE__).'/addons2/');
    }
    file_put_contents(dirname(__FILE__).'/addons1/addon1.php', '
<?php
class SiteAddon1 extends SiteComponent {
}
?>
');
    file_put_contents(dirname(__FILE__).'/addons2/addon2.php', '
<?php
class SiteAddon2 extends SiteComponent {
}
?>
');

    $site = new Site(getConf('addons'));
    $this->assertType('SiteAddon1', $site->addon1);
    $this->assertType('SiteAddon2', $site->addon2);

    unlink(dirname(__FILE__).'/addons1/addon1.php');
    unlink(dirname(__FILE__).'/addons2/addon2.php');
    rmdir(dirname(__FILE__).'/addons1/');
    rmdir(dirname(__FILE__).'/addons2/');

    killConf('addons');
  }

  public function tearDown()
  {
  }
}

?>
