<?php

require_once('MDB2.php');

class SiteDatabase extends SiteComponent {
  private $dns;
  private $dbh;

  public function __construct($site)
  {
    if (!isset($site->ini['database'])) {
      throw new Exception('Missing database settings.');
    }

    $settings = $site->ini['database'];

    $this->dsn = array(
      'phptype'  => @$settings['phptype'],
      'username' => @$settings['username'],
      'password' => @$settings['password'],
      'hostspec' => @$settings['host'],
      'database' => @$settings['database'],
    );

    if (!@$this->dsn['phptype']) {
      $this->dsn['phptype'] = 'mysqli';
    }

    $dbh =& MDB2::connect($this->dsn);

    if (PEAR::isError($dbh)) {
      throw new Exception('Could not connect to database. (' . $dbh->getMessage() . ')');
    }

    $this->dbh = $dbh;

    $this->dbh->setFetchMode(MDB2_FETCHMODE_ASSOC);
    //$this->dbh->setOption('debug', true);
    $this->dbh->loadModule('Manager');
    $this->dbh->loadModule('Extended');
    $this->dbh->loadModule('Reverse');

    parent::__construct($site);
  }

  public function __destruct()
  {
    if ($this->dbh) {
      $this->dbh->disconnect();
    }
  }

  public function query($query, $values = null, $count = null, $start = null, $indexby = null)
  {
    $sth = $this->dbh->prepare($query);
    if (MDB2::isError($sth)) {
      throw new Exception('Could not prepare query "'.$query.'". (' . $sth->getMessage() . ')');
    }

    $res = $sth->execute($values);
    if (MDB2::isError($res)) {
      throw new Exception('Could not execute query "'.$query.' ('.print_r($values,true).')". (' . $res->getMessage() . ')');
    }

    $results = array();

    if ($start > 1) {
      $res->seek($start - 1);
    }

    if (is_null($count)) {
      $count = $res->numRows();
    }

    $i = 0;
    while (($row = $res->fetchRow()) && $i++ < $count) {
      $results[] = $row;
    }

    return $results;
  }

  public function search($table_name, $values = null, $orderby = null, $count = null, $start = null, $indexby = null)
  {
    $where = array();
    if (!is_null($values))
    {
      foreach ($values as $var => $val) {
        $where[] = "$var = :$var";
      }
    }
    return $this->query(
      "select * from $table_name"
     .(count($where)?" where " . implode(' and ', $where):'')
     .(is_null($orderby)?'':" order by $orderby"),
     $values, $count, $start, $indexby
    );
  }

  public function getFirst($table_name, $values = null)
  {
    $rows = $this->search($table_name, $values);

    if (!count($rows)) {
      return null;
    }

    return $rows[0];
  }

  public function insert($table_name, $row)
  {
    $fields = implode(',', array_keys($row));
    $values = ':'.implode(', :', array_keys($row));
    $query = "insert into $table_name ($fields) values ($values)";
    $this->query($query, $row);

    return $this->dbh->lastInsertId();
  }

  public function update($table_name, $row, $values)
  {
    $assigns = array();
    foreach ($row as $var => $val) {
      $assigns[] = "$var = :$var";
    }
    $assigns = implode(', ', $assigns);

    $update_values = $row;

    $where = array();
    foreach ($values as $var => $val) {
      $where[] = "$var = :w_$var";
      $update_values["w_$var"] = $val;
    }

    $query = "update {$table_name} set $assigns where " . implode(' and ', $where);
    $this->query($query, $update_values);
  }

  public function delete($table_name, $values)
  {
    $where = array();
    foreach ($values as $var => $val) {
      $where[] = "$var = :$var";
    }
    $query = "delete from {$table_name} where " . implode(' and ', $where);
    $this->query($query, $values);
  }

  public function beginTransaction()
  {
    $this->dbh->beginTransaction();
  }

  public function commit()
  {
    $this->dbh->commit();
  }

  public function rollback()
  {
    $this->dbh->rollback();
  }
}

?>
