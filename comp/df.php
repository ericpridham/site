<?php

class SiteDataFilesController extends SiteComponent {
  protected $_files_path;

  public function init()
  {
    if (!isset($this->conf['files_path'])) {
      throw new Exception("Missing required config option 'files_path'.");
    }

    $this->_files_path = $this->site->root($this->conf['files_path']);

    if (!is_dir($this->_files_path)) {
      throw new Exception("Invalid path '{$this->_files_path}'.");
    }
  }

  public function __get($var)
  {
    $path = "{$this->_files_path}/$var";
    if (is_dir($path)) {
      return new SiteDataFiles($this, $path);
    }

    return parent::__get($var);
  }
}

class SiteDataFiles {
  protected $_controller;
  protected $_files_path;
  protected $_index;

  public function __construct($controller, $path)
  {
    $this->_controller = $controller;
    $this->_files_path = $path;
    $this->indexFiles();
  }

  protected function indexFiles()
  {
    if (file_exists($this->_files_path.'/.index')) {
      $this->_index = unserialize(file_get_contents($this->_files_path.'/.index'));
    }

    $updated = false;
    foreach (scandir($this->_files_path) as $f) {
      $full_filename = "{$this->_files_path}/$f";
      if (!is_dir($full_filename) && $full_filename[0] != '_') {
        $file = pathinfo($full_filename);
        $last_mod = filemtime($full_filename);

        if (@$this->_index['last_mod'][$file['filename']] != $last_mod) {
          $this->_index['last_mod'][$file['filename']] = $last_mod;

          $df = $this->get($file['filename']);

          foreach ($df->headers() as $name => $value) {
            $this->_index[$name][$value][] = $file['filename'];
          }

          $updated = true;
        }
      }
    }

    if ($updated) {
      file_put_contents($this->_files_path.'/.index', serialize($this->_index));
    }
  }

  public function searchIndex($conditions)
  {
  }

  public function get($conditions)
  {
    if (is_string($conditions)) {
      $path = "{$this->_files_path}/$conditions";
      if (!is_dir($path)) {
        return new SiteDataFile($this, $path);
      }
    }
    elseif (is_array($conditions)) {
      $results = $this->searchIndex($conditions);
      if (count($results)) {
        return $results[0];
      }
    }

    return null;
  }
}

class SiteDataFile {
  protected $files;
  protected $filename;

  public function __construct($files, $filename)
  {
    $this->files = $files;
    $this->filename = $filename;
  }

  protected function loadFile()
  {
    if (!$this->filename) {
      return;
    }
  }

  public function headers()
  {
    return array();
  }
}

?>
