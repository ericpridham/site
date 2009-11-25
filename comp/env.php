<?php
class EnvironmentComponent extends SiteComponent
{
  protected $env;
  public function init($env = null)
  {
    if (is_null($env) || !is_array($env)) {
      $this->env = array(
        'post'   => $_POST,
        'get'    => $_GET,
        'cookie' => $_COOKIE,
        'server' => $_SERVER,
        'shell'  => $_ENV,
      );
      if (isset($this->conf['session'])) {
        session_start();
        $this->env['session'] = $_SESSION;
      }
    }
    else {
      $this->env = env;
    }
  }

  public function get($var, $type = null) 
  {
    if (!is_null($type)) {
      if (isset($this->env[$type][$var])) {
        return $this->env[$type][$var];
      }
      return null;
    }

    $order = array('post', 'get', 'cookie', 'server', 'shell', 'session');
    foreach ($order as $type) {
      if (isset($this->env[$type][$var])) {
        return $this->env[$type][$var];
      }
    }
    return null;
  }

  public function set($var, $val, $type = 'all')
  {
  }
}
?>
