<?php

require_once('MDB2.php');

class SiteDataFilesController extends SiteComponent {
  private $conf;
  private $data_root;

  public function __construct($conf)
  {
    $this->conf = $conf;
    $this->data_root = $conf['root'];
  }

  public function __get($var)
  {
    switch ($var) {
      $path = "{$_SERVER['DOCUMENT_ROOT']}/{$this->data_root}/$var";
      if (is_dir($path)) {
        return new SiteDataFiles($path);
      }
    }
    return parent::__get($var);
  }
}

class SiteDataFiles {
  private $path;

  public function __construct($path)
  {
    $this->path = $path;
  }
}

class SiteDataFile {
  private $filename;

  public function __construct($filename)
  {
    $this->filename = $filename;
  }

  protected function loadFile()
  {
    if (!$this->filename) {
      return;
    }
  }
}

?>
