<?php
class SiteLog extends SiteComponent implements Iterator, ArrayAccess, Serializable {
  protected $log;
  protected $iter_pos; // Iterator

  protected function init()
  {
    $this->log = array();
    $this->iter_pos = 0; // Iterator
  }

  public function log($type, $msg, $extra = null)
  {
    $bt = array();
    foreach(debug_backtrace(false) as $line) {
      unset($line['args']);
      $bt[] = $line;
    }
    $this->log[] = array(
      'type'  => $type,
      'msg'   => $msg,
      'ts'    => microtime(true),
      'extra' => $extra,
      'bt'    => $bt,
    );
  }

  public function get($type = null)
  {
    if (is_null($type)) {
      return $this->log;
    }

    throw new Exception('NOT YET IMPLEMENTED');
  }

  public function __call($name, $args)
  {
    switch ($name) {
      case 'info':
      case 'warn':
      case 'error':
      case 'debug':
        array_unshift($args, $name);
        return call_user_func_array(array($this,'log'), $args);
    }
  }

  /* Iterator interface */
  public function current() { return $this->log[$this->iter_pos]; }
  public function key() { return $this->iter_pos; }
  public function next() { ++$this->iter_pos; }
  public function rewind() { $this->iter_pos = 0; }
  public function valid() { return isset($this->log[$this->iter_pos]); }

  /* ArrayAccess interface */
  public function offsetSet($i, $v) { $this->log[$i] = $v; }
  public function offsetExists($i) { return isset($this->log[$i]); }
  public function offsetUnset($i) { unset($this->log[$i]); }
  public function offsetGet($i) { return isset($this->log[$i])?$this->log[$i]:null; }

  /* Serializable interface */
  public function serialize() { return serialize($this->log); }
  public function unserialize($d) { $this->log = unserialize($d); }
}
?>
