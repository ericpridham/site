<?php

// comment
require_once('MDB2.php');

class SiteDatabases extends SiteComponent {
  protected $dbs;
  protected $startup_queries;

  public function init()
  {
    // don't initialize any database connections here ...
    $this->dbs = array();
    $this->startup_queries = array();
  }

  public function setStartupQueries($queries)
  {
    $this->startup_queries = $queries;
  }

  public function __get($var)
  {
    // include required components here.  otherwise site.php picks up
    // on the new SiteComponent class and things get messed up.
    require_once('db.php');

    // ... initialize the connections here instead, so that we only open
    // connections to databases that we use
    if (!isset($this->dbs[$var])) {
      if (isset($this->conf[$var])) {
        $this->site->log->debug("DBs: Connecting to DB '$var'");
        $this->dbs[$var] = new SiteDatabase($this->site, $this->conf[$var]);
        if (!empty($this->startup_queries)) {
          foreach ($this->startup_queries as $query) {
            $this->dbs[$var]->query($query);
          }
        }
      }
    }
    return $this->dbs[$var];
  }

  public function __destruct()
  {
    foreach ($this->dbs as $db => $x) {
      unset($this->dbs[$db]);
    }
    unset($this->dbs);
  }
}

?>
