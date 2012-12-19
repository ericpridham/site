<?php
if (!function_exists('is_assoc')) {
  function is_assoc($array)
  {
    if (!is_array($array) || empty($array)) {
      return false;
    }
    $keys = array_keys($array);
    return array_keys($keys) !== $keys;
  }
}

class Site {
  protected $components;
  public $conf;

  public function __construct($conf = null)
  {
    // bare minimum requirements.  failures cause generic exceptions.
    if ($conf && !file_exists($conf)) {
      throw new Exception("Site configuration file '$conf' not found.");
    }

    $parts = pathinfo($conf);

    switch (@$parts['extension']) {
      case 'ini':
        $this->conf = parse_ini_file($conf, true);
        break;

      case 'yaml':
      case 'yml':
        //$this->conf = Horde_Yaml::loadFile($conf);
        require_once('spyc/spyc.php');
        $this->conf = Spyc::YAMLLoad($conf);
        break;
    }

    if ($conf && empty($this->conf) && strlen(trim(file_get_contents($conf)))) {
      throw new Exception("Configuration file '$conf' could not be parsed.");
    }

    // ensure root_path is always set
    if (@$this->conf['root_path']) {
      if (@$this->conf['root_path'][0] != '/') {
        $this->conf['root_path'] = "{$_SERVER['DOCUMENT_ROOT']}/{$this->conf['root_path']}";
      }
    }
    else {
      $this->conf['root_path'] = $_SERVER['DOCUMENT_ROOT'];
    }

    $this->loadComponent('log');
    $this->loadComponent('env');
  }

  public function __get($var)
  {
    if (!isset($this->components[$var])) {
      $this->loadComponent($var);
    }

    return $this->components[$var];
  }

  public function loadComponent($component)
  {
    if (isset($this->components[$component])) {
      return true;
    }

    $conf = @$this->conf['components'][$component]?:array();

    $paths = array(dirname(__FILE__).'/comp/'); // core
    if (isset($this->conf['addon_path'])) {
      if ($this->conf['addon_path'][0] != '/') {
        $this->conf['addon_path'] = $this->root($this->conf['addon_path']);
      }
      $paths = array_merge($paths, explode(':', $this->conf['addon_path']));
    }

    $file = $this->findFile("$component.php", $paths);
    if ($file === false) {
      throw new Exception(
        "Component '$component' not found. (" . implode(':', $paths) . ")"
      );
    }
    foreach ($this->loadClasses($file) as $class) {
      if (is_subclass_of($class, 'SiteComponent')) {
        $this->components[$component] = new $class($this, $conf);
      }
    }

    $this->log->debug("Loaded component $component.");
    return true;
  }

  public function getConf($var = null)
  {
    if (is_null($var)) {
      return $this->conf;
    }
    return @$this->conf[$var];
  }

  public function setConf($var, $val)
  {
    return $this->conf[$var] = $val;
  }

  public function defaultConf($var, $val = null)
  {
    $this->conf = $this->arrayDefaults($this->conf, $var, $val);
  }

  public function root($path = null)
  {
    return $this->getConf('root_path').$path;
  }

  /* Utility Functions */

  public function findFile($file, $paths)
  {
    foreach ($paths as $path) {
      if (file_exists("$path/$file")) {
        return "$path/$file";
      }
    }
    return false;
  }

  public function loadComponentClasses($component)
  {
    return $this->loadClasses($file);
  }

  public function loadClasses($file)
  {
    $basename = basename($file);
    static $classes = array();
    if (!isset($classes[$basename])) {
      $pre = get_declared_classes();
      require_once($file);
      $classes[$basename] = array_values(array_diff(get_declared_classes(), $pre));
    }
    return $classes[$basename];
  }

  public function arrayDefaults($conf, $var, $val = null)
  {
    if (!is_array($var)) {
      $defaults = array($var => $val);
    }
    else {
      $defaults = $var;
    }

    foreach ($defaults as $var => $val) {
      $conf[$var] = @$conf[$var]?:$val;
    }

    return $conf;
  }

  public function timeRun($f, &$r)
  {
    $s = microtime(true);
    $r = $f();
    return microtime(true) - $s;
  }

  public function __destruct()
  {
    foreach ($this->components as $name => $c)
    {
      unset($this->components[$name]);
    }
  }
}

class SiteComponent {
  protected $site;
  protected $conf;

  public function __construct($site, $conf)
  {
    $this->site = $site;
    $this->conf = $conf;
    $this->init();
  }

  protected function init()
  {
    // placeholder
  }

  public function getSite()
  {
    return $this->site;
  }

  public function getConf($var = null)
  {
    if (is_null($var)) {
      return $this->conf;
    }
    return @$this->conf[$var];
  }

  public function setConf($var, $val = null)
  {
    if (is_array($var)) {
      $vars = $var;
    }
    else {
      $vars = array($var => $val);
    }

    foreach ($vars as $k => $v) {
      $this->conf[$k] = $v;
    }

    if (is_array($var)) {
      return $var;
    }
    else {
      return $val;
    }
  }

  protected function defaultConf($var, $val = null)
  {
    $this->conf = $this->site->arrayDefaults($this->conf, $var, $val);
  }

  protected function assertConfSet($vars)
  {
    foreach ($vars as $var) {
      if (!isset($this->conf[$var])) {
        throw new Exception("Missing config option '$var'.");
      }
    }
  }

  public function __destruct()
  {
    unset($this->site);
  }
}
?>
