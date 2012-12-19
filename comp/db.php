<?php

require_once('MDB2.php');

/**
 * Site component for DB access.
 *
 * @uses SiteComponent
 * @package Site
 * @author Eric Pridham
 */
class SiteDatabase extends SiteComponent {
  protected $dns;
  protected $dbh_ro;
  protected $dbh_rw;
  protected $conf;
  protected $model;
  protected $field_info;
  protected $log_queries;

  //
  // once a rw query has been made, all subsequent queries
  // are made to the rw server.  that way updates are immediately
  // queriable.
  //
  protected $force_rw = false;

  public function init()
  {
    $this->defaultConf(array(
      'result_class' => 'SiteDatabaseResult',
      'model_class'  => 'SiteDatabaseModel',
      'log_queries'  => true,
    ));

    // class file is a source file to include that contains class overrides for
    // result_class, model_class, record_class etc.  there's a chicken/egg problem
    // with just providing a class name, so here we have db.php do the include
    if (@$this->conf['classes_file']) {
      $this->loadClassesFile($this->conf['classes_file']);
    }

    $this->initConnections();

    if (@$this->conf['model']) {
      $c = $this->conf['model_class'];
      $this->model = new $c($this, @$this->conf['tables_path'], @$this->conf['model_record_class']);
    }

    $this->field_info = array();

    $this->log_queries = $this->conf['log_queries'];
  }

  public function loadClassesFile($classes_file)
  {
    $new_classes = Site::loadClasses($classes_file);
    foreach ($new_classes as $class) {
      if (is_subclass_of($class, 'SiteDatabaseResult')) {
        $this->conf['result_class'] = $class;
      }
      elseif (is_subclass_of($class, 'SiteDatabaseModel')) {
        $this->conf['model_class'] = $class;
      }
      elseif (is_subclass_of($class, 'SiteDatabaseModelRecord')) {
        $this->conf['model_record_class'] = $class;
      }
      elseif (in_array('SiteDatabaseRecordWrapper', class_implements($class))) {
        $this->conf['record_class'] = $class;
      }
    }
  }

  // for debugging
  public function inspect($var)
  {
    return $this->$var;
  }

  /**
   * Initializes connections to the DB.
   *
   * Connection combinations:
   *
   * #1 Single DB
   *    phptype =>
   *    username =>
   *    password =>
   *    host =>
   *    db =>
   *
   * #2 DB Pool
   *    pool =>
   *      0 => [Single DB]
   *      1 => [Single DB]
   *      ...
   *
   * #3 RO/RW Split DB
   *    ro => [Single DB]|[DB Pool]
   *    rw => [Single DB]|[DB Pool]
   *
   * @access protected
   * @return void
   */
  protected function initConnections()
  {
    // #3
    if (isset($this->conf['ro']) && isset($this->conf['rw'])) {
      if (isset($this->conf['ro']['pool'])) {
        $this->dbh_ro = $this->dbConnectFromPool($this->conf['ro']['pool']);
      }
      else {
        $this->dbh_ro = $this->dbConnect($this->conf['ro']);
      }
      if (isset($this->conf['rw']['pool'])) {
        $this->dbh_rw = $this->dbConnectFromPool($this->conf['rw']['pool']);
      }
      else {
        $this->dbh_rw = $this->dbConnect($this->conf['rw']);
      }
    }

    // #2
    elseif (isset($this->conf['pool'])) {
      $this->dbh_ro = $this->dbConnectFromPool($this->conf['pool']);
      $this->dbh_rw = $this->dbh_ro;
    }

    // #1
    else {
      $this->dbh_ro = $this->dbConnect($this->conf);
      $this->dbh_rw = $this->dbh_ro;
    }
  }

  public function logQueries($do_log)
  {
    $this->log_queries = $do_log;
  }

  /**
   * Connects to DB.
   *
   * @param array $dbconf - DB config
   * @access protected
   * @return mixed - DB connection handle
   */
  protected function dbConnect($dbconf)
  {
    $dsn = array(
      'phptype'  => @$dbconf['phptype']?:'mysqli',
      'username' => @$dbconf['username'],
      'password' => @$dbconf['password'],
      'hostspec' => @$dbconf['host'],
      'database' => @$dbconf['database'],
      'new_link' => true, // we always want new connections to create their own
                          // link resources
    );

    $dbh = MDB2::connect($dsn);

    if (PEAR::isError($dbh)) {
      throw new Exception('Could not connect to database. (' . $dbh->getMessage() . ' - ' . $dbh->getUserinfo() . ')');
    }

    $this->dsn = $dsn;

    $dbh->setFetchMode(MDB2_FETCHMODE_ASSOC);
    $dbh->setOption('debug', false);
    $dbh->setOption('portability', MDB2_PORTABILITY_ALL^MDB2_PORTABILITY_FIX_CASE);
    $dbh->loadModule('Manager');
    $dbh->loadModule('Extended');
    $dbh->loadModule('Reverse');

    return $dbh;
  }

  protected function dbConnectFromPool($pool)
  {
    $dbh = null;

    for ($i = 0; $i < count($pool) && is_null($dbh); ++$i) {
      try {
        $dbh = $this->dbConnect($pool[$i]);
      }
      catch (Exception $e) {}
    }

    if (is_null($dbh)) {
      throw new Exception('Could not connect to database pool.');
    }

    return $dbh;
  }

  public function getConnection($type)
  {
    if ($this->force_rw || $type == 'rw') {
      $this->force_rw = true;
      return $this->dbh_rw;
    }
    elseif ($type == 'ro') {
      return $this->dbh_ro;
    }
    else {
      return null;
    }
  }

  protected function initFieldInfo($table_name)
  {
    if (!isset($this->field_info[$table_name])) {
      $pk = array();
      $auto = null;
      foreach ($this->tableInfo($table_name) as $info) {
        if (strpos($info['flags'], 'primary_key') !== false) {
          $pk[] = $info['name'];
        }
        if (@$info['autoincrement'] || strpos($info['flags'], 'auto_increment') !== false) {
          $auto = $info['name'];
        }
      }

      $this->field_info[$table_name] = array(
        'pk' => $pk, 'auto' => $auto
      );
    }
  }

  public function getFieldInfo($table_name, $sub = null)
  {
    $this->initFieldInfo($table_name);
    if (is_null($sub)) {
      return @$this->field_info[$table_name];
    }
    return @$this->field_info[$table_name][$sub];
  }

  public function tableInfo($table_name)
  {
    return $this->dbh_rw->tableInfo($table_name);
  }

  public function __destruct()
  {
    if ($this->dbh_ro) {
      $this->dbh_ro->disconnect();
    }
    if ($this->dbh_rw) {
      $this->dbh_rw->disconnect();
    }
    parent::__destruct();
  }

  public function __get($var)
  {
    if (@$this->conf['model']) {
      if ($var == '_model') {
        return $this->model;
      }
      else {
        $tbl = @$this->model->$var;
        if ($tbl) {
          return $tbl;
        }
      }
    }
  }

  public function query($query, $values = null, $count = null, $start = null, $indexby = null)
  {
    // when in doubt, query RW
    return $this->queryRW($query, $values, $count, $start, $indexby);
  }

  public function queryRO($query, $values = null, $count = null, $start = null, $indexby = null)
  {
    return $this->_query($this->getConnection('ro'), $query, $values, $count, $start, $indexby);
  }
  public function queryRW($query, $values = null, $count = null, $start = null, $indexby = null)
  {
    return $this->_query($this->getConnection('rw'), $query, $values, $count, $start, $indexby);
  }

  protected function _query($dbh, $query, $values = null, $count = null, $start = null, $indexby = null)
  {
    $log = array();

    try
    {
      $start_time = microtime(true);
      $log['query'] = $query;
      $log['values'] = $values;

      $sth = $dbh->prepare($query);
      if (MDB2::isError($sth)) {
        throw new Exception('Could not prepare query. (' . $sth->getMessage() . ' - ' . $sth->getUserinfo() . ')');
      }

      $res = $sth->execute($values);
      if (MDB2::isError($res)) {
        throw new Exception('Could not execute query. (' . $res->getMessage() . ' - ' . $res->getUserinfo() . ')');
      }

      $log['runtime'] = microtime(true) - $start_time;
      $nr = $res->numRows();
      if (!MDB2::isError($nr)) {
        $log['numrows'] = $nr;
      }
      else {
        $log['numrows'] = '?';
      }

      $c = $this->conf['result_class'];
      $results = new $c($res, $count, $start, @$this->conf['record_class']);
    }
    catch (Exception $e) {
      $log['error'] = $e->getMessage();
      $this->site->log->query("[{$this->dsn['database']}] $query", $log);
      throw $e;
    }

    if ($this->log_queries) {
      $this->site->log->query("[{$this->dsn['database']}] $query " . ($values?print_r($values,true):'') . "({$log['numrows']} rows in ".number_format($log['runtime'], 2)."s)", $log);
    }

    return $results;
  }

  public function search($table_name, $where = null, $values = null, $orderby = null, $count = null, $start = null, $indexby = null)
  {
    return $this->queryRO(
      "select * from $table_name"
     .($where?" where $where":'')
     .(is_null($orderby)?'':" order by $orderby")
     ,$values, $count, $start, $indexby
    );
  }

  public function exec($function, $args = null, $count = null, $start = null, $indexby = null)
  {
    $argstr  = '';
    if (is_array($args)) {
      foreach ($args as $var => $val) {
        if (!is_null($val) && $val !== '') {
          $argstr .= ($argstr?', ':'') . "@$var = :$var";
        }
      }
    }
    return $this->queryRW(
      "EXEC $function $argstr", $args, $count, $start, $indexby
    );
  }

  public function getFirst($table_name, $where = null, $values = null)
  {
    $res = $this->search($table_name, $where, $values);

    if (!$res->count()) {
      return null;
    }

    return $res[0];
  }

  public function insert($table_name, $row)
  {
    if (!is_array($row) || empty($row)) {
      throw new Exception('Invalid row parameter.');
    }

    // single record insert
    if (is_assoc($row)) {
      $this->queryRW(
         "insert into $table_name (".implode(',', array_keys($row)).")"
        ." values (:".implode(', :', array_keys($row)) . ")"
        ,$row
      );

      if ($this->getFieldInfo($table_name, 'auto')) {
        return $this->dbh_rw->lastInsertId();
      }
    }
    else {
      // validate batch insert
      foreach ($row as $r) {
        if (empty($r) || !is_assoc($r)) {
          throw new Exception('Invalid row parameter.');
        }
        if (!isset($fields)) {
          $fields = array_keys($r);
        }
        else {
          $d = array_diff($fields, array_keys($r));
          if (!empty($d)) {
            throw new Exception('Batch insert fields mismatch.');
          }
        }
      }

      $keys = array_keys($row[0]);
      $inserts = '';
      $values = array();
      foreach ($row as $r) {
        $inserts .= ($inserts?' UNION ALL ':'')
                  . 'SELECT ?' . str_repeat(',?', count($keys)-1);
        // being pedantic about ensuring sort order
        foreach ($keys as $k) {
          $values[] = $r[$k];
        }
      }
      $this->queryRW("insert into $table_name (".implode(',', $keys).") $inserts", $values);
    }

    return true;
  }

  public function update($table_name, $where, $values, $update_values)
  {
    $assigns = '';
    foreach ($update_values as $var => $val) {
      $assigns .= ($assigns?', ':'') . "$var = :$var";
    }

    $this->queryRW(
      "update $table_name set $assigns where $where", array_merge($values, $update_values)
    );
  }

  public function delete($table_name, $where, $values)
  {
    $this->queryRW("delete from $table_name where $where", $values);
  }

  /*
   * NOTE! MDB2's mysqli driver gets it's SET AUTOCOMMIT statements reversed.
   * Fix it by changing '= 1' to '= 0' and vice versa in transaction function
   * calls in Driver/mysqli.php
   *
   */
  public function beginTransaction()
  {
    $this->dbh_rw->beginTransaction();
  }

  public function commit()
  {
    $this->dbh_rw->commit();
  }

  public function rollback()
  {
    $this->dbh_rw->rollback();
  }

  public function getDebugOutput()
  {
    return "RO:\n" . $this->dbh_ro->getDebugOutput()
      . "\n\nRW:\n" . $this->dbh_rw->getDebugOutput();
  }
}

interface SiteDatabaseRecordWrapper {
  public function getRow();
}

/**
 * Result set wrapper class for SiteDatabase
 *
 * @uses Iterator
 * @uses ArrayAccess
 * @uses Countable
 * @package Site
 */
class SiteDatabaseResult implements Iterator, ArrayAccess, Countable {
  protected $res;
  protected $start;
  protected $count;
  protected $rows;
  protected $cur_row;
  protected $fetch_pos;
  protected $iter_pos;
  protected $first_rewind;
  protected $fetched;
  protected $wrap_class;
  protected $wrap_func;

  public function __construct($res, $count = null, $start = null, $wrap_class = null)
  {
    if ($start > 1) {
      $res->seek($start - 1);
    }
    else {
      $start = 1;
    }

    $nr = $res->numRows();
    if (MDB2::isError($nr)) {
      $nr = 0;
    }

    $remainder = max($nr - $start + 1, 0);

    if (is_null($count) || $count > $remainder) {
      $count = $remainder;
    }

    $this->res = $res;
    $this->start = $start;
    $this->count = $count;
    $this->rows = array();
    $this->cur_row = null;
    $this->fetch_pos = $this->iter_pos = 0;
    $this->first_rewind = false;
    // consider result sets of count 0 already fetched
    $this->fetched = $count?false:true;

    $this->setWrapClass($wrap_class);
    $this->wrap_func = null;
  }

  public function setWrapClass($wrap_class)
  {
    if (!is_null($wrap_class)) {
      if (!in_array('SiteDatabaseRecordWrapper', class_implements($wrap_class))) {
        throw new Exception("Invalid wrapper class '$wrap_class'.");
      }
    }
    $this->wrap_class = $wrap_class;
  }

  public function setWrapFunc($f)
  {
    $this->wrap_func = $f;
  }

  // for debugging
  public function inspect($var)
  {
    return $this->$var;
  }

  public function fetchRow()
  {
    if ($this->fetched) {
      return null;
    }

    $this->cur_row = $this->res->fetchRow();

    if (++$this->fetch_pos >= $this->count) {
      $this->fetched = true;
    }

    $this->rows[] = $this->cur_row;
    return $this->wrapRow($this->cur_row);
  }

  protected function wrapRow($row)
  {
    if ($this->wrap_func) {
      $f = $this->wrap_func;
      $ret = $f($row);
    }
    elseif ($this->wrap_class) {
      $c = $this->wrap_class;
      $ret = new $c($row);
    }
    else {
      $ret = $row;
    }
    return $ret;
  }

  protected function wrapRows($rows)
  {
    $w = array();
    foreach ($rows as $row) {
      $w[] = $this->wrapRow($row);
    }
    return $w;
  }

  // fetch to $i index, or all
  public function fetchRows($i = null)
  {
    if (is_null($i)) {
      $i = $this->count - 1;
    }
    // note order of conditionals is important
    while($this->fetch_pos <= $i && $this->fetchRow());
    return $this->wrapRows($this->rows);
  }

  /* Iterator interface */
  public function current()
  {
    if (!$this->fetched) {
      return $this->fetchRow();
    }
    else {
      return $this->wrapRow(@$this->rows[$this->iter_pos]);
    }
  }
  public function key()
  {
    return $this->iter_pos;
  }
  public function next()
  {
    if (!$this->fetched) {
      $this->fetchRow();
    }
    ++$this->iter_pos;
  }
  public function rewind()
  {
    if (!$this->first_rewind) {
      // fetch the rest just in case
      while ($this->fetchRow());
    }
    $this->iter_pos = 0;
    $this->first_rewind = false;
  }
  public function valid()
  {
    if (!$this->fetched) {
      return !is_null($this->cur_row);
    }
    else {
      return isset($this->rows[$this->iter_pos]);
    }
  }

  /* Countable interface */
  public function count()
  {
    return $this->count;
  }

  /* ArrayAccess interface */
  public function offsetSet($i, $v)
  {
    $this->fetchRows($i);
    $this->rows[$i] = $v;
  }
  public function offsetExists($i)
  {
    $this->fetchRows($i);
    return isset($this->rows[$i]);
  }
  public function offsetUnset($i)
  {
    $this->fetchRows($i);
    unset($this->rows[$i]);
  }
  public function offsetGet($i)
  {
    $this->fetchRows($i);
    return isset($this->rows[$i])?$this->wrapRow($this->rows[$i]):null;
  }
}



/**
 * Model class manages interface betwen modeled tables and database.
 *
 * @package Site
 */
class SiteDatabaseModel {
  protected $db;
  protected $fields;
  protected $tables;
  protected $tables_path;
  protected $model_record_class;

  public function __construct($database, $tables_path, $model_record_class = null)
  {
    if (!$database) {
      throw new Exception('Model component requires database component.');
    }

    $this->db = $database;
    $this->tables_path = $tables_path;
    $this->model_record_class = $model_record_class;

    if ($this->tables_path[0] != '/') {
      $this->tables_path = $this->db->getSite()->root($this->tables_path);
    }
  }

  public function __destruct()
  {
    unset($this->db);
  }

  public function __get($table_name)
  {
    $this->initModel($table_name);
    return @$this->tables[$table_name];
  }

  public function getFieldInfo($table_name, $sub = null)
  {
    return $this->db->getFieldInfo($table_name, $sub);
  }

  public function create($table_name, $row = null, $exists = false, $dirty = false)
  {
    $this->initModel($table_name);
    return $this->tables[$table_name]->create($row, $exists, $dirty);
  }

  public function getRecordClass($table_name)
  {
    $this->initModel($table_name);
    return $this->tables[$table_name]->getRecordClass();
  }

  public function get($table_name, $where, $values = null)
  {
    $this->initModel($table_name);

    $row = $this->db->getFirst($table_name, $where, $values);

    if (is_null($row)) {
      return null;
    }

    return $this->create($table_name, $row, /*exists=*/true);
  }

  public function all($table_name, $orderby = null, $count = null, $start = null, $indexby = null)
  {
    return $this->search($table_name, null, null, $orderby, $count, $start, $indexby);
  }

  public function search($table_name, $where = null, $values = null, $orderby = null, $count = null, $start = null, $indexby = null)
  {
    $this->initModel($table_name);

    $res = $this->db->search($table_name, $where, $values, $orderby, $count, $start, $indexby);
    $o = $this;
    $res->setWrapFunc(function ($row) use ($o, $table_name) {
      return $o->create($table_name, $row, /*exists=*/true);
    });

    return $res;
  }

  public function exec($function, $args = null, $count = null, $start = null, $indexby = null)
  {
    return $this->db->exec($function, $args, $count, $start, $indexby);
  }

  public function insert($table_name, $row)
  {
    $this->initModel($table_name);

    $id = $this->db->insert($table_name, $row);
    if ($auto = $this->getFieldInfo($table_name, 'auto')) {
      $row[$auto] = $id;
    }

    return $this->create($table_name, $row, /*exists=*/true, /*dirty=*/true);
  }

  public function update($table_name, $where, $values, $row)
  {
    $this->initModel($table_name);

    $this->db->update($table_name, $where, $values, $row);

    return $this->create($table_name, array_merge($values, $row), /*exists=*/true, /*dirty=*/true);
  }

  public function delete($table_name, $where, $values)
  {
    $this->initModel($table_name);
    $this->db->delete($table_name, $where, $values);
  }

  protected function initModel($table_name)
  {
    if (isset($this->tables[$table_name])) {
      return;
    }

    $table_class = "SiteDatabaseModelTable";
    $model_record_class = $this->model_record_class;

    $model_file = "{$this->tables_path}$table_name.php";
    if ($model_file[0] != '/' && $_SERVER['DOCUMENT_ROOT']) {
      $model_file = "{$_SERVER['DOCUMENT_ROOT']}/$model_file";
    }

    if (file_exists($model_file)) {
      $new_classes = Site::loadClasses($model_file);
      foreach ($new_classes as $class) {
        if (is_subclass_of($class, 'SiteDatabaseModelTable')) {
          $table_class = $class;
        }
        elseif (is_subclass_of($class, 'SiteDatabaseModelRecord')) {
          $model_record_class = $class;
        }
      }
    }

    $this->tables[$table_name] = new $table_class($this, $table_name, $model_record_class);
  }
}

class SiteDatabaseModelTable {
  protected $model;
  protected $table_name;
  protected $record_class;
  protected $rel_cache;

  public function __construct($model, $table_name, $record_class = null)
  {
    $this->model = $model;
    $this->table_name = $table_name;

    if (is_null($record_class)) {
      $this->record_class = 'SiteDatabaseModelRecord';
    }
    else {
      $this->record_class = $record_class;
    }

    $this->rel_cache = array();
  }

  public function __destruct()
  {
    unset($this->model);
  }

  public function getTableName()
  {
    return $this->table_name;
  }

  public function getFieldInfo($sub = null)
  {
    return $this->model->getFieldInfo($this->table_name, $sub);
  }

  public function create($row = null, $exists = false, $dirty = false)
  {
    $c = $this->record_class;
    if (is_object($row) && class_implements($row, 'SiteDatabaseRecordWrapper')) {
      $row = $row->getRow();
    }
    return new $c($this, $row, $exists, $dirty);
  }

  public function getRecordClass()
  {
    return $this->record_class;
  }

  public function get($where, $values = null)
  {
    return $this->model->get($this->table_name, $where, $values);
  }

  public function getRelated($table_name, $where, $values)
  {
    $cache_key = $table_name.'-'.$where.serialize($values);
    if (!isset($this->rel_cache[$cache_key])) {
      $this->rel_cache[$cache_key] = $this->model->get($table_name, $where, $values);
    }
    return $this->rel_cache[$cache_key];
  }

  public function insert($row)
  {
    return $this->model->insert($this->table_name, $row);
  }

  public function update($where, $values, $row)
  {
    return $this->model->update($this->table_name, $where, $values, $row);
  }

  public function delete($where, $values)
  {
    return $this->model->delete($this->table_name, $where, $values);
  }

  public function all($orderby = null, $count = null, $start = null, $indexby = null)
  {
    return $this->model->all($this->table_name, $orderby, $count, $start, $indexby);
  }

  public function search($where = null, $values = null, $orderby = null, $count = null, $start = null, $indexby = null)
  {
    return $this->model->search($this->table_name, $where, $values, $orderby, $count, $start, $indexby);
  }

  public function exec($function, $args = null, $count = null, $start = null, $indexby = null)
  {
    return $this->model->exec($function, $args, $count, $start, $indexby);
  }

  public function searchRelated($table_name, $where = null, $values = null, $orderby = null)
  {
    return $this->model->search($table_name, $where, $values, $orderby);
  }

  public function queryRelated($table_name, $where, $values = null, $orderby = null)
  {
    return $this->model->query($table_name, $where, $values, $orderby);
  }
}

class SiteDatabaseModelRecord implements SiteDatabaseRecordWrapper {
  protected $table;
  protected $row;
  protected $changes;
  protected $exists;
  protected $dirty;

  /**
   * SiteDatabaseModelRecord constructor.
   *
   * @param mixed $table - SiteDatabaseModelTable (or derived) object
   * @param mixed $row - field/value assoc
   * @param mixed $exists - if the record exists in the DB already
   * @param mixed $dirty - $row is fresh from the db (false) or not (true)
   * @access public
   * @return void
   */
  public function __construct($table, $row = null, $exists = false, $dirty = false)
  {
    if (!is_a($table, 'SiteDatabaseModelTable')) {
      throw new Exception('Invalid ModelTable parameter.');
    }
    $this->table = $table;

    if (is_null($row)) {
      $this->row = array();
    }
    else {
      $this->row = $row;
    }

    $this->exists = $exists;
    $this->dirty = $dirty;

    $this->changes = array();
  }

  public function __destruct()
  {
    unset($this->table);
    unset($this->row);
  }

  public function __get($var)
  {
    $this->freshen();

    if (isset($this->changes[$var])) {
      return $this->changes[$var];
    }

    if (isset($this->row[$var])) {
      return $this->row[$var];
    }

    return null;
  }

  // for debugging
  public function inspect($var)
  {
    return $this->$var;
  }

  public function getRow()
  {
    $this->freshen();
    return $this->row;
  }

  public function __set($var, $val)
  {
    return $this->changes[$var] = $val;
    //return $this->row[$var] = $val;
  }

  public function freshen($force = false)
  {
    if ($this->dirty || $force) {
      $key = $this->getKey();

      if (empty($key)) {
        throw new Exception("Can't freshen row.  No key inc values set.");
      }

      $where = '';
      foreach ($key as $var => $val) {
        $where .= ($where?' and ':'') . "$var = :$var";
      }

      $f = $this->table->get($where, $key);
      if (is_null($f)) {
        throw new Exception("Can't freshen row.  Record does not exist.");
      }

      $this->row = $f->getRow();
    }
    $this->dirty = false;
  }

  public function getKey()
  {
    $info = $this->table->getFieldInfo();
    if (isset($info['pk']) && count($info['pk'])) {
      foreach ($info['pk'] as $field) {
        // NOTE! uses $this->row so it doesn't trigger a freshen call
        if (@$this->row[$field]) {
          $key[$field] = @$this->row[$field];
        }
      }
      if (!isset($key) || count($key) != count($info['pk'])) {
        return null;
      }
    }
    elseif (@$info['auto'] && @$this->row[$info['auto']]) {
      $key[$info['auto']] = $this->row[$info['auto']];
    }

    if (!isset($key)) {
      return null;
    }

    return $key;
  }

  public function update($row)
  {
    foreach ($row as $var => $val) {
      $this->__set($var, $val);
    }
  }

  public function save()
  {
    if ($this->exists) {
      $key = $this->getKey();

      if (empty($key)) {
        throw new Exception("Can't update row.  No key values set.");
      }

      $where = '';
      foreach ($key as $var => $val) {
        $where .= ($where?' and ':'') . "$var = :$var";
      }

      $this->table->update($where, $key, $this->changes);
      $this->row = array_merge($this->row, $this->changes);
    }
    else {
      $i = $this->table->insert(array_merge($this->row, $this->changes));
      $this->row = $i->getRow();
    }
    $this->exists = true;
    $this->dirty = true;
    $this->changes = array();
  }

  public function delete()
  {
    $key = $this->getKey();

    if (empty($key)) {
      throw new Exception("Can't delete row.  No key values set.");
    }

    $where = '';
    foreach ($key as $var => $val) {
      $where .= ($where?' and ':'') . "$var = :$var";
    }

    $this->table->delete($where, $key);
    $this->exists = false;
    $this->dirty = false;
  }

  public function updateAndSave($row)
  {
    $this->update($row);
    $this->save();
  }

  public function __toString()
  {
    /*
    $str = '';
    foreach ($this->row as $k => $v) {
      $str .= "$k => $v\n";
    }
    return $str;
    */
    return get_class($this);
  }
}

?>
