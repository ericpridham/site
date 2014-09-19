<?php
require_once('MDB2.php');

/**
 * Site component for database access.
 *
 * This is a general purpose database connection component backed by MDB2, so
 * you'll need to install MDB2 and the associated addons for your database.
 * It supports single DB, heterogeneous pools, and RW/RO split pool schemes.  It
 * also has an optional modeling system that allows you to write your own
 * classes for each table and record instance.
 *
 * Settings
 * --------
 *
 * Connection setting options:
 *
 * #1 [Single DB]
 *   phptype: mysql|mssql|...
 *   username: user
 *   password: pass
 *   host: host
 *   db: dbname
 *   model: true|false
 *   tables_path: path/to/model/classes/
 *   classes_file: path/to/custom/model/classes/
 *
 * #2 [DB Pool]
 *   pool:
 *     - [Single DB]
 *     - [Single DB]
 *     ...
 *
 * #3 RO/RW Split DB
 *   ro: [Single DB]|[DB Pool]
 *   rw: [Single DB]|[DB Pool]
 *
 * Usage
 * -----
 *
 * Non-modeled:
 *
 *   $users = $site->db->query('SELECT username, firstname, lastname FROM users');
 *   echo $users->numRows() . " User(s):\n\n";
 *   foreach ($users as $user) {
 *     echo $user->lastname . ", " . $user->lastname . "\n";
 *   }
 *
 *   $login = $site->db->get('users', 'username = ? and password = ?', array('hrunner', 'marzipan'));
 *   if (is_null($login)) {
 *      throw Exception("Login failed!");
 *   }
 *
 * Modeled:
 *
 *   $users = $site->db->users->all();
 *   ...
 *
 *   $login = $site->db->users->get('username = ? and password = ?', array('sbad', 'trogdor'));
 *   if (is_null($login)) {
 *      throw Exception("Login failed!");
 *   }
 *   $login->last_login = date('Y-m-d H:i:s');
 *   $login->save();
 * 
 *
 * @package Site
 */
class SiteDatabase extends SiteComponent {
  /**
   * Current connection handler to read-only db.
   * @var object
   */
  protected $dbh_ro;
  /**
   * Current connection handler to read/write db.
   * @var object
   */
  protected $dbh_rw;
  /**
   * Configuration options parsed from settings file.
   * @var array
   */
  protected $conf;
  /**
   * Instance of modeling handler class.
   * @var object
   */
  protected $model;
  /**
   * Field info (primary key, autoinc) for table fields.
   * @var array
   */
  protected $field_info;
  /**
   * Query logs.
   * @var array
   */
  protected $log_queries;

  /**
   * once a rw query has been made, all subsequent queries are made to the rw
   * server so  updates are immediately queriable.
   * @var bool
   */
  protected $force_rw = false;

  /**
   * Component initializer.
   */
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


  /**
   * Loads conf['classes_file'] looking for overrides for the built-in modeling
   * classes.
   *
   * @param string $classes_file Path to file containing class definitions.
   */
  protected function loadClassesFile($classes_file)
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

  /**
   * Turn on/off query logging.
   *
   * @param bool $do_log
   */
  public function logQueries($do_log)
  {
    $this->log_queries = $do_log;
  }

  /**
   * Connects to DB.
   *
   * @param array $dbconf DB config
   * @return mixed DB connection handle
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

  /**
   * Initializes a connection to one of the databases in a pool.
   *
   * @param array $pool Array of database connections.
   * @return mixed Database handle.
   */
  protected function dbConnectFromPool($pool)
  {
    $dbh = null;

    // Here we just go through sequentially and find the first active db
    // connection.  TODO: Implement hooks for optional decision schemes.
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

  /**
   * Returns an established connection of type $type (or rw if force_rw = true).
   *
   * @param string $type 'rw' or 'ro'
   * @return mixed Active DB connection handle or null if none found.
   */
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

  /**
   * Initialize field info from MDB2 reflection functions for a single table.
   *
   * @param string $table_name Table to get info for.
   */
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

  /**
   * Returns field info for single table.
   *
   * @param string $table_name Table to get info for.
   * @param string $sub Subset of field info to return.
   * @return array Requested field info.
   */
  public function getFieldInfo($table_name, $sub = null)
  {
    $this->initFieldInfo($table_name);
    if (is_null($sub)) {
      return @$this->field_info[$table_name];
    }
    return @$this->field_info[$table_name][$sub];
  }

  /**
   * Returns a list of tables for the database.
   *
   * @return array List of table names.
   */
  public function getTables()
  {
    return $this->dbh_rw->listTables();
  }

  /**
   * Returns results from an MDB2 tableInfo call.
   *
   * @param string $table_name Table to get info for.
   * @return mixed Table information.
   */
  public function tableInfo($table_name)
  {
    return $this->dbh_rw->tableInfo($table_name);
  }

  /**
   * Destructor.
   */
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

  /**
   * Table picker.
   *
   * Magical get function that, when modeling is turned on, returns an instance
   * of a modeled table class.
   *
   * @param string $var Table to model.
   * @return mixed Instance of table's model class.
   */
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

  /**
   * Wrapper for queryRW.
   */
  public function query($query, $values = null, $count = null, $start = null, $indexby = null)
  {
    // when in doubt, query RW
    return $this->queryRW($query, $values, $count, $start, $indexby);
  }

  /**
   * Wrapper for _query that defaults to connection type 'ro'.
   */
  public function queryRO($query, $values = null, $count = null, $start = null, $indexby = null)
  {
    return $this->_query($this->getConnection('ro'), $query, $values, $count, $start, $indexby);
  }

  /**
   * Wrapper for _query that defaults to connection type 'rw'.
   */
  public function queryRW($query, $values = null, $count = null, $start = null, $indexby = null)
  {
    return $this->_query($this->getConnection('rw'), $query, $values, $count, $start, $indexby);
  }

  /**
   * Executes a query.
   *
   * @param object $dbh DB connection handle
   * @param string $query Query string.
   * @param array $values Bind values.
   * @param int $count Number of records to return.  null = all
   * @param int $start Index of first record to return.  null = 0
   * @param string $indexby (NOT YET IMPLEMENTED)
   * @return object An handler instance of result_class for query results.
   */
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
      $this->site->log->debug("new \$c: count($count) start($start)");
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

  /**
   * Executes a read-only select * query.
   *
   * @param string $table_name Table to query.
   * @param string $where Conditions for the WHERE clause of the select.
   * @param array $values Bind values for WHERE clause.
   * @param string $orderby Fields for the ORDER BY clause.
   * @param int $count Number of records to return.  null = all
   * @param int $start Index of first record to return.  null = 0
   * @param string $indexby (NOT YET IMPLEMENTED)
   * @return object An handler instance of result_class for query results.
   */
  public function search($table_name, $where = null, $values = null, $orderby = null, $count = null, $start = null, $indexby = null)
  {
    return $this->queryRO(
      "select * from $table_name"
     .($where?" where $where":'')
     .(is_null($orderby)?'':" order by $orderby")
     ,$values, $count, $start, $indexby
    );
  }

  /**
   * Executes a search() then returns the first result
   *
   * @param string $table_name Table to query.
   * @param string $where Conditions for the WHERE clause of the search.
   * @param string $values Bind values.
   * @return object Returns a record instance or null if no results.
   */
  public function getFirst($table_name, $where = null, $values = null)
  {
    $res = $this->search($table_name, $where, $values);

    if (!$res->count()) {
      return null;
    }

    return $res[0];
  }

  /**
   * Executes a r/w insert into query.
   *
   * @param string $table_name Table to insert into.
   * @param array $row Assoc array of (column => value) pairs containing the data to insert.
   * @return mixed The auto-int key of the inserted record or true if no auto-int exists.
   */
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

  /**
   * Executes a r/w update query.
   *
   * @param string $table_name Table to update.
   * @param string $where Conditions for the WHERE clause of the update.
   * @param array $values Bind values for the WHERE.
   * @param array $update_values Assoc array of (column => value) pairs containing the data to update.
   */
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

  /**
   * Executes a r/w delete query.
   *
   * @param string $table_name Table to delete from.
   * @param string $where Conditions for the WHERE clause of the delete.
   * @param array $values Bind values for WHERE clause.
   */
  public function delete($table_name, $where, $values)
  {
    $this->queryRW("delete from $table_name where $where", $values);
  }

  /**
   * Begins a transaction.
   *
   * NOTE! MDB2's mysqli driver gets it's SET AUTOCOMMIT statements reversed.
   * Fix it by changing '= 1' to '= 0' and vice versa in transaction function
   * calls in Driver/mysqli.php
   *
   */
  public function beginTransaction()
  {
    $this->dbh_rw->beginTransaction();
  }

  /**
   * Commits the current transaction.
   */
  public function commit()
  {
    $this->dbh_rw->commit();
  }

  /**
   * Rolls back the current transction.
   */
  public function rollback()
  {
    $this->dbh_rw->rollback();
  }

  /**
   * Echoes the debug output from the active MDB2 connection handlers.
   */
  public function getDebugOutput()
  {
    return "RO:\n" . $this->dbh_ro->getDebugOutput()
      . "\n\nRW:\n" . $this->dbh_rw->getDebugOutput();
  }
}

/**
 * Interface for a class set up to be a record wrapper.
 *
 * @package Site
 */
interface SiteDatabaseRecordWrapper {
  public function getRow();
}

/**
 * Result set wrapper class for SiteDatabase
 *
 * This class (or a define subclass) is what is returned from any select query.
 * It handles providing details about the result set, and then iterating over
 * the result resource to obtain the required records, if necessary.
 *
 * It implements Iterator, ArrayAccess, and Countable so that it can be treated,
 * in most cases, like an array of result records.
 *
 * @package Site
 */
class SiteDatabaseResult implements Iterator, ArrayAccess, Countable {
  /**
   * Resource pointing back to the query results returned by an MDB2 query.
   * @var object
   */
  protected $res;
  /**
   * Where to start pulling records in the result resource.
   * @var int
   */
  protected $start;
  /**
   * How many records to pull from the result resource.
   * @var int
   */
  protected $count;
  /**
   * The raw data pulled from the result resource so far.
   * @var array
   */
  protected $rows;
  /**
   * The raw data for the last record pulled from the result resource.
   * @var array
   */
  protected $cur_row;
  /**
   * The next position to pull from when iterating through the result resource.
   * @var int
   */
  protected $fetch_pos;
  /**
   * The next position to pull from when iterating the resources already pulled.
   * @var int
   */
  protected $iter_pos;
  /**
   * Whether rewind has been called at least once.
   * @var bool
   */
  protected $first_rewind;
  /**
   * Whether all the records in the result resource have been fetched.
   * @var bool
   */
  protected $fetched;
  /**
   * The name of the class to use as a wrapper for each record.
   * @var string
   */
  protected $wrap_class;
  /**
   * The name of the function to call that will returned a wrapped record.
   * @var string
   */
  protected $wrap_func;
  /**
   * Total number of records in the result resource.
   * @var int
   */
  protected $num_rows;

  /**
   * Initializes state, including iterator state, based on the parameters
   * (iterator will start at $start if provided, etc).
   *
   * @param object $res Query result resource
   * @param int $count Total number of records to account for in the result.
   * @param int $start Index of the first record in the result set to pull.
   * @param string $wrap_class Name of the class to use as the record wrapper.
   */
  public function __construct($res, $count = null, $start = null, $wrap_class = null)
  {
    if ($start > 1) {
      $res->seek($start - 1);
    }
    else {
      $start = 1;
    }

    $this->num_rows = $res->numRows();
    if (MDB2::isError($this->num_rows)) {
      $this->num_rows = 0;
    }

    $remainder = max($this->num_rows - $start + 1, 0);

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

  /**
   * Sets the class to use as the record wrapper.
   *
   * @param string $wrap_class Class name.
   */
  public function setWrapClass($wrap_class)
  {
    if (!is_null($wrap_class)) {
      if (!in_array('SiteDatabaseRecordWrapper', class_implements($wrap_class))) {
        throw new Exception("Invalid wrapper class '$wrap_class'.");
      }
    }
    $this->wrap_class = $wrap_class;
  }

  /**
   * Sets the function to use as the record wrapper.
   *
   * @param string $f Function name.
   */
  public function setWrapFunc($f)
  {
    $this->wrap_func = $f;
  }

  // for debugging
  public function inspect($var)
  {
    return $this->$var;
  }

  /**
   * Gets the number of rows in the records in the result resource.
   *
   * @return int Number of records.
   */
  public function numRows()
  {
    return $this->num_rows;
  }

  /**
   * Fetches the next row from the result resource.
   *
   * @return object Wrapper class around the next result.
   */
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

  /**
   * Initializes the wrapper class with the raw data.
   *
   * @param array $row Raw data from a query result.
   * @return object Instance of wrapper class containing the input row.
   */
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

  /**
   * Wraps multiple rows at once.
   *
   * @param array $rows Array of raw data from a query result.
   * @return object Array of instances of wrapper class containing the input rows.
   */
  protected function wrapRows($rows)
  {
    $w = array();
    foreach ($rows as $row) {
      $w[] = $this->wrapRow($row);
    }
    return $w;
  }

  /**
   * Fetches rows from the results resource.
   *
   * @param int $i Index to start pulling records from.
   * @return array Array of wrapped rows.
   */
  public function fetchRows($i = null)
  {
    if (is_null($i)) {
      $i = $this->count - 1;
    }
    // note order of conditionals is important
    while($this->fetch_pos <= $i && $this->fetchRow());
    return $this->wrapRows($this->rows);
  }

  /**
   * Fetches rows and groups them by a particular field.
   *
   * @param string $field Field to group records by
   * @return array Assoc array of wrapped records, grouped by $field.
   */
  public function fetchGrouped($field)
  {
    $this->fetchRows();
    $groups = array();
    foreach ($this->rows as $row) {
      $groups[$row[$field]][] = $this->wrapRow($row);
    }
    return $groups;
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
    $this->first_rewind = true;
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
 * Model class manages interface between modeled tables and database.
 *
 * @package Site
 */
class SiteDatabaseModel {
  protected $db;
  protected $fields;
  protected $tables;
  protected $tables_path;
  protected $model_record_class;

  /**
   * SiteDatabaseModel contructor.
   *
   * @param object $database Instance of SiteDatabase
   * @param string $tables_path Path to the table (model) classes.
   * @param string $model_record_class Name of record wrapper class.
   */
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

  /**
   * Magical getter for accessing table class instance ($site->db->tableName-> ...)
   * 
   * @param string $table_name Name of table.
   */
  public function __get($table_name)
  {
    $this->initModel($table_name);
    return @$this->tables[$table_name];
  }

  /**
   * Returns database field info.
   *
   * @param string $table_name
   * @param string $sub Subset of field records to return.
   * @return array All field info, or $sub subset.
   */
  public function getFieldInfo($table_name, $sub = null)
  {
    return $this->db->getFieldInfo($table_name, $sub);
  }

  /**
   * Creates a new instance of $table_name record model class.
   *
   * @param string $table_name
   * @param array $row Initial values to load into record instance.
   * @param bool $exists Does this record instance reflect an actual DB record?
   * @param bool $dirty Has any value in this instance changed?
   * @return object Instance of model_record_class containing $row.
   */
  public function create($table_name, $row = null, $exists = false, $dirty = false)
  {
    $this->initModel($table_name);
    return $this->tables[$table_name]->create($row, $exists, $dirty);
  }

  /**
   * Get the name of the class we're using to wrap $table_name records.
   *
   * @param string $table_name
   * @return string Name of model wrapper class.
   */
  public function getRecordClass($table_name)
  {
    $this->initModel($table_name);
    return $this->tables[$table_name]->getRecordClass();
  }

  /**
   * Returns a single record (the first one returned by the db) matching the
   * criteria provided.  Simplifies querying for a single record.
   *
   * @param string $table_name
   * @param string $where Conditions for the WHERE clause for the query.
   * @param array $values Bind values for the $where
   * @return object Instance of model wrapper class for first record matching
   * criteria, or null if not found.
   */
  public function get($table_name, $where, $values = null)
  {
    $this->initModel($table_name);

    $row = $this->db->getFirst($table_name, $where, $values);

    if (is_null($row)) {
      return null;
    }

    return $this->create($table_name, $row, /*exists=*/true);
  }

  /**
   * Returns all records in a table.
   *
   * @param string $table_name Table to query.
   * @param string $orderby Fields for the ORDER BY clause.
   * @param int $count Number of records to return.  null = all
   * @param int $start Index of first record to return.  null = 0
   * @param string $indexby (NOT YET IMPLEMENTED)
   * @return object An instance of result_class, using model_record_class as a wrapper.
   */
  public function all($table_name, $orderby = null, $count = null, $start = null, $indexby = null)
  {
    return $this->search($table_name, null, null, $orderby, $count, $start, $indexby);
  }

  /**
   * Calls db search, then ensures that the records returned from the result set
   * are wrapped in the model class.
   * 
   * @param string $table_name Table to query.
   * @param string $where Conditions for the WHERE clause of the select.
   * @param array $values Bind values for WHERE clause.
   * @param string $orderby Fields for the ORDER BY clause.
   * @param int $count Number of records to return.  null = all
   * @param int $start Index of first record to return.  null = 0
   * @param string $indexby (NOT YET IMPLEMENTED)
   * @return object An instance of result_class, using model_record_class as a wrapper.
   */
  public function search($table_name, $where = null, $values = null, $orderby = null, $count = null, $start = null, $indexby = null)
  {
    $this->initModel($table_name);

    $res = $this->db->search($table_name, $where, $values, $orderby, $count, $start, $indexby);

    // The results wrapper class returned by the modeled table needs to, of course,
    // returned model class wrappers, not the standard record wrappers.  An easy
    // way to do this is to ensure that the model controller's create function
    // is used.  This does that.
    $o = $this;
    $res->setWrapFunc(function ($row) use ($o, $table_name) {
      return $o->create($table_name, $row, /*exists=*/true);
    });

    return $res;
  }

  /**
   * Does an insert into $table_name with the values in $row and returns a
   * modeled instance of the record.
   *
   * @param string $table_name
   * @param array $row Values to insert.
   * @return object Instance of modeled record class containing the inserted record.
   */
  public function insert($table_name, $row)
  {
    $this->initModel($table_name);

    // update the auto-int if one exists.
    $id = $this->db->insert($table_name, $row);
    if ($auto = $this->getFieldInfo($table_name, 'auto')) {
      $row[$auto] = $id;
    }

    return $this->create($table_name, $row, /*exists=*/true, /*dirty=*/true);
  }

  /**
   * Updates the table records matching the provided criteria.
   *
   * @param string $table_name Table to update.
   * @param string $where Conditions for the WHERE clause of the update.
   * @param array $values Bind values for the WHERE.
   * @param array $update_values Assoc array of (column => value) pairs
   * containing the data to update.
   */
  public function update($table_name, $where, $values, $row)
  {
    $this->initModel($table_name);
    $this->db->update($table_name, $where, $values, $row);
  }

  /**
   * Delete the table records matching the provided criteria.
   *
   * @param string $table_name Table to delete from.
   * @param string $where Conditions for the WHERE clause of the delete.
   * @param array $values Bind values for the WHERE.
   */
  public function delete($table_name, $where, $values)
  {
    $this->initModel($table_name);
    $this->db->delete($table_name, $where, $values);
  }

  /**
   * Initializes the model handler for a particular table.
   *
   * @param string $table_name
   */
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

/**
 * Base handler class for a modeled table.
 */
class SiteDatabaseModelTable {
  /**
   * Reference back to the model class.
   * @var object
   */
  protected $model;
  /**
   * Name of the table modeled by this class.
   * @var string
   */
  protected $table_name;
  /**
   * Name of the class used to wrap each record.
   * @var string
   */
  protected $record_class;
  /**
   * Cache for related record queries.
   * @var array
   */
  protected $rel_cache;

  /**
   * @param object $model Link back to the model controller class.
   * @param string $table_name Table to model.
   * @param string $record_class Name of class, if any, to use as record wrapper.
   */
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

  /**
   * Simple table name getter.
   *
   * @return string Name of table modeled.
   */
  public function getTableName()
  {
    return $this->table_name;
  }

  /**
   * Queries the model controller to get the field info for the current table.
   *
   * @param string $sub Subset of fields to return, if any.
   * @return array Assoc array of requested info.
   */
  public function getFieldInfo($sub = null)
  {
    return $this->model->getFieldInfo($this->table_name, $sub);
  }

  /**
   * Instantiates and returns a record wrapper.
   *
   * @param mixed $row Assoc array of (column => value), or instance of wrapper object.
   * @param bool $exists Record exists in the database.
   * @param bool $dirty Record has local updates.
   * @return object Record wrapped in a model_record_class instance.
   */
  public function create($row = null, $exists = false, $dirty = false)
  {
    $c = $this->record_class;
    if (is_object($row) && class_implements($row, 'SiteDatabaseRecordWrapper')) {
      $row = $row->getRow();
    }
    return new $c($this, $row, $exists, $dirty);
  }

  /**
   * Simple getter for the name of the record wrapper class.
   *
   * @return string Name of record class.
   */
  public function getRecordClass()
  {
    return $this->record_class;
  }

  /**
   * Calls model controller's get method to return a single record matching the
   * criteria provided, defaulting the modeled table name.
   *
   * @param string $where Conditions for the WHERE clause for the query
   * @param array $values Bind values for the $where
   * @return object Instance of first record matching criteria, or null if not found.
   */
  public function get($where, $values = null)
  {
    return $this->model->get($this->table_name, $where, $values);
  }

  /**
   * Calls model controller's get method to return a single record matching the
   * criteria provided, defaulting the modeled table name.
   *
   * @param string $where Conditions for the WHERE clause for the query
   * @param array $values Bind values for the $where
   * @return object Instance of first record matching criteria, or null if not found.
   */
  public function getRelated($table_name, $where, $values)
  {
    $cache_key = $table_name.'-'.$where.serialize($values);
    if (!isset($this->rel_cache[$cache_key])) {
      $this->rel_cache[$cache_key] = $this->model->get($table_name, $where, $values);
    }
    return $this->rel_cache[$cache_key];
  }

  /**
   * Calls the model controller's insert method to insert the values in $row and
   * returns a wrapped instance of the record, defaulting the modeled table name.
   *
   * @param array $row Values to insert.
   * @return object Instance of wrapped class containing the inserted record.
   */
  public function insert($row)
  {
    return $this->model->insert($this->table_name, $row);
  }

  /**
   * Calls the model controller's update method to update the records matching
   * the provided criteria, defaulting the modeled table name.
   *
   * @param string $where Conditions for the WHERE clause of the update.
   * @param array $values Bind values for the WHERE.
   * @param array $update_values Assoc array of (column => value) pairs
   * containing the data to update.
   */
  public function update($where, $values, $row)
  {
    $this->model->update($this->table_name, $where, $values, $row);
  }

  /**
   * Calls the model controller's delete method to delete the table records
   * matching the provided criteria, defaulting the modeled table name.
   *
   * @param string $where Conditions for the WHERE clause of the delete.
   * @param array $values Bind values for the WHERE.
   */
  public function delete($where, $values)
  {
    return $this->model->delete($this->table_name, $where, $values);
  }

  /**
   * Calls the model controller's all method to returns all records in a table,
   * defaulting the modeled table name.
   *
   * @param string $orderby Fields for the ORDER BY clause.
   * @param int $count Number of records to return.  null = all
   * @param int $start Index of first record to return.  null = 0
   * @param string $indexby (NOT YET IMPLEMENTED)
   * @return object An handler instance of result_class for query results.
   */
  public function all($orderby = null, $count = null, $start = null, $indexby = null)
  {
    return $this->model->all($this->table_name, $orderby, $count, $start, $indexby);
  }

  /**
   * Calls the model controller's search method to find all records matching the
   * criteria, ensuring that the records returned from the result set are
   * wrapped in the model class.  The modeled table name is provided by default.
   * 
   * @param string $where Conditions for the WHERE clause of the select.
   * @param array $values Bind values for WHERE clause.
   * @param string $orderby Fields for the ORDER BY clause.
   * @param int $count Number of records to return.  null = all
   * @param int $start Index of first record to return.  null = 0
   * @param string $indexby (NOT YET IMPLEMENTED)
   * @return object An handler instance of result_class for query results.
   */
  public function search($where = null, $values = null, $orderby = null, $count = null, $start = null, $indexby = null)
  {
    return $this->model->search($this->table_name, $where, $values, $orderby, $count, $start, $indexby);
  }

  /**
   * Calls the model controller's search method to find all records matching the
   * criteria for a related table within the same database.
   *
   * @param string $table_name Table to query.
   * @param string $where Conditions for the WHERE clause.
   * @param string $values Bind values for WHERE clause.
   * @param string $orderby Fields for the ORDER BY clause
   * @return object An handler instance of result_class for query results.
   */
  public function searchRelated($table_name, $where = null, $values = null, $orderby = null)
  {
    return $this->model->search($table_name, $where, $values, $orderby);
  }
}

/**
 * A modeled version of the standard record wrapper.
 */
class SiteDatabaseModelRecord implements SiteDatabaseRecordWrapper {
  /**
   * Instance of modeled table handler class.
   * @var object
   */
  protected $table;
  /**
   * Assoc array of (column => value) pairs containing the row values.
   * @var array
   */
  protected $row;
  /**
   * Assoc array of (column => value) pairs containing any unsaved changes made
   * to the row.
   * @var array
   */
  protected $changes;
  /**
   * Does this record tie to a record that already exists in the database?
   * @var bool
   */
  protected $exists;
  /**
   * Is this record fresh from the database?
   * @var bool
   */
  protected $dirty;

  /**
   * SiteDatabaseModelRecord constructor.
   *
   * @param mixed $table SiteDatabaseModelTable (or derived) object.
   * @param mixed $row Assoc array of (column => value) pairs for the row to wrap.
   * @param mixed $exists If the record exists in the DB already.
   * @param mixed $dirty $row Is this record fresh from the database?
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

  /**
   * Magical getter for the record column values.
   *
   * @param string $var Name of column.
   * @return mixed Value contained in $var column, or null if not found.
   */
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

  /**
   * Getter for entire row.
   * 
   * @return array Assoc array of (column => value) pairs for the row.
   */
  public function getRow()
  {
    $this->freshen();
    return $this->row;
  }

  /**
   * Magical setter for the record column values.
   *
   * @param string $var Column name to assign to.
   * @param mixed $val Value to store in the column.
   * @return mixed The value assigned (for chaining).
   */
  public function __set($var, $val)
  {
    return $this->changes[$var] = $val;
    //return $this->row[$var] = $val;
  }

  /**
   * Refreshes the internal row from the associated table record.
   *
   * @param bool $force Normally only freshens if $this->dirty.  This overrides
   * that check.
   */
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

  /**
   * Returns the primary key in an assoc array of (column => value) pairs.
   * 
   * @return array Assoc array containing primary key.
   */
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

  /**
   * Updates the internal row values.
   *
   * @param array $row Assoc array of (column => value) pairs of columns to
   * update.
   */
  public function update($row)
  {
    foreach ($row as $var => $val) {
      $this->__set($var, $val);
    }
  }

  /**
   * Saves the internal row values to the associated database record.
   */
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

  /**
   * Deletes the table record associated with the modeled row.
   */
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

  /**
   * Updates and saves at the same time.
   *
   * @param array $row Assoc array of (column => value) pairs of columns to
   * update.
   */
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
