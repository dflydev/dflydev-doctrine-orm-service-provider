Doctrine ORM Service Provider
=============================

Provides Doctrine ORM Entity Managers as services to Silex applications.


Features
--------

 * Leverages the core [Doctrine Service Provider][1]
 * Default Entity Manager can be bound to any database connection
 * Multiple Entity Managers can be defined
 * Mechanism for allowing Service Providers to register their own
   mappings


Requirements
------------

 * PHP 5.3+
 * Doctrine ~2.3

The [Doctrine Service Provider][1] (or something looking a whole lot
like it) **must** be available in order for Doctrine ORM Service
Provider to function properly.
 
 
Installation
------------
 
Through [Composer](http://getcomposer.org)


Usage
-----

To get up and running, register `DoctrineOrmServiceProvider` and
manually specify the directory that will contain the proxies along
with at least one mapping.

```php
<?php

use Dflydev\Silex\Provider\DoctrineOrm\DoctrineOrmServiceProvider;
use Silex\Application;
use Silex\Provider\DoctrineServiceProvider;

$app = new Applicaton;

$app->register(new DoctrineServiceProvider, array(
    "db.options" => array(
        "driver" => "pdo_sqlite",
        "path" => "/path/to/sqlite.db",
    ),
));

$app->register(new DoctrineOrmServiceProvider, array(
    "orm.proxies_dir" => "/path/to/proxies",
    "orm.em.options" => array(
        "mappings" => array(
            array(
                "type" => "annotation",
                "path" => __DIR__."/Entity",
                "namespace" => "Entity",
            ),
        ),
    ),
));
```

This will provide access to an Entity Manager that is bound to
the default database connection. It is accessible via **orm.em**.

```php
<?php

// Default entity manager.
$em = $app['orm.em'];
```


License
-------

MIT, see LICENSE.


Community
---------

If you have questions or want to help out, join us in the
[#dflydev][#dflydev] channel on irc.freenode.net.


Not Invented Here
-----------------

This project is based heavily on both the core
[Doctrine Service Provider][1] and the work done by [@docteurklein][2]
on the [docteurklein/silex-doctrine-service-providers][3] project.
Some inspiration was also taken from [Doctrine Bundle][4] and
[Doctrine Bridge][5].


[1]: http://silex.sensiolabs.org/doc/providers/doctrine.html
[2]: https://github.com/docteurklein
[3]: https://github.com/docteurklein/SilexServiceProviders
[4]: https://github.com/doctrine/DoctrineBundle
[5]: https://github.com/symfony/symfony/tree/master/src/Symfony/Bridge/Doctrine

[#dflydev]: irc://irc.freenode.net/#dflydev


