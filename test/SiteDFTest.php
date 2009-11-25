<?php
require_once('PHPUnit/Framework.php');
require_once(dirname(__FILE__).'/../site.php');
require_once('testing.php');

class SiteDFTest extends PHPUnit_Framework_TestCase {
  public function setUp()
  {
  }

  public function testTemplate()
  {
    newConf('df', "
components:
  df:
    files_path: /var/www/imagineapuddle.com/www/siteplay/site/test/files/
");

    if (!file_exists(dirname(__FILE__).'/files/')) {
      mkdir(dirname(__FILE__).'/files/');
    }
    if (!file_exists(dirname(__FILE__).'/files/test/')) {
      mkdir(dirname(__FILE__).'/files/test/');
    }
    file_put_contents(dirname(__FILE__).'/files/test/testfile', '
');

    $site = new Site(getConf('df'));
    $this->assertType('SiteDataFilesController', $site->df);
    $this->assertType('SiteDataFiles', $site->df->test);

    unlink(dirname(__FILE__).'/files/test/.index');
    unlink(dirname(__FILE__).'/files/test/testfile');
    rmdir(dirname(__FILE__).'/files/test/');
    rmdir(dirname(__FILE__).'/files/');

    killConf('df');
  }

  public function tearDown()
  {
  }
}
?>
