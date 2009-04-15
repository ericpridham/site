<?php

require_once(dirname(__FILE__).'/database.php');

class SiteModelController extends SiteComponent {
  protected $models;
  protected $db;
  protected $models_path;

  public function __construct($site)
  {
    if (!$site->database) {
      throw new Exception('Model component requires database component.');
    }

    $this->db = $site->database;
    $this->models_path = @$site->ini['model']['models_path'];

    parent::__construct($site);
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

    $model_file = "{$_SERVER['DOCUMENT_ROOT']}{$this->models_path}$table_name.php";
    if (file_exists($model_file)) {
      $new_classes = $this->site->load_classes($model_file);
      foreach ($new_classes as $class) {
        if (get_parent_class($class) == 'SiteModel') {
          $model_class = $class;
        }
        elseif (get_parent_class($class) == 'SiteModelRecord') {
          $model_record_class = $class;
        }
      }
      if (!$model_class) {
        $model_class = "SiteModel";
      }

      $this->models[$table_name] = new $model_class($this, $table_name, $model_record_class);
    }
    else {
      $this->models[$table_name] = new SiteModel($this, $table_name);
    }
  }
}

class SiteModel {
  protected $controller;
  protected $table_name;
  protected $record_class;

  public function __construct($controller, $table, $record_class = null)
  {
    $this->controller = $controller;
    $this->table_name = $table;

    if (is_null($record_class)) {
      $this->record_class = 'SiteModelRecord';
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

  public function get_related($table_name, $id)
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

  public function search_related($table_name, $values = null, $orderby = null)
  {
    return $this->controller->search($table_name, $values, $orderby);
  }

  public function query($where, $values = null, $orderby = null)
  {
    return $this->controller->query($this->table_name, $where, $values, $orderby);
  }
}

class SiteModelRecord {
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

  public function update_and_save($row)
  {
    $this->update($row);
    $this->save();
  }
}

?>
