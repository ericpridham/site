<?php
require_once('smarty/Smarty.class.php');

class SiteTemplate extends SiteComponent {
  protected $smarty;
  public function __construct($site)
  {
    if (!isset($site->ini['template'])) {
      throw new Exception('Missing template settings.');
    }

    $settings = $site->ini['template'];

    $this->smarty = new Smarty();
    $this->smarty->template_dir = $this->root_dir(@$settings['template_dir']);
    $this->smarty->compile_dir  = $this->root_dir(@$settings['compile_dir']);
    $this->smarty->config_dir   = $this->root_dir(@$settings['config_dir']);
    $this->smarty->cache_dir    = $this->root_dir(@$settings['cache_dir']);

    parent::__construct($site);
  }

  public function root_dir($dir)
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
