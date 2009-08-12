<?php

function newConf($name, $conf_str)
{
  file_put_contents(getConf($name), $conf_str);
}

function getConf($name)
{
  return dirname(__FILE__)."/$name.yaml";
}

function killConf($name)
{
  unlink(getConf($name));
}

?>
