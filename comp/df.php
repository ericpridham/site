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
    else {
      $this->_index = array();
    }

    $seen = array();
    $updated = false;
    foreach (scandir($this->_files_path) as $f) {
      $full_filename = "{$this->_files_path}/$f";
      $file = pathinfo($full_filename);
      $seen[$file['filename']] = true;
      if ($file['filename'] && !is_dir($full_filename) && $file['filename'][0] != '_' && $file['filename'][0] != '.') {
        $last_mod = filemtime($full_filename);
        if (@$this->_index['files'][$file['filename']] != $last_mod) {
          $this->updateIndex($file['filename'], $last_mod);
          $updated = true;
        }
      }
    }

    foreach ($this->_index['files'] as $filename => $last_mod) {
      if (!isset($seen[$filename])) {
        $this->removeFromIndex($filename);
      }
    }

    if ($updated) {
      file_put_contents($this->_files_path.'/.index', serialize($this->_index));
    }
  }

  public function addToIndex($filename, $last_mod)
  {
    $this->_index['files'][$filename] = $last_mod;

    $df = $this->get($filename);
    foreach ($df->getHeaders() as $name => $value) {
      $this->_index['fields'][$name][$value][] = $filename;
    }
  }

  public function removeFromIndex($filename)
  {
    if (isset($this->_index['files'][$filename])) {
      unset($this->_index['files'][$filename]);
    }

    if (!empty($this->_index['fields'])) {
      foreach ($this->_index['fields'] as $field => $values) {
        foreach ($values as $value => $files) {
          if (($i = array_search($filename, $files)) !== false) {
            unset($this->_index[$field][$value][$i]);
          }
        }
      }
    }
  }

  public function updateIndex($filename, $last_mod)
  {
    $this->removeFromIndex($filename);
    $this->addToIndex($filename, $last_mod);
  }

  public function searchIndex($conditions)
  {
    $matches = array();
    if (is_array($conditions)) {
      foreach ($conditions as $field => $value) {
        if (isset($this->_index['fields'][$field][$value])) {
          foreach ($this->_index['fields'][$field][$value] as $filename) {
            if (!isset($matches[$filename])) {
              $matches[$filename] = 1;
            }
            else {
              $matches[$filename]++;
            }
          }
        }
      }
    }
    asort($matches);
    return array_keys($matches);
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
        return new SiteDataFile($this, "{$this->_files_path}/{$results[0]}");
      }
    }
    return null;
  }
}

class SiteDataFile {
  protected $_files;
  protected $_filename;
  protected $_loaded;
  protected $_headers;
  protected $_content;

  public function __construct($files, $filename)
  {
    $this->_files = $files;
    $this->_filename = $filename;
    $this->_loaded = false;
    $this->_headers = $this->_content = null;
  }

  protected function loadFile()
  {
    if (!$this->_filename) {
      return;
    }

    $this->_headers = array();

    $fullStr = file_get_contents($this->_filename)."\n"/*explode below fails without this*/;
    list ($metasStr, $text) = explode("\n\n", $fullStr, 2);

    if (is_null($text)) {
      $this->_content = $metasStr;
      $metasStr = null;
    }
    else {
      $this->_content = $text;
    }

    if ($metasStr) {
      foreach (explode("\n", $metasStr) as $metaStr) {
        if ($metaStr) {
          if (!preg_match('/^(;|#|\/\/)/', @$metaStr[0])) {
            list ($label, $value) = preg_split('/\s*:\s*/', $metaStr, 2);
            $field = strtolower(preg_replace('/[ \t]+/', '_', trim($label)));
            $this->_headers[$field] = $value;
          }
        }
      }
    }

    if (!@$this->_headers['title']) {
      $info = pathinfo($this->_filename);
      $this->_headers['title'] = ucwords(str_replace('_', ' ',
        basename($this->_filename,(isset($info['extension'])?'.'.$info['extension']:''))
      ));
    }

    $this->_loaded = true;
  }

  public function getHeaders()
  {
    if (!$this->_loaded) {
      $this->loadFile();
    }
    return $this->_headers;
  }
}

?>
