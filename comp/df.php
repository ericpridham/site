<?php

class SiteDataFilesController extends SiteComponent {
  protected $data_root;

  public function init()
  {
    if (!isset($this->conf['data_root'])) {
      throw Exception("Missing required config option 'data_root'.");
    }

    $this->data_root = $this->site->root($this->conf['data_root']);

    if (!is_dir($this->data_root)) {
      throw Exception("Invalid path '{$this->data_root}'.");
    }
  }

  public function __get($var)
  {
    switch ($var) {
      $path = "{$this->data_root}/$var";
      if (is_dir($path)) {
        return new SiteDataFiles($this, $path);
      }
    }
    return parent::__get($var);
  }
}

class SiteDataFiles {
  protected $controller;
  protected $files_path;

  public function __construct($controller, $path)
  {
    $this->controller = $controller;
    $this->files_path = $path;
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
