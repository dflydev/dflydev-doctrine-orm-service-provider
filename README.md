Doctrine ORM Service Provider
=============================

Provides Doctrine ORM Entity Managers as services to Pimple applications.


Features
--------

 * Leverages the core [Doctrine Service Provider][1] for either
   Silex.
 * Default Entity Manager can be bound to any database connection
 * Multiple Entity Managers can be defined
 * Mechanism for allowing Service Providers to register their own
   mappings


Requirements
------------

 * PHP 5.3+
 * Pimple ~2.1
 * Doctrine ~2.3

Currently requires both **dbs** and **dbs.event_manager** services in
order to work. These can be provided by a Doctrine Service Provider
like the [Silex][1] one. If you can or want to fake it, go for it. :)

 
Installation
------------
 
Through [Composer](http://getcomposer.org) as [dflydev/doctrine-orm-service-provider][6].


Usage
-----

To get up and running, register `DoctrineOrmServiceProvider` and
manually specify the directory that will contain the proxies along
with at least one mapping.

In each of these examples an Entity Manager that is bound to the
default database connection will be provided. It will be accessible
via **orm.em**.

```php
<?php

// Default entity manager.
$em = $app['orm.em'];
```

### Pimple

```php
<?php

use Dflydev\Provider\DoctrineOrm\DoctrineOrmServiceProvider;
use Pimple\Container;

$container = new Container;

$container["db.options"] = array(
    "driver" => "pdo_sqlite",
    "path" => "/path/to/sqlite.db",
);

// ensure that $container['dbs'] and $container['dbs.event_manager']
// are available, most likely by way of a core service provider.

$container->register(new DoctrineOrmServiceProvider, array(
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
            // XML/YAML driver (Symfony2 style)
            // Mapping files can be named like Foo.orm.yml
            // instead of Baz.Entities.Foo.dcm.yml
            array(
                "type" => "simple_yml",
                "namespace" => "Baz\Entities",
                "path" => __DIR__."/src/Bat/Resources/config/doctrine",
            ),
        ),
    ),
));
```

### Silex

```php
<?php

use Dflydev\Provider\DoctrineOrm\DoctrineOrmServiceProvider;
use Silex\Application;
use Silex\Provider\DoctrineServiceProvider;

$app = new Application;

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
        ),
    ),
));
```

Configuration
-------------

### Parameters

 * **orm.em.options**:
   Array of Entity Manager options.

   These options are available:
   * **connection** (Default: default):
     String defining which database connection to use. Used when using
     named databases via **dbs**.
   * **mappings**:
     Array of mapping definitions.

     Each mapping definition should be an array with the following
     options:
     * **type**: Mapping driver type, one of `annotation`, `xml`, `yml`, `simple_xml`, `simple_yml` or `php`.
     * **namespace**: Namespace in which the entities reside.
     
     *New: the `simple_xml` and `simple_yml` driver types were added in v1.1 and provide support for the [simplified XML driver][8] and [simplified YAML driver][9] of Doctrine.*

     Additionally, each mapping definition should contain one of the
     following options:
     * **path**: Path to where the mapping files are located. This should
       be an actual filesystem path. For the php driver it can be an array
       of paths
     * **resources_namespace**: A namespaceish path to where the mapping
       files are located. Example: `Path\To\Foo\Resources\mappings`

     Each mapping definition can have the following optional options:
     * **alias** (Default: null): Set the alias for the entity namespace.

     Each **annotation** mapping may also specify the following options:
     * **use_simple_annotation_reader** (Default: true):
       If `true`, only simple notations like `@Entity` will work.
       If `false`, more advanced notations and aliasing via `use` will
       work. (Example: `use Doctrine\ORM\Mapping AS ORM`, `@ORM\Entity`)
       Note that if set to `false`, the `AnnotationRegistry` will probably
       need to be configured correctly so that it can load your Annotations
       classes. See this FAQ:
       [Why aren't my Annotations classes being found?](#why-arent-my-annotations-classes-being-found)
   * **query_cache** (Default: setting specified by orm.default_cache):
     String or array describing query cache implementation.
   * **metadata_cache** (Default: setting specified by orm.default_cache):
     String or array describing metadata cache implementation.
   * **result_cache** (Default: setting specified by orm.default_cache):
     String or array describing result cache implementation.
   * **hydration_cache** (Default: setting specified by orm.default_cache):
     String or array describing hydration cache implementation.
   * **types**
     An array of custom types in the format of 'typeName' => 'Namespace\To\Type\Class'
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
 * **orm.ems.default** (Default: first Entity Manager processed):
   String defining the name of the default Entity Manager.
 * **orm.proxies_dir**:
   String defining path to where Doctrine generated proxies should be located.
 * **orm.proxies_namespace** (Default: DoctrineProxy):
   String defining namespace in which Doctrine generated proxies should reside.
 * **orm.auto_generate_proxies**:
   Boolean defining whether or not proxies should be generated automatically.
 * **orm.class_metadata_factory_name**: Class name of class metadata factory.
   Class implements `Doctrine\Common\Persistence\Mapping\ClassMetadataFactory`.
 * **orm.default_repository_class**: Class name of default repository.
   Class implements `Doctrine\Common\Persistence\ObjectRepository`.
 * **orm.repository_factory**: Repository factory, instance `Doctrine\ORM\Repository\RepositoryFactory`.
 * **orm.entity_listener_resolver**: Entity listener resolver, instance
   `Doctrine\ORM\Mapping\EntityListenerResolver`.
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
 * **orm.strategy**:
   * **naming**: Naming strategy, instance `Doctrine\ORM\Mapping\NamingStrategy`.
   * **quote**: Quote strategy, instance `Doctrine\ORM\Mapping\QuoteStrategy`.
 * **orm.custom.functions**:
   * **string**, **numeric**, **datetime**: Custom DQL functions, array of class names indexed by DQL function name.
     Classes are subclasses of `Doctrine\ORM\Query\AST\Functions\FunctionNode`.
   * **hydration_modes**: Hydrator class names, indexed by hydration mode name.
     Classes are subclasses of `Doctrine\ORM\Internal\Hydration\AbstractHydrator`.
  

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

### Why is there no manager registry implementation?

There is allready a thirdparty `ManagerRegistry` implementation at [saxulum-doctrine-orm-manager-registry-provider][7].
It support the `entity` type known of the form component, adds the `UniqueEntity` validator and a command line integration.


License
-------

MIT, see LICENSE.


Community
---------

If you have questions or want to help out, join us in the
[#dflydev][dflydev] or [#silex-php][silex-php] channels on
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
[6]: https://packagist.org/packages/dflydev/doctrine-orm-service-provider
[7]: https://github.com/saxulum/saxulum-doctrine-orm-manager-registry-provider
[8]: http://docs.doctrine-project.org/en/latest/reference/xml-mapping.html#simplified-xml-driver
[9]: http://docs.doctrine-project.org/en/latest/reference/yaml-mapping.html#simplified-yaml-driver

[dflydev]: irc://irc.freenode.net/#dflydev
[silex-php]: irc://irc.freenode.net/#silex-php
