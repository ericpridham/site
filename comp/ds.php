<?php
if (!function_exists('is_iterable')) {
  function is_iterable($array)
  {
    return (is_array($array) || $array instanceof Iterator);
  }
}

if (!function_exists('clean_xml_value')) {
  function clean_xml_value($str)
  {
    return str_replace(
      array('&', '"', '<', '>'),
      array('&#38;', '&#34;', '&#60;', '&#62;'),
      trim($str)
    );
  }
}

/*
$ds = $site->ds->new();
$ds->data;
*/
class DataStoreComponent extends SiteComponent {
  public function create()
  {
    return new DataStoreNode();
  }
}

class DataStoreNode implements Iterator, ArrayAccess, Countable {
  protected $value;
  protected $child_name;
  protected $children;
  protected $mode;

  public function __construct($value = null, $children = null, $child_name = 'node')
  {
    $this->mode = 'write';
    $this->child_name = $child_name;
    $this->value($value);
    if (!is_null($children)) {
      $this->addNodes($children);
    }
  }

  public function setMode($new_mode)
  {
    $this->mode = $new_mode;
    if (is_array($this->value)) {
      // pass the mode along to any nodes in the $value
      foreach ($this->value as $v) {
        if ($v instanceof DataStoreNode) {
          $v->setMode($new_mode);
        }
      }
    }
    if (!is_null($this->children)) {
      // pass the mode along to any child nodes
      foreach ($this->children as $node => $child) {
        if ($child instanceof DataStoreNode) {
          $child->setMode($new_mode);
        }
      }
    }
  }

  public function __get($name)
  {
    if ($this->mode == 'write') {
      if (!isset($this->children[$name])) {
        return $this->addNode($name);
      }
      return $this->getNode($name);
    }
    elseif ($this->mode == 'read') {
      // if $name is a child node ...
      if (isset($this->children[$name])) {
        if (!($this->children[$name] instanceof DataStoreNode)) {
          return $this->children[$name];
        }

        $node = $this->children[$name];
        $value = $node->value();
        if (is_null($value)) {
          if ($node->hasChildren()) {
            return $node;
          }
          else {
            return null;
          }
        }
        // otherwise return the value
        else {
          // if $value is an array, then it is an array of DataStoreNodes.
          // check through each node, and if the value is not also an array,
          // return a simple array of the values
          //
          // this allows us to do things like:
          //   $ds->names = array('Abe','Bill','Carol');
          //   $ds->setMode('read');
          //   foreach ($ds->names as $name) {
          //     echo $name; // this will be a string, not a DataStoreNode
          //   }
          //
          if (is_array($value)) {
            if (empty($value)) {
              // OK, so here's this situation.  Say you do:
              //
              // $ds->params = $_GET;
              //
              // Here, it would be nice to be able to do:
              //
              // $p = $ds->params->missingParameter
              //
              // and just have $p be null instead of the call throwing an error.
              // That's what this does.
              $nullNode = new DataStoreNode();
              $nullNode->setMode('read');
              return $nullNode;
            }
            else {
              $is_simple = true;
              foreach ($value as $element) {
                $v = $element->value();
                if (is_array($v) || $element->hasChildren()) {
                  $is_simple = false;
                }
                else {
                  $basic_array[] = $v;
                }
              }
              if ($is_simple) {
                return $basic_array;
              }
            }
          }
          return $value;
        }
      }
      // if the $value is an object that overrides it's own __get, then use that
      elseif (method_exists($this->value, '__get')) {
        return $this->value->__get($name);
      }
      return null;
    }
    throw new Exception("Unsupported mode '{$this->mode}'.");
  }

  /*
   * Supports: getNode('foo'), getNode('foo.bar'), getNode(array('foo','bar')).
   * The last two are equivalent
   */
  public function getNode($name)
  {
    if (is_array($name)) {
      $parts = $name;
      $name = array_shift($parts);
    }
    elseif (strpos($name, '.') !== false) {
      $parts = explode('.', $name);
      $name = array_shift($parts);
    }
    if (!isset($this->children[$name])) {
      return null;
    }
    if (isset($parts) && count($parts)) {
      return $this->children[$name]->getNode($parts);
    }
    return $this->children[$name];
  }

  public function __set($name, $value)
  {
    return $this->addNode($name, $value);
  }

  /*
   * Aliases to make the behavior more explicit but still easy to work with.
   */
  public function get($name)
  {
    return $this->getValue($name);
  }
  public function add($name, $value = null)
  {
    return $this->addNode($name, $value);
  }

  public function getValue($name)
  {
    $node = $this->getNode($name);
    if (is_null($node)) {
      return null;
    }
    if (!($node instanceof DataStoreNode)) {
      return $node;
    }
    return $node->value();
  }

  public function addNodes($values)
  {
    if (!is_assoc($values)) {
      throw new Exception('addNodes: Nodes missing names.');
    }
    $this->value($values);
  }

  /*
   * array (
   *   array (
   *     'key'    => 'IMIT_ItemID',
   *     'group'  => 'items',
   *     'node'   => 'item',
   *     'fields' => 'IMIT',
   *   ),
   *   ...
   * )
   */
  public function addGrouped($rows, $groups)
  {
    $group = array_shift($groups);
    $groupedRows = Utils::group($rows, @$group['key']);

    $nodeRows = array();
    $nodeGroupedRows = array();
    foreach ($groupedRows as $key => $groupedRow) {
      $nodeRows[] = $groupedRow[0]->getRow(@$group['fields'], true);
      $nodeGroupedRows[] = $groupedRow;
    }

    $node = null;
    if (isset($group['group'])) {
      if (isset($group['node'])) {
        $node = $this->addNode($group['group'], array(@$group['node'] => $nodeRows));
      }
      else {
        $node = $this->addNode($group['group'], $nodeRows);
      }
    }
    elseif (count($nodeRows)) {
      $node = array($this->addNode($group['node'], $nodeRows[0]));
    }
    if (!empty($groups) && $node) {
      $i = 0;
      foreach ($node as $n) {
        $n->addGrouped($nodeGroupedRows[$i], $groups);
        ++$i;
      }
    }
    return $node;
  }

  public function addNode($name, $value = null, $raw = false)
  {
    if ($this->mode == 'read') {
      throw new Exception('Datastore is in read mode.');
    }
    if (is_array($name)) {
      $parts = $name;
      $name = array_shift($parts);
    }
    elseif (strpos($name, '.') !== false) {
      $parts = explode('.', $name);
      $name = array_shift($parts);
    }

    if (isset($parts) && count($parts)) {
      if (!isset($this->children[$name])) {
        $this->children[$name] = new DataStoreNode();
      }
      return $this->children[$name]->addNode($parts, $value, $raw);
    }

    if ($raw) {
      $this->children[$name] = $value;
    }
    else {
      $this->children[$name] = new DataStoreNode($value);
    }
    return $this->children[$name];
  }

  public function childName($new_child_name = null)
  {
    if (!is_null($new_child_name)) {
      $this->child_name = $new_child_name;
    }
    return $this->child_name;
  }

  public function hasChildren()
  {
    return !empty($this->children);
  }

  public function getChildren()
  {
    return $this->children;
  }

  public function value($new_value = null)
  {
    if (!is_null($new_value)) {
      if ($this->mode == 'read') {
        throw new Exception('Datastore is in read mode.');
      }
      // assigning a DataStoreNode, just copy members
      if ($new_value instanceof DataStoreNode) {
        $this->value = $new_value->value();
        $this->child_name = $new_value->childName();
        $this->children = $new_value->getChildren();
      }
      else {
        if (is_object($new_value) && method_exists($new_value, 'toDS')) {
          $new_value = $new_value->toDS();
        }
        if (is_assoc($new_value)) {
          foreach ($new_value as $name => $value) {
            $this->addNode($name, $value);
          }
        }
        elseif(is_iterable($new_value)) {
          $this->value = array();
          foreach ($new_value as $v) {
            if (is_array($v) && !empty($v) && !is_assoc($v)) {
              throw new Exception('Cannot have nested indexed arrays.');
            }
            $this->value[] = new DataStoreNode($v);
         }
        }
        // basic value, just assign
        else {
          $this->value = $new_value;
        }
      }
    }
    return $this->value;
  }

  public function toArray($top = true)
  {
    $array = null;
    if ($this->value) {
      if (is_object($this->value) && method_exists($this->value, 'toArray')) {
        $array = $this->value->toArray();
      }
      elseif (is_array($this->value)) {
        foreach ($this->value as $v) {
          $array = $v->toArray();
        }
      }
      else {
        $array = $this->value;
      }
    }
    if (!empty($this->children)) {
      if (is_null($array)) {
        $array = array();
      }
      elseif (!is_array($array)) {
        $array = array($array);
      }
      foreach ($this->children as $name => $child) {
        $array[$name] = $child->toArray();
      }
    }
    return $array;
  }

  public function toString($level = 0)
  {
    $string = '';
    if (!is_null($this->value)) {
      if (is_array($this->value)) {
        $string .= ($this->child_name?:'unnamed') . '()';
        foreach ($this->value as $k => $v) {
          //if ($v instanceof DataStoreNode) {
          $string .= "\n".str_repeat('   ', $level)."[$k]" . $v->toString($level+1);
          //}
          //else {
          //  $string .= ', ' . $this->value;
          //}
        }
      }
      else {
        $string .= $this->value;
      }
    }
    if (!empty($this->children)) {
      foreach ($this->children as $name => $child) {
        $string .= "\n".str_repeat('   ', ($level?$level-1:0)) . ($level?' \-':'')."[{$name}] => ";
        $string .= $child->toString($level+1);
      }
    }
    return $string;
  }

  public function toJson()
  {
    $json = '';
    if ($this->value) {
      if (is_object($this->value) && method_exists($this->value, 'toJson')) {
        $json .= $this->value->toJson();
      }
      elseif (is_array($this->value)) {
        $json .= '[';
        $first = true;
        foreach ($this->value as $v) {
          $json .= ($first?'':',');
          $first = false;
          if (is_object($v) && method_exists($v, 'toJson')) {
            $json .= $v->toJson();
          }
          else {
            $json .= json_encode($v);
          }
        }
        $json .= ']';
      }
      else {
        $json .= json_encode($this->value);
      }
    }
    if (!empty($this->children)) {
      $json .= ($json?$json.',':'')."{";
      $first = true;
      foreach ($this->children as $name => $child) {
        $json .= ($first?'':',')."\"{$name}\":" . $child->toJson();
        $first = false;
      }
      $json .= '}';
    }
    return $json ? $json : '{}';
  }

  public function toXML()
  {
    $xml = '';
    if ($this->value) {
      if (is_object($this->value) && method_exists($this->value, 'toXML')) {
        //$xml .= "<{$this->child_name}>".$this->value->toXML()."</{$this->child_name}>";
        $xml .= $this->value->toXML();
      }
      elseif (is_array($this->value)) {
        foreach ($this->value as $v) {
          $xml .= "<{$this->child_name}>";
          if (is_object($v) && method_exists($v, 'toXML')) {
            $xml .= $v->toXML();
          }
          else {
            $xml .= clean_xml_value($v);
          }
          $xml .= "</{$this->child_name}>";
        }
      }
      else {
        $xml .= clean_xml_value($this->value);
      }
    }
    if (!empty($this->children)) {
      foreach ($this->children as $name => $child) {
        $xml .= "<{$name}>" . $child->toXML() . "</{$name}>";
      }
    }
    return $xml;
  }

  public function toHTML()
  {
    if (!is_null($this->value)){
      if (is_object($this->value) && method_exists($this->value, 'toHTML')){
        $dom = $this->value->toHTML();
      }
      elseif (is_array($this->value)){
        if (!empty($this->value)){
          $dom = '<ul>';

          foreach($this->value as $k=>$v){
            if (is_object($v) && method_exists($v, 'toHTML')){
              $dom .= "<li data-rel=\"data\" data-key=\"$k\"". (is_object($v) && !is_object($v->value) ? " data-value=\"$v->value\"":"") .">";
              $dom .= "<strong>$k</strong>";
              $dom .= $v->toHTML();
              $dom .= "</li>";
            }
            else {
              $dom .= "<li data-rel=\"data\" data-key=\"$k\" data-value=\"$v\">";
              $dom .= "<strong>$k</strong><span>$v</span>";
              $dom .= "</li>";
            }
          }
          
          $dom .='</ul>';
        }
      }
      else {
        $dom = '<span>'. $this->value .'</span>';
      }
    }
    
    if (!empty($this->children)){
      $dom = '<ul>';
      
      foreach ($this->children as $k=>$v){
        $dom .= "</li>";
        if (is_object($v) && method_exists($v, 'toHTML')){
          $dom .= "<li data-rel=\"data\" data-key=\"$k\"". (is_object($v) && !is_object($v->value) ? " data-value=\"$v->value\"":"") .">";
          $dom .= "<strong>$k</strong>";
          $dom .= $v->toHTML();
          $dom .= "</li>";
        }
        else {
          $dom .= "<li data-rel=\"data\" data-key=\"$k\" data-value=\"$v\">";
          $dom .= "<strong>$k</strong><span>$v</span>";
          $dom .= "</li>";
        }
      }

      $dom .= '</ul>';
    }

    return $dom;
  }

  /* Iterator interface */
  protected $iter_pos;
  public function current()
  {
    if (!is_null($this->value)) {
      if (is_array($this->value)) {
        return $this->value[$this->iter_pos];
      }
      elseif ($this->value instanceof Iterator) {
        return $this->value->current();
      }
    }
    elseif ($this->hasChildren()) {
      $k = array_keys($this->children);
      return $this->children[$k[$this->iter_pos]];
    }
    return $this->value;
  }
  public function key()
  {
    if ($this->value instanceof Iterator) {
      return $this->value->key();
    }
    elseif (is_null($this->value) && $this->hasChildren()) {
      $k = array_keys($this->children);
      return $k[$this->iter_pos];
    }
    return $this->iter_pos;
  }
  public function next()
  {
    if ($this->value instanceof Iterator) {
      $this->value->next();
    }
    else {
      ++$this->iter_pos;
    }
  }
  public function rewind()
  {
    if ($this->value instanceof Iterator) {
      $this->value->rewind();
    }
    else {
      $this->iter_pos = 0;
    }
  }
  public function valid()
  {
    if (!is_null($this->value)) {
      if (is_array($this->value)) {
        return isset($this->value[$this->iter_pos]);
      }
      elseif ($this->value instanceof Iterator) {
        return $this->value->valid();
      }
      else {
        if ($this->iter_pos == 0) {
          return true;
        }
        else {
          return false;
        }
      }
    }
    elseif ($this->hasChildren()) {
      $k = array_keys($this->children);
      return isset($k[$this->iter_pos]);
    }
    return false;
  }

  /* Countable interface */
  public function count()
  {
    if (!is_null($this->value)) {
      if (is_array($this->value)) {
        return count($this->value);
      }
      elseif ($this->value instanceof Countable) {
        return $this->value->count();
      }
    }
    elseif ($this->hasChildren()) {
      return count($this->children);
    }

    return 0;
  }

  /* ArrayAccess interface */
  public function offsetSet($i, $v)
  {
    if (is_array($this->value)) {
      $this->value[] = new DataStoreNode($v);
      /*
      if (is_null($i)) {
        return $this->value[] = $v;
      }
      else {
        return $this->value[$i] = $v;
      }
      */
    }
    elseif ($this->value instanceof ArrayAccess) {
      return $this->value->offsetSet($i, $v);
    }
    else {
      if ($i == 0) {
        $this->value = $v;
      }
    }
  }
  public function offsetExists($i)
  {
    if (is_array($this->value)) {
      return isset($this->value[$i]);
    }
    elseif ($this->value instanceof ArrayAccess) {
      return $this->value->offsetExists($i);
    }
    else {
      if ($i == 0) {
        return true;
      }
      else {
        return false;
      }
    }
  }
  public function offsetUnset($i)
  {
    if (is_array($this->value)) {
      unset($this->value[$i]);
    }
    elseif ($this->value instanceof ArrayAccess) {
      $this->value->offsetUnset($i);
    }
    else {
      if ($i == 0) {
        $this->value = null;
      }
    }
  }
  public function offsetGet($i)
  {
    if (is_array($this->value)) {
      return $this->value[$i];
    }
    elseif ($this->value instanceof ArrayAccess) {
      return $this->value->offsetGet($i);
    }
    else {
      if ($i == 0) {
        return $this->value;
      }
      else {
        return null;
      }
    }
  }
}

?>
