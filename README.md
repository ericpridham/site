#$site - minimalist PHP framework

##Overview

`$site` is a minimalist, modular framework that is made to be extended.  The goal was to have as little magic as possible - everything should be central, explicit, and obvious.

Included are some basic modules to get started (database, templates, email) as well as a central configuration point in easily understood YAML.  You are then encouraged to extend `$site` with all your custom code and libraries so everything lives in one place.

##How it works

Just clone this repository in a PHP include_path then:

```
require_once('site/site.php');
$site = new Site('mysite.yaml');
```

Put any site-level configuration options at the top level of your YAML, and access them with `$site->getConf('mysetting')`.

Components are all accessed through the `$site` instance.

```
$site->email->send(...)
$site->db->...
```

Adding components is easy.  Just set the `addon_path` in the YAML and put your custom component files in there.  The component name is going to be the filename minus the .php, and all you need in the file is a class that `extends SiteComponent`.  The class name doesn't matter to `$site`.

And that's pretty much it!

##Requirements

* PHP YAML module
* Smarty (tpl component)
* MDB2 (db/dbs components)
