<?php

class SiteDataFilesController extends SiteComponent {
  protected $root_path;

  public function init()
  {
    if (!isset($this->conf['root_path'])) {
      throw Exception("Missing required config option 'root_path'.");
    }

    $this->root_path = $this->site->getRoot() . "/{$this->conf['root_path']}";

    if (!is_dir($this->root_path)) {
      throw Exception("Invalid path '{$this->root_path}'.");
    }
  }

  public function __get($var)
  {
    switch ($var) {
      $path = "{$this->root_path}/$var";
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
