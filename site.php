<?php
/*
 * By: Eric <eric@imagineapuddle.com>
 */
class Site {
  protected $components;
  public $conf;

  public function __construct($conf)
  {
    $parts = pathinfo($conf);
    switch ($parts['extension']) {
      case 'ini':
        $this->conf = parse_ini_file($conf, true);
        break;

      case 'yaml':
      case 'yml':
        require_once('spyc.php');
        $this->conf = Spyc::YAMLLoad($conf);
        break;
    }

    if ($this->conf === false) {
      throw new Exception('Could not parse INI.');
    }

    foreach ($this->conf as $component => $conf) {
      $file = dirname(__FILE__)."/$component.php";
      if (file_exists($file)) {
        foreach ($this->loadClasses($file) as $class) {
          if (get_parent_class($class) == 'SiteComponent') {
            try {
              $this->components[$component] = new $class($conf);
            }
            catch (Exception $e) {
              // do proper logging
              echo $e->getMessage();
            }
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

  public function loadClasses($file)
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
