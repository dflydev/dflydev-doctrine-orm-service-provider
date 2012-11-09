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
Provider to function properly. Currently requires both **dbs** and **dbs.event_manager** services in order to work. If you can or want
to fake it, go for it. :)


Optional Dependencies
---------------------

### PSR-0 Resource Locator Service Provider

An implementation of [dflydev/psr0-resource-locator-service-provider][6]
is required if using namespaceish resource mapping. See documentation
for **orm.generate_psr0_mapping** for more information.

 
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
            // Using actual filesystem paths
            array(
                "type" => "annotation",
                "namespace" => "Foo\Entities",
                "path" => __DIR__."/src/Foo/Entities",
            ),
            array(
                "type" => "xml",
                "namespace" => "Bat\Entities",
                "path" => __DIR__."/src/Bat/Resources/mappings",
            ),
            // Using PSR-0 namespaceish embedded resources
            // (requires registering a PSR-0 Resource Locator
            // Service Provider)
            array(
                "type" => "annotations",
                "namespace" => "Baz\Entities",
                "resources_namespace" => "Baz\Entities",
            ),
            array(
                "type" => "xml",
                "namespace" => "Bar\Entities",
                "resources_namespace" => "Bar\Resources\mappings",
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

Configuration
-------------

### Parameters

 * **orm.em.options**:
   Array of Entity Manager options.

   These options are available:
   * **connection**:
     String defining which database connection to use. Used when using
     named databases via **dbs**.
     *Default: default*
   * **mappings**:
     Array of mapping definitions.

     Each mapping definition should be an array with the following
     options:
     * **type**: Mapping driver type, one of `annotation`, `xml`, or `yml`.
     * **namespace**: Namespace in which the entities reside.

     Additionally, each mapping definition should contain one of the
     following options:
     * **path**: Path to where the mapping files are located. This should
       be an actual filesystem path.
     * **resources_namespace**: A namespaceish path to where the mapping
       files are located. Example: `Path\To\Foo\Resources\mappings`

     Each **annotation** mapping may also specify the following options:
     * **use_simple_annotation_reader**: If `true`, only the notation `@Entity`
       will work, otherwise, the notation `@ORM\Entity` and similar (`@Doctrine\ORM\Mapping\Entity`, ... and so on) will also be supported.
   * **query_cache**:
     String or array describing query cache implementation.
     *Default: setting specified by orm.default_cache*
   * **metadata_cache**:
     String or array describing metadata cache implementation.
     *Default: setting specified by orm.default_cache*
   * **result_cache**:
     String or array describing result cache implementation.
     *Default: setting specified by orm.default_cache*
 * **orm.ems.options**:
   Array of Entity Manager configuration sets indexed by each Entity Manager's
   name. Each value should look like **orm.em.options**.
   
   Example configuration:

   ```php
   <?php
   $app['orm.ems.default'] = 'sqlite';
   $app['orm.ems.options'] = array(
       'mysql' => array(
           'connection' => 'mysql',
           'mappings' => array(), 
       ),
       'sqlite' => array(
           'connection' => 'sqlite',
           'mappings' => array(),
       ),
   );
   ```

   Example usage:

   ```php
   <?php
   $emMysql = $app['orm.ems']['mysql'];
   $emSqlite = $app['orm.ems']['sqlite'];
   ```
 * **orm.ems.default**:
   String defining the name of the default Entity Manager.
   *Default: first Entity Manager processed*
 * **orm.proxies_dir**:
   String defining path to where Doctrine generated proxies should be located.
 * **orm.proxies_namespace**:
   String defining namespace in which Doctrine generated proxies should reside.
   *Default: DoctrineProxy*
 * **orm.auto_generate_proxies**:
   Boolean defining whether or not proxies should be generated automatically.
 * **orm.default_cache**:
   String or array describing default cache implementation.
 * **orm.add_mapping_driver**:
   Function providing the ability to add a mapping driver to an Entity Manager.

   These params are available:
    * **$mappingDriver**:
      Mapping driver to be added,
      instance `Doctrine\Common\Persistence\Mapping\Driver\MappingDriver`.
    * **$namespace**:
      Namespace to be mapped by `$mappingDriver`, string.
    * **$name**:
      Name of Entity Manager to add mapping to, string, default `null`.
 * **orm.em_name_from_param**:
   Function providing the ability to retrieve an entity manager's name from
   a param.

   This is useful for being able to optionally allow users to specify which
   entity manager should be configured for a 3rd party service provider
   but fallback to the default entity manager if not explitely specified.

   For example:

   ```php
   <?php
   $emName = $app['orm.em_name_from_param']('3rdparty.provider.em');
   $em = $app['orm.ems'][$emName];
   ```

   This code should be able to be used inside of a 3rd party service provider
   safely, whether the user has defined `3rdparty.provider.em` or not.
 * **orm.generate_psr0_mapping**:
   Leverages [dflydev/psr0-resource-locator-service-provider][6] to process
   a map of namespaceish resource directories to their mapped entities.

   Example usage:
   ```php
   <?php
   $app['orm.ems.config'] = $app->share('orm.ems.config', $app->extend(function ($config, $app) {
       $mapping = $app['orm.generate_psr0_mapping'](array(
           'Foo\Resources\mappings' => 'Foo\Entities',
           'Bar\Resources\mappings' => 'Bar\Entities',
       ));

       $chain = $app['orm.mapping_driver_chain.locator']();

       foreach ($mapping as $directory => $namespace) {
           $driver = new XmlDriver($directory);
           $chain->addDriver($driver, $namespace);
       }

       return $config;
   }));
   ```

### Services

 * **orm.em**:
   Entity Manager, instance `Doctrine\ORM\EntityManager`.
 * **orm.ems**:
   Entity Managers, array of `Doctrine\ORM\EntityManager` indexed by name.


Frequently Asked Questions
--------------------------

### Why aren't my Annotations classes being found?

When **use_simple_annotation_reader** is set to `False` for an entity,
the `AnnotationRegistry` needs to have the project's autoloader added
to it.

Example:

```php
<?php
$loader = require __DIR__ . '/../vendor/autoload.php';

\Doctrine\Common\Annotations\AnnotationRegistry::registerLoader(array($loader, 'loadClass'));
```

License
-------

MIT, see LICENSE.


Community
---------

If you have questions or want to help out, join us in the
[#dflydev][#dflydev] or [#silex-php][#silex-php] channels on
irc.freenode.net.


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
[6]: http://github.com/dflydev/dflydev-psr0-resource-locator-service-provider

[#dflydev]: irc://irc.freenode.net/#dflydev
[#silex-php]: irc://irc.freenode.net/#silex-php


