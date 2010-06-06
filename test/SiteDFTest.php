<?php
require_once('PHPUnit/Framework.php');
require_once(dirname(__FILE__).'/../site.php');
require_once('testing.php');

class SiteDFTest extends PHPUnit_Framework_TestCase {
  public function setUp()
  {
  }

  public function testDataFiles()
  {
    newConf('df', "components:
  df:
    files_path: /var/www/imagineapuddle.com/www/siteplay/site/test/files/
");

    if (!file_exists(dirname(__FILE__).'/files/')) {
      mkdir(dirname(__FILE__).'/files/');
    }
    if (!file_exists(dirname(__FILE__).'/files/test/')) {
      mkdir(dirname(__FILE__).'/files/test/');
    }
    file_put_contents(dirname(__FILE__).'/files/test/testfile', 'Title: A Bing-a-bong
Bar Biz: Bang

Content
');

    $site = new Site(getConf('df'));
    $this->assertType('SiteDataFilesController', $site->df);
    $this->assertType('SiteDataFiles', $site->df->test);

    $f = $site->df->test->get(array('title' => 'A Bing-a-bong'));
    $this->assertType('SiteDataFile', $f);

    $this->assertEquals($f->title,   'A Bing-a-bong');
    $this->assertEquals($f->bar_biz, 'Bang');
    $this->assertEquals($f->content, "Content\n");

    $f = $site->df->test->get(array('title' => 'Not-a-Title'));
    $this->assertNull($f);

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
