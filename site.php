<?php
/*
 * By: Eric <eric@imagineapuddle.com>
 */
class Site {
  protected $components;
  public $ini;

  public function __construct($ini)
  {
    $this->ini = parse_ini_file($ini, true);

    if ($this->ini === false) {
      throw new Exception('Could not parse INI.');
    }

    foreach (glob(dirname(__FILE__).'/*.php') as $file) {
      $component = basename($file, '.php');
      if ($component != 'site') {
        foreach ($this->load_classes($file) as $class) {
          if (get_parent_class($class) == 'SiteComponent') {
            $this->components[$component] = new $class($this);
          }
        }
      }
    }
  }

  public function __get($var)
  {
    if (!isset($this->components[$var])) {
      throw new Exception("Invalid site component '$var'.");
    }

    return $this->components[$var];
  }

  public function load_classes($file)
  {
    $classes = get_declared_classes();
    require($file);
    return array_values(array_diff(get_declared_classes(), $classes));
  }
}

class SiteComponent {
  protected $site;

  public function __construct($site)
  {
    $this->site = $site;
  }
}
?>
