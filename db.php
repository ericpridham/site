<?php

require_once('MDB2.php');

class SiteDatabase extends SiteComponent {
  private $dns;
  private $dbh;
  private $conf;
  private $model;
  private $ro;

  public function __construct($conf)
  {
    $this->conf = $conf;

    $this->dsn = array(
      'phptype'  => @$this->conf['phptype'],
      'username' => @$this->conf['username'],
      'password' => @$this->conf['password'],
      'hostspec' => @$this->conf['host'],
      'database' => @$this->conf['database'],
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

    if ($this->conf['model']) {
      $this->model = new SiteDatabaseModelController($this, $this->conf['models_path']);
    }

    if ($this->conf['ro']) {
      $this->ro = true;
    }

    parent::__construct($conf);
  }

  public function __destruct()
  {
    if ($this->dbh) {
      $this->dbh->disconnect();
    }
  }

  public function __get($var)
  {
    if ($this->conf['model']) {
      $tbl = $this->model->$var;
      if ($tbl) {
        return $tbl;
      }
    }
  }

  public function query($query, $values = null, $count = null, $start = null, $indexby = null)
  {
    $log = array();

    try
    {
      $start = microtime(true);
      $log['query'] = $query;
      $log['values'] = $values;

      $sth = $this->dbh->prepare($query);
      if (MDB2::isError($sth)) {
        throw new Exception('Could not prepare query. (' . $sth->getMessage() . ')');
      }

      $res = $sth->execute($values);
      if (MDB2::isError($res)) {
        throw new Exception('Could not execute query. (' . $res->getMessage() . ')');
      }

      $log['runtime'] = microtime(true) - $start;
      $log['numrows'] = $res->numRows();

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
    }
    catch (Exception $e) {
      $log['error'] = $e->getMessage();
    }

    if ($this->conf['logging']) {
      echo "<!-- ".print_r($log,true)." -->";
      file_put_contents($this->conf['log_path'] . 'queries.log', serialize($log), FILE_APPEND);
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

  public function tableInfo($table_name)
  {
    return $this->dbh->tableInfo($table_name);
  }
}

class SiteDatabaseModelController {
  protected $db;
  protected $fields;
  protected $models;
  protected $models_path;

  public function __construct($database, $models_path)
  {
    if (!$database) {
      throw new Exception('Model component requires database component.');
    }

    $this->db = $database;
    $this->models_path = $models_path;
  }

  public function __get($table_name)
  {
    $this->initModel($table_name);
    return $this->models[$table_name];
  }

  public function get($table_name, $id)
  {
    $this->initModel($table_name);

    if (is_array($id)) {
      $row = $this->db->getFirst($table_name, $id);
    }
    else {
      $row = $this->db->getFirst($table_name, array('id' => $id));
    }

    if (is_null($row)) {
      return null;
    }

    return $this->models[$table_name]->create($row);
  }

  public function all($table_name)
  {
    return $this->search($table_name);
  }

  public function search($table_name, $values = null, $orderby = null)
  {
    $this->initModel($table_name);

    $rows = $this->db->search($table_name, $values, $orderby);
    $records = array();
    foreach ($rows as $row) {
      $records[] = $this->models[$table_name]->create($row);
    }
    return $records;
  }

  public function query($table_name, $where, $values = null, $orderby = null)
  {
    $this->initModel($table_name);

    $query = "select * from $table_name where $where" . ($orderby?" order by $orderby":'');
    $rows = $this->db->query($query, $values);
    $records = array();
    foreach ($rows as $row) {
      $records[] = $this->models[$table_name]->create($row);
    }
    return $records;
  }

  public function save($table_name, $row)
  {
    $this->initModel($table_name);

    if (isset($row['id'])) {
      $r = $this->db->getFirst($table_name, array('id' => $row['id']));
      if (is_null($r)) {
        throw new Exception("No record in $table_name with id $id");
      }

      $update_row = $row;
      $id = $row['id'];
      unset($update_row['id']);

      $this->db->update($table_name, $update_row, array('id' => $id));
    }
    else {
      $id = $this->db->insert($table_name, $row);
    }

    return $this->get($table_name, $id);
  }

  public function delete($table_name, $id)
  {
    $this->initModel($table_name);

    if (is_array($id)) {
      $this->db->delete($table_name, $id);
    }
    else {
      $this->db->delete($table_name, array('id' => $id));
    }
  }

  protected function initModel($table_name)
  {
    if (isset($this->models[$table_name])) {
      return;
    }

    $this->fields = $this->db->tableInfo($table_name);

    $model_file = "{$_SERVER['DOCUMENT_ROOT']}{$this->models_path}$table_name.php";
    if (file_exists($model_file)) {
      $new_classes = Site::loadClasses($model_file);
      foreach ($new_classes as $class) {
        if (get_parent_class($class) == 'SiteDatabaseModel') {
          $model_class = $class;
        }
        elseif (get_parent_class($class) == 'SiteDatabaseModelRecord') {
          $model_record_class = $class;
        }
      }
      if (!$model_class) {
        $model_class = "SiteDatabaseModel";
      }

      $this->models[$table_name] = new $model_class($this, $table_name, $model_record_class);
    }
    else {
      $this->models[$table_name] = new SiteDatabaseModel($this, $table_name);
    }
  }
}

class SiteDatabaseModel {
  protected $controller;
  protected $table_name;
  protected $record_class;

  public function __construct($controller, $table, $record_class = null)
  {
    $this->controller = $controller;
    $this->table_name = $table;

    if (is_null($record_class)) {
      $this->record_class = 'SiteDatabaseModelRecord';
    }
    else {
      $this->record_class = $record_class;
    }
  }

  public function create($row = null)
  {
    $c = $this->record_class;
    return new $c($this, $row);
  }

  public function get($id)
  {
    return $this->controller->get($this->table_name, $id);
  }

  public function getRelated($table_name, $id)
  {
    static $rel_cache;
    if (is_null($rel_cache)) {
      $rel_cache = array();
    }
    if (!isset($rel_cache[$table_name.'-'.serialize($id)])) {
      $rel_cache[$table_name.'-'.serialize($id)] = $this->controller->get($table_name, $id);
    }
    return $rel_cache[$table_name.'-'.serialize($id)];
  }

  public function save($row)
  {
    return $this->controller->save($this->table_name, $row);
  }

  public function delete($id)
  {
    return $this->controller->delete($this->table_name, $id);
  }

  public function all()
  {
    return $this->controller->search($this->table_name);
  }

  public function search($values = null, $orderby = null)
  {
    return $this->controller->search($this->table_name, $values, $orderby);
  }

  public function searchRelated($table_name, $values = null, $orderby = null)
  {
    return $this->controller->search($table_name, $values, $orderby);
  }

  public function query($where, $values = null, $orderby = null)
  {
    return $this->controller->query($this->table_name, $where, $values, $orderby);
  }

  public function queryRelated($table_name, $where, $values = null, $orderby = null)
  {
    return $this->controller->query($table_name, $where, $values, $orderby);
  }
}

class SiteDatabaseModelRecord {
  protected $model;
  protected $row;

  public function __construct($model, $row = null)
  {
    $this->model = $model;

    if (is_null($row)) {
      $this->row = array();
    }
    else {
      $this->row = $row;
    }
  }

  public function __get($var)
  {
    if (!isset($this->row[$var])) {
      return null;
    }
    return $this->row[$var];
  }

  public function __set($var, $val)
  {
    return $this->row[$var] = $val;
  }

  public function getRow()
  {
    return $this->row;
  }

  public function update($row)
  {
    $this->row = array_merge($this->row, $row);
  }

  public function save()
  {
    $saved = $this->model->save($this->row);
    $this->row = $saved->getRow();
  }

  public function delete()
  {
    $this->model->delete($this->row['id']);
  }

  public function updateAndSave($row)
  {
    $this->update($row);
    $this->save();
  }
}

?>
