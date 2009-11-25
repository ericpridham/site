<?php
require_once('PHPUnit/Framework.php');
require_once(dirname(__FILE__).'/../site.php');
require_once('testing.php');

class SiteTplTest extends PHPUnit_Framework_TestCase {
  public function setUp()
  {
  }

  public function testTemplate()
  {
    newConf('tpl', "
components:
  tpl:
    template_dir: ".dirname(__FILE__)."/view
    compile_dir:  ".dirname(__FILE__)."/view/_compile
    config_dir:   ".dirname(__FILE__)."/view/_config
    cache_dir:    ".dirname(__FILE__)."/view/_cache
");

    $site = new Site(getConf('tpl'));
    $site->tpl->display('test.tpl');;

    killConf('tpl');
  }

  public function tearDown()
  {
  }
}
?>
