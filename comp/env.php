<?php
class EnvironmentComponent extends SiteComponent
{
  protected $env;
  public function init($env = null)
  {
    if (is_null($env) || !is_array($env)) {
      $this->env = array(
        'post'   => $_POST,
        'get'    => $_GET,
        'cookie' => $_COOKIE,
        'server' => $_SERVER,
        'shell'  => $_ENV,
      );
      if (isset($this->conf['session'])) {
        @session_start();
        $this->env['session'] = $_SESSION;
      }
      foreach ($this->env['cookie'] as $name => $value) {
        if (strpos($value, ':') !== false) {
          $parsed = array();
          foreach (explode(';', $value) as $kv) {
            list ($k, $v) = explode(':', $kv, 2);
            $parsed[$k] = $v;
          }
          $this->env['cookie'][$name] = $parsed;
        }
      }
    }
    else {
      $this->env = env;
    }
  }

  public function get($var, $source = null) 
  {
    if (!is_null($source)) {
      if (isset($this->env[$source][$var])) {
        return $this->env[$source][$var];
      }
      return null;
    }

    $order = array('post', 'get', 'cookie', 'server', 'shell', 'session');
    foreach ($order as $source) {
      if (isset($this->env[$source][$var])) {
        return $this->env[$source][$var];
      }
    }
    return null;
  }

  public function getAll($source = null, $except = null)
  {
    if (is_null($source)) {
      $return = $this->env;
    }
    elseif (isset($this->env[$source])) {
      $return = $this->env[$source];
    }

    if (!isset($return)) {
      return null;
    }

    if (is_array($except)) {
      foreach ($except as $e) {
        if (isset($return[$e])) {
          unset($return[$e]);
        }
      }
    }

    return $return;
  }

  public function scrub($var, $source, $type, $default = null)
  {
    $value = $this->get($var, $source);

    switch ($type) {
      case 'alphanum':
        $valid = is_string($value) && preg_match('/^[\w]*$/', $value);
        break;

      case 'number':
      case 'numeric':
        $valid = is_numeric($value);
        break;

      case 'float':
        $valid = is_float($value);
        break;

      case 'int':
      case 'integer':
        $valid = is_int($value);
        break;

      case 'bool':
      case 'boolean':
        if (is_numeric($value)) {
          $value = $value != 0;
        }
        elseif (is_string($value)) {
          if (strtolower($value) === 'true') {
            $value = true;
          }
          elseif (strtolower($value) === 'false') {
            $value = false;
          }
        }
        $valid = is_bool($value);
        break;

      case 'array':
        if (!is_array($value)) {
          if (strlen($value) == 0) {
            $value = null;
          }
          else {
            // accept comma/newline delimited string
            $value = preg_split('/[,\n]/', $value);
          }
        }
        $valid = is_array($value);
        break;

      case 'date':
        $valid = is_string($value) && $value != '' && ($timestamp = @strtotime($value)) !== -1;
        if ($valid) {
          // return a consistent format
          $value = date('Y-m-d', $timestamp);
        }
        elseif (!is_null($default) && ($timestamp = @strtotime($default)) !== -1) {
          $default = date('Y-m-d', $timestamp);
        }
        break;

      case 'datetime':
        $valid = is_string($value) && $value != '' && ($timestamp = @strtotime($value)) !== -1;
        if ($valid) {
          // return a consistent format
          $value = date('Y-m-d H:i:s', $timestamp);
        }
        elseif (!is_null($default) && ($timestamp = @strtotime($default)) !== -1) {
          $default = date('Y-m-d H:i:s', $timestamp);
        }
        break;

      case 'url':
        // note: {} regex delimiters
        $valid = is_string($value) && preg_match('{^(https?://)?[\w-./]+(\?[\w-&%= ]*)?(#[\w-]+)?$}', $value);
        break;

      case 'email':
        $valid = is_string($value) && preg_match('/^\w+@(\w+\.)+(\w+)$/', $value);
        break;

      default:
        $valid = false;
        break;
    }

    if ($valid) {
      return $value;
    }
    else {
      return $default;
    }
  }

  public function set($source, $var, $val)
  {
    if (isset($this->env[$source])) {
      $this->env[$source][$var] = $val;
    }

    switch ($source) {
      case 'session':
        $_SESSION[$var] = $val;
        break;

      case 'cookie':
        setcookie($var, $val);
        break;
    }
  }
}
?>
