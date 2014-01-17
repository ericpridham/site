<?php
require_once('smarty/Smarty.class.php');

class SiteTemplate extends SiteComponent {
  public function create()
  {
    $this->assertConfSet(array(
      'template_dir', 'compile_dir', 'config_dir', 'cache_dir'
    ));
    return new SmartyHandler($this->site, $this->conf);
  }
}

class SmartyHandler {
  protected $site;
  protected $conf;
  protected $smarty;

  public function __construct($site, $conf)
  {
    $this->site = $site;
    $this->conf = $conf;

    $this->smarty = new Smarty();
    $this->smarty->template_dir = $this->site->root($this->conf['template_dir']);
    $this->smarty->compile_dir  = $this->site->root($this->conf['compile_dir']);
    $this->smarty->config_dir   = $this->site->root($this->conf['config_dir']);
    $this->smarty->cache_dir    = $this->site->root($this->conf['cache_dir']);
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
