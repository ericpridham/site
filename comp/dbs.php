<?php

// comment
require_once('MDB2.php');

class SiteDatabases extends SiteComponent {
  protected $dbs;

  public function init()
  {
    $this->defaultConf(array(
      'result_class' => 'SiteDatabaseResult',
      'model_class'  => 'SiteDatabaseModel',
    ));
  }


  public function __construct($conf)
  {
    // include required components here.  otherwise site.php picks up
    // on the new SiteComponent class and things get messed up.
    require_once('db.php');

    foreach ($conf as $dbname => $dbconf) {
      $this->dbs[$dbname] = new SiteDatabase($dbconf);
    }
  }

  public function __get($var)
  {
    if (isset($this->dbs[$var])) {
      return $this->dbs[$var];
    }
  }
}

?>
