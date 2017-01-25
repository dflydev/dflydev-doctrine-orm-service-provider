<?php

/*
 * This file is a part of dflydev/doctrine-orm-service-provider.
 *
 * (c) Dragonfly Development Inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Dflydev\Provider\DoctrineOrm;

use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\MemcacheCache;
use Doctrine\Common\Cache\MemcachedCache;
use Doctrine\Common\Cache\CouchbaseCache;
use Doctrine\Common\Cache\XcacheCache;
use Doctrine\Common\Cache\RedisCache;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\DefaultEntityListenerResolver;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Doctrine\ORM\Mapping\Driver\Driver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedYamlDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\ORM\Mapping\Driver\StaticPHPDriver;
use Doctrine\ORM\Repository\DefaultRepositoryFactory;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

/**
 * Doctrine ORM Pimple Service Provider.
 *
 * @author Beau Simensen <beau@dflydev.com>
 */
class DoctrineOrmServiceProvider implements ServiceProviderInterface
{
    /**
     * Register ORM service.
     *
     * @param Container $container
     */
    public function register(Container $container)
    {
        foreach ($this->getOrmDefaults() as $key => $value) {
            if (!isset($container[$key])) {
                $container[$key] = $value;
            }
        }

        $container['orm.em.default_options'] = array(
            'connection' => 'default',
            'mappings' => array(),
            'types' => array()
        );

        $container['orm.ems.options.initializer'] = $container->protect(function () use ($container) {
            static $initialized = false;

            if ($initialized) {
                return;
            }

            $initialized = true;

            if (!isset($container['orm.ems.options'])) {
                $container['orm.ems.options'] = array('default' => isset($container['orm.em.options']) ? $container['orm.em.options'] : array());
            }

            $tmp = $container['orm.ems.options'];
            foreach ($tmp as $name => &$options) {
                $options = array_replace($container['orm.em.default_options'], $options);

                if (!isset($container['orm.ems.default'])) {
                    $container['orm.ems.default'] = $name;
                }
            }
            $container['orm.ems.options'] = $tmp;
        });

        $container['orm.em_name_from_param_key'] = $container->protect(function ($paramKey) use ($container) {
            $container['orm.ems.options.initializer']();

            if (isset($container[$paramKey])) {
                return $container[$paramKey];
            }

            return $container['orm.ems.default'];
        });

        $container['orm.ems'] = function ($container) {
            $container['orm.ems.options.initializer']();

            $ems = new Container();
            foreach ($container['orm.ems.options'] as $name => $options) {
                if ($container['orm.ems.default'] === $name) {
                    // we use shortcuts here in case the default has been overridden
                    $config = $container['orm.em.config'];
                } else {
                    $config = $container['orm.ems.config'][$name];
                }

                $ems[$name] = function ($ems) use ($container, $options, $config) {
                    return EntityManager::create(
                        $container['dbs'][$options['connection']],
                        $config,
                        $container['dbs.event_manager'][$options['connection']]
                    );
                };
            }

            return $ems;
        };

        $container['orm.ems.config'] = function ($container) {
            $container['orm.ems.options.initializer']();

            $configs = new Container();
            foreach ($container['orm.ems.options'] as $name => $options) {
                $config = new Configuration;

                $container['orm.cache.configurer']($name, $config, $options);

                $config->setProxyDir($container['orm.proxies_dir']);
                $config->setProxyNamespace($container['orm.proxies_namespace']);
                $config->setAutoGenerateProxyClasses($container['orm.auto_generate_proxies']);

                $config->setCustomStringFunctions($container['orm.custom.functions.string']);
                $config->setCustomNumericFunctions($container['orm.custom.functions.numeric']);
                $config->setCustomDatetimeFunctions($container['orm.custom.functions.datetime']);
                $config->setCustomHydrationModes($container['orm.custom.hydration_modes']);

                $config->setClassMetadataFactoryName($container['orm.class_metadata_factory_name']);
                $config->setDefaultRepositoryClassName($container['orm.default_repository_class']);

                $config->setEntityListenerResolver($container['orm.entity_listener_resolver']);
                $config->setRepositoryFactory($container['orm.repository_factory']);

                $config->setNamingStrategy($container['orm.strategy.naming']);
                $config->setQuoteStrategy($container['orm.strategy.quote']);

                $chain = $container['orm.mapping_driver_chain.locator']($name);

                foreach ((array) $options['mappings'] as $mappingOptions) {
                    if (!is_array($mappingOptions)) {
                        throw new \InvalidArgumentException(
                            "The 'orm.em.options' option 'mappings' should be an array of arrays."
                        );
                    }

                    if (isset($mappingOptions['alias'])) {
                        $config->addEntityNamespace($mappingOptions['alias'], $mappingOptions['namespace']);
                    }

                    $driver = $container['orm.mapping.factory']($mappingOptions['type'], $config, $mappingOptions);
                    $chain->addDriver($driver, $mappingOptions['namespace']);
                }
                $config->setMetadataDriverImpl($chain);

                foreach ((array) $options['types'] as $typeName => $typeClass) {
                    if (Type::hasType($typeName)) {
                        Type::overrideType($typeName, $typeClass);
                    } else {
                        Type::addType($typeName, $typeClass);
                    }
                }

                $configs[$name] = $config;
            }

            return $configs;
        };

        $container['orm.mapping.factory.annotation'] = $container->protect(function(Configuration $config, $mappingOptions) {
            $useSimpleAnnotationReader =
                isset($mappingOptions['use_simple_annotation_reader'])
                ? $mappingOptions['use_simple_annotation_reader']
                : true;
            return $config->newDefaultAnnotationDriver((array) $mappingOptions['path'], $useSimpleAnnotationReader);
        });

        $container['orm.mapping.factory.yml'] = $container->protect(function(Configuration $config, $mappingOptions) {
            return new YamlDriver($mappingOptions['path']);
        });

        $container['orm.mapping.factory.simple_yml'] = $container->protect(function(Configuration $config, $mappingOptions) {
            return new SimplifiedYamlDriver(array($mappingOptions['path'] => $mappingOptions['namespace']));
        });

        $container['orm.mapping.factory.xml'] = $container->protect(function(Configuration $config, $mappingOptions) {
            return new XmlDriver($mappingOptions['path']);
        });

        $container['orm.mapping.factory.simple_xml'] = $container->protect(function(Configuration $config, $mappingOptions) {
            return new SimplifiedXmlDriver(array($mappingOptions['path'] => $mappingOptions['namespace']));
        });

        $container['orm.mapping.factory.php'] = $container->protect(function(Configuration $config, $mappingOptions) {
            return new StaticPHPDriver($mappingOptions['path']);
        });

        $container['orm.mapping.factory'] = $container->protect(function ($driver, Configuration $config, $mappingOptions) use ($container) {
            $mappingFactoryKey = 'orm.mapping.factory.'.$driver;
            if (!isset($container[$mappingFactoryKey])) {
                throw new \RuntimeException("Factory '$mappingFactoryKey' for mapping type '$driver' not defined (is it spelled correctly?)");
            }

            return $container[$mappingFactoryKey]($config, $mappingOptions);
        });

        $container['orm.cache.configurer'] = $container->protect(function ($name, Configuration $config, $options) use ($container) {
            $config->setMetadataCacheImpl($container['orm.cache.locator']($name, 'metadata', $options));
            $config->setQueryCacheImpl($container['orm.cache.locator']($name, 'query', $options));
            $config->setResultCacheImpl($container['orm.cache.locator']($name, 'result', $options));
            $config->setHydrationCacheImpl($container['orm.cache.locator']($name, 'hydration', $options));
        });

        $container['orm.cache.locator'] = $container->protect(function ($name, $cacheName, $options) use ($container) {
            $cacheNameKey = $cacheName . '_cache';

            if (!isset($options[$cacheNameKey])) {
                $options[$cacheNameKey] = $container['orm.default_cache'];
            }

            if (isset($options[$cacheNameKey]) && !is_array($options[$cacheNameKey])) {
                $options[$cacheNameKey] = array(
                    'driver' => $options[$cacheNameKey],
                );
            }

            if (!isset($options[$cacheNameKey]['driver'])) {
                throw new \RuntimeException("No driver specified for '$cacheName'");
            }

            $driver = $options[$cacheNameKey]['driver'];

            $cacheInstanceKey = 'orm.cache.instances.'.$name.'.'.$cacheName;
            if (isset($container[$cacheInstanceKey])) {
                return $container[$cacheInstanceKey];
            }

            $cache = $container['orm.cache.factory']($driver, $options[$cacheNameKey]);

            if (isset($options['cache_namespace']) && $cache instanceof CacheProvider) {
                $cache->setNamespace($options['cache_namespace']);
            }

            return $container[$cacheInstanceKey] = $cache;
        });

        $container['orm.cache.factory.backing_memcache'] = $container->protect(function () {
            return new \Memcache;
        });

        $container['orm.cache.factory.memcache'] = $container->protect(function ($cacheOptions) use ($container) {
            if (empty($cacheOptions['host']) || empty($cacheOptions['port'])) {
                throw new \RuntimeException('Host and port options need to be specified for memcache cache');
            }

            /** @var \Memcache $memcache */
            $memcache = $container['orm.cache.factory.backing_memcache']();
            $memcache->connect($cacheOptions['host'], $cacheOptions['port']);

            $cache = new MemcacheCache;
            $cache->setMemcache($memcache);

            return $cache;
        });

        $container['orm.cache.factory.backing_memcached'] = $container->protect(function () {
            return new \Memcached;
        });

        $container['orm.cache.factory.memcached'] = $container->protect(function ($cacheOptions) use ($container) {
            if (empty($cacheOptions['host']) || empty($cacheOptions['port'])) {
                throw new \RuntimeException('Host and port options need to be specified for memcached cache');
            }

            /** @var \Memcached $memcached */
            $memcached = $container['orm.cache.factory.backing_memcached']();
            $memcached->addServer($cacheOptions['host'], $cacheOptions['port']);

            $cache = new MemcachedCache;
            $cache->setMemcached($memcached);

            return $cache;
        });

        $container['orm.cache.factory.backing_redis'] = $container->protect(function () {
            return new \Redis;
        });

        $container['orm.cache.factory.redis'] = $container->protect(function ($cacheOptions) use ($container) {
            if (empty($cacheOptions['host']) || empty($cacheOptions['port'])) {
                throw new \RuntimeException('Host and port options need to be specified for redis cache');
            }

            /** @var \Redis $redis */
            $redis = $container['orm.cache.factory.backing_redis']();
            $redis->connect($cacheOptions['host'], $cacheOptions['port']);

            if (isset($cacheOptions['password'])) {
                $redis->auth($cacheOptions['password']);
            }

            $cache = new RedisCache;
            $cache->setRedis($redis);

            return $cache;
        });

        $container['orm.cache.factory.array'] = $container->protect(function () {
            return new ArrayCache;
        });

        $container['orm.cache.factory.apc'] = $container->protect(function () {
            return new ApcCache;
        });

        $container['orm.cache.factory.xcache'] = $container->protect(function () {
            return new XcacheCache;
        });

        $container['orm.cache.factory.filesystem'] = $container->protect(function ($cacheOptions) {
            if (empty($cacheOptions['path'])) {
                throw new \RuntimeException('FilesystemCache path not defined');
            }

            $cacheOptions += array(
                'extension' => FilesystemCache::EXTENSION,
                'umask' => 0002,
            );
            return new FilesystemCache($cacheOptions['path'], $cacheOptions['extension'], $cacheOptions['umask']);
        });

        $container['orm.cache.factory.couchbase'] = $container->protect(function($cacheOptions){
          $host='';
          $bucketName='';
          $user='';
          $password='';
          if (empty($cacheOptions['host'])) {
            $host='127.0.0.1';
          }
          if (empty($cacheOptions['bucket'])) {
            $bucketName='default';
          }
          if (!empty($cacheOptions['user'])) {
            $user=$cacheOptions['user'];
          }
          if (!empty($cacheOptions['password'])) {
            $password=$cacheOptions['password'];
          }

          $couchbase = new \Couchbase($host,$user,$password,$bucketName);
          $cache = new CouchbaseCache();
          $cache->setCouchbase($couchbase);
          return $cache;
        });

        $container['orm.cache.factory'] = $container->protect(function ($driver, $cacheOptions) use ($container) {
            $cacheFactoryKey = 'orm.cache.factory.'.$driver;
            if (!isset($container[$cacheFactoryKey])) {
                throw new \RuntimeException("Factory '$cacheFactoryKey' for cache type '$driver' not defined (is it spelled correctly?)");
            }

            return $container[$cacheFactoryKey]($cacheOptions);
        });

        $container['orm.mapping_driver_chain.locator'] = $container->protect(function ($name = null) use ($container) {
            $container['orm.ems.options.initializer']();

            if (null === $name) {
                $name = $container['orm.ems.default'];
            }

            $cacheInstanceKey = 'orm.mapping_driver_chain.instances.'.$name;
            if (isset($container[$cacheInstanceKey])) {
                return $container[$cacheInstanceKey];
            }

            return $container[$cacheInstanceKey] = $container['orm.mapping_driver_chain.factory']($name);
        });

        $container['orm.mapping_driver_chain.factory'] = $container->protect(function ($name) use ($container) {
            return new MappingDriverChain;
        });

        $container['orm.add_mapping_driver'] = $container->protect(function (MappingDriver $mappingDriver, $namespace, $name = null) use ($container) {
            $container['orm.ems.options.initializer']();

            if (null === $name) {
                $name = $container['orm.ems.default'];
            }

            /** @var MappingDriverChain $driverChain */
            $driverChain = $container['orm.mapping_driver_chain.locator']($name);
            $driverChain->addDriver($mappingDriver, $namespace);
        });

        $container['orm.strategy.naming'] = function($container) {
            return new DefaultNamingStrategy;
        };

        $container['orm.strategy.quote'] = function($container) {
            return new DefaultQuoteStrategy;
        };

        $container['orm.entity_listener_resolver'] = function($container) {
            return new DefaultEntityListenerResolver;
        };

        $container['orm.repository_factory'] = function($container) {
            return new DefaultRepositoryFactory;
        };

        $container['orm.em'] = function ($container) {
            $ems = $container['orm.ems'];

            return $ems[$container['orm.ems.default']];
        };

        $container['orm.em.config'] = function ($container) {
            $configs = $container['orm.ems.config'];

            return $configs[$container['orm.ems.default']];
        };
    }

    /**
     * Get default ORM configuration settings.
     *
     * @return array
     */
    protected function getOrmDefaults()
    {
        return array(
            'orm.proxies_dir' => __DIR__.'/../../../../../../../../cache/doctrine/proxies',
            'orm.proxies_namespace' => 'DoctrineProxy',
            'orm.auto_generate_proxies' => true,
            'orm.default_cache' => array(
                'driver' => 'array',
            ),
            'orm.custom.functions.string' => array(),
            'orm.custom.functions.numeric' => array(),
            'orm.custom.functions.datetime' => array(),
            'orm.custom.hydration_modes' => array(),
            'orm.class_metadata_factory_name' => 'Doctrine\ORM\Mapping\ClassMetadataFactory',
            'orm.default_repository_class' => 'Doctrine\ORM\EntityRepository',
        );
    }
}
