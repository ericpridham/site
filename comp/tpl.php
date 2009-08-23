<?php
require_once('smarty/Smarty.class.php');

class SiteTemplate extends SiteComponent {
  protected $smarty;

  public function init()
  {
    $this->smarty = new Smarty();
    $this->smarty->template_dir = $this->rootDir(@$this->conf['template_dir']);
    $this->smarty->compile_dir  = $this->rootDir(@$this->conf['compile_dir']);
    $this->smarty->config_dir   = $this->rootDir(@$this->conf['config_dir']);
    $this->smarty->cache_dir    = $this->rootDir(@$this->conf['cache_dir']);

    parent::__construct($conf);
  }

  public function rootDir($dir)
  {
    if (!$dir) {
      return null;
    }

    if ($dir{0} == '/') {
      return $dir;
    }

    return "{$_SERVER['DOCUMENT_ROOT']}/$dir";
  }

  public function __get($var)
  {
    return $this->smarty->$var;
  }

  public function __call($func, $args)
  {
    return call_user_func_array(array($this->smarty, $func), $args);
  }
}
?>
