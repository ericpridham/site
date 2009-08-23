<?php
require_once('smarty/Smarty.class.php');

class SiteTemplate extends SiteComponent {
  protected $smarty;

  public function init()
  {
    $this->assertConfSet(array(
      'template_dir', 'compile_dir', 'config_dir', 'cache_dir'
    ));

    $this->smarty = new Smarty();
    $this->smarty->template_dir = $this->site->root($this->conf['template_dir']);
    $this->smarty->compile_dir  = $this->site->root($this->conf['compile_dir']);
    $this->smarty->config_dir   = $this->site->root($this->conf['config_dir']);
    $this->smarty->cache_dir    = $this->site->root($this->conf['cache_dir']);

    parent::__construct($conf);
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
