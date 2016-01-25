<?php

/*
 * This file is a part of dflydev/doctrine-orm-service-provider.
 *
 * (c) Dragonfly Development Inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Dflydev\Pimple\Provider\DoctrineOrm;

use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\MemcacheCache;
use Doctrine\Common\Cache\MemcachedCache;
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

/**
 * Doctrine ORM Pimple Service Provider.
 *
 * @author Beau Simensen <beau@dflydev.com>
 */
class DoctrineOrmServiceProvider
{
    public function register(\Pimple $app)
    {
        foreach ($this->getOrmDefaults() as $key => $value) {
            if (!isset($app[$key])) {
                $app[$key] = $value;
            }
        }

        $app['orm.em.default_options'] = array(
            'connection' => 'default',
            'mappings' => array(),
            'types' => array()
        );

        $app['orm.ems.options.initializer'] = $app->protect(function () use ($app) {
            static $initialized = false;

            if ($initialized) {
                return;
            }

            $initialized = true;

            if (!isset($app['orm.ems.options'])) {
                $app['orm.ems.options'] = array('default' => isset($app['orm.em.options']) ? $app['orm.em.options'] : array());
            }

            $tmp = $app['orm.ems.options'];
            foreach ($tmp as $name => &$options) {
                $options = array_replace($app['orm.em.default_options'], $options);

                if (!isset($app['orm.ems.default'])) {
                    $app['orm.ems.default'] = $name;
                }
            }
            $app['orm.ems.options'] = $tmp;
        });

        $app['orm.em_name_from_param_key'] = $app->protect(function ($paramKey) use ($app) {
            $app['orm.ems.options.initializer']();

            if (isset($app[$paramKey])) {
                return $app[$paramKey];
            }

            return $app['orm.ems.default'];
        });

        $app['orm.ems'] = $app->share(function($app) {
            $app['orm.ems.options.initializer']();

            $ems = new \Pimple();
            foreach ($app['orm.ems.options'] as $name => $options) {
                if ($app['orm.ems.default'] === $name) {
                    // we use shortcuts here in case the default has been overridden
                    $config = $app['orm.em.config'];
                } else {
                    $config = $app['orm.ems.config'][$name];
                }

                $ems[$name] = $app->share(function ($ems) use ($app, $options, $config) {
                    return EntityManager::create(
                        $app['dbs'][$options['connection']],
                        $config,
                        $app['dbs.event_manager'][$options['connection']]
                    );
                });
            }

            return $ems;
        });

        $app['orm.ems.config'] = $app->share(function($app) {
            $app['orm.ems.options.initializer']();

            $configs = new \Pimple();
            foreach ($app['orm.ems.options'] as $name => $options) {
                $config = new Configuration;

                $app['orm.cache.configurer']($name, $config, $options);

                $config->setProxyDir($app['orm.proxies_dir']);
                $config->setProxyNamespace($app['orm.proxies_namespace']);
                $config->setAutoGenerateProxyClasses($app['orm.auto_generate_proxies']);

                $config->setCustomStringFunctions($app['orm.custom.functions.string']); 
                $config->setCustomNumericFunctions($app['orm.custom.functions.numeric']); 
                $config->setCustomDatetimeFunctions($app['orm.custom.functions.datetime']); 
                $config->setCustomHydrationModes($app['orm.custom.hydration_modes']);

                $config->setClassMetadataFactoryName($app['orm.class_metadata_factory_name']);
                $config->setDefaultRepositoryClassName($app['orm.default_repository_class']);

                $config->setEntityListenerResolver($app['orm.entity_listener_resolver']);
                $config->setRepositoryFactory($app['orm.repository_factory']);

                $config->setNamingStrategy($app['orm.strategy.naming']);
                $config->setQuoteStrategy($app['orm.strategy.quote']);

                $chain = $app['orm.mapping_driver_chain.locator']($name);
                foreach ((array) $options['mappings'] as $entity) {
                    if (!is_array($entity)) {
                        throw new \InvalidArgumentException(
                            "The 'orm.em.options' option 'mappings' should be an array of arrays."
                        );
                    }

                    if (!empty($entity['resources_namespace'])) {
                        $entity['path'] = $app['psr0_resource_locator']->findFirstDirectory($entity['resources_namespace']);
                    }

                    if (isset($entity['alias'])) {
                        $config->addEntityNamespace($entity['alias'], $entity['namespace']);
                    }

                    switch ($entity['type']) {
                        case 'annotation':
                            $useSimpleAnnotationReader =
                                isset($entity['use_simple_annotation_reader'])
                                ? $entity['use_simple_annotation_reader']
                                : true;
                            $driver = $config->newDefaultAnnotationDriver((array) $entity['path'], $useSimpleAnnotationReader);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'yml':
                            $driver = new YamlDriver($entity['path']);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'simple_yml':
                            $driver = new SimplifiedYamlDriver(array($entity['path'] => $entity['namespace']));
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'xml':
                            $driver = new XmlDriver($entity['path']);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'simple_xml':
                            $driver = new SimplifiedXmlDriver(array($entity['path'] => $entity['namespace']));
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'php':
                            $driver = new StaticPHPDriver($entity['path']);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        default:
                            throw new \InvalidArgumentException(sprintf('"%s" is not a recognized driver', $entity['type']));
                            break;
                    }
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
        });

        $app['orm.cache.configurer'] = $app->protect(function($name, Configuration $config, $options) use ($app) {
            $config->setMetadataCacheImpl($app['orm.cache.locator']($name, 'metadata', $options));
            $config->setQueryCacheImpl($app['orm.cache.locator']($name, 'query', $options));
            $config->setResultCacheImpl($app['orm.cache.locator']($name, 'result', $options));
            $config->setHydrationCacheImpl($app['orm.cache.locator']($name, 'hydration', $options));
        });

        $app['orm.cache.locator'] = $app->protect(function($name, $cacheName, $options) use ($app) {
            $cacheNameKey = $cacheName . '_cache';

            if (!isset($options[$cacheNameKey])) {
                $options[$cacheNameKey] = $app['orm.default_cache'];
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
            if (isset($app[$cacheInstanceKey])) {
                return $app[$cacheInstanceKey];
            }

            $cache = $app['orm.cache.factory']($driver, $options[$cacheNameKey]);

            if ($cache instanceof CacheProvider) {
                if (isset($options[$cacheNameKey]['namespace'])) {
                    $cache->setNamespace($options[$cacheNameKey]['namespace']);
                } elseif (isset($options['cache_namespace'])) {
                    $cache->setNamespace($options['cache_namespace']);
                }
            }

            return $app[$cacheInstanceKey] = $cache;
        });

        $app['orm.cache.factory.backing_memcache'] = $app->protect(function() {
            return new \Memcache;
        });

        $app['orm.cache.factory.memcache'] = $app->protect(function($cacheOptions) use ($app) {
            if (empty($cacheOptions['host']) || empty($cacheOptions['port'])) {
                throw new \RuntimeException('Host and port options need to be specified for memcache cache');
            }

            $memcache = $app['orm.cache.factory.backing_memcache']();
            $memcache->connect($cacheOptions['host'], $cacheOptions['port']);

            $cache = new MemcacheCache;
            $cache->setMemcache($memcache);

            return $cache;
        });

        $app['orm.cache.factory.backing_memcached'] = $app->protect(function() {
            return new \Memcached;
        });

        $app['orm.cache.factory.memcached'] = $app->protect(function($cacheOptions) use ($app) {
            if (empty($cacheOptions['host']) || empty($cacheOptions['port'])) {
                throw new \RuntimeException('Host and port options need to be specified for memcached cache');
            }

            $memcached = $app['orm.cache.factory.backing_memcached']();
            $memcached->addServer($cacheOptions['host'], $cacheOptions['port']);

            $cache = new MemcachedCache;
            $cache->setMemcached($memcached);

            return $cache;
        });

        $app['orm.cache.factory.backing_redis'] = $app->protect(function() {
            return new \Redis;
        });

        $app['orm.cache.factory.redis'] = $app->protect(function($cacheOptions) use ($app) {
            if (empty($cacheOptions['host']) || empty($cacheOptions['port'])) {
                throw new \RuntimeException('Host and port options need to be specified for redis cache');
            }

            /** @var \Redis $redis */
            $redis = $app['orm.cache.factory.backing_redis']();
            $redis->connect($cacheOptions['host'], $cacheOptions['port']);

            if (isset($cacheOptions['password'])) {
                $redis->auth($cacheOptions['password']);
            }

            if (isset($cacheOptions['database'])) {
                $redis->select($cacheOptions['database']);
            }

            $cache = new RedisCache;
            $cache->setRedis($redis);

            return $cache;
        });

        $app['orm.cache.factory.array'] = $app->protect(function() {
            return new ArrayCache;
        });

        $app['orm.cache.factory.apc'] = $app->protect(function() {
            return new ApcCache;
        });

        $app['orm.cache.factory.xcache'] = $app->protect(function() {
            return new XcacheCache;
        });

        $app['orm.cache.factory.filesystem'] = $app->protect(function($cacheOptions) {
            if (empty($cacheOptions['path'])) {
                throw new \RuntimeException('FilesystemCache path not defined');
            }

            $cacheOptions += array(
                'extension' => FilesystemCache::EXTENSION,
                'umask' => 0002,
            );
            return new FilesystemCache($cacheOptions['path'], $cacheOptions['extension'], $cacheOptions['umask']);
        });

        $app['orm.cache.factory'] = $app->protect(function($driver, $cacheOptions) use ($app) {
            switch ($driver) {
                case 'array':
                    return $app['orm.cache.factory.array']();
                case 'apc':
                    return $app['orm.cache.factory.apc']();
                case 'xcache':
                    return $app['orm.cache.factory.xcache']();
                case 'memcache':
                    return $app['orm.cache.factory.memcache']($cacheOptions);
                case 'memcached':
                    return $app['orm.cache.factory.memcached']($cacheOptions);
                case 'filesystem':
                    return $app['orm.cache.factory.filesystem']($cacheOptions);
                case 'redis':
                    return $app['orm.cache.factory.redis']($cacheOptions);
                default:
                    throw new \RuntimeException("Unsupported cache type '$driver' specified");
            }
        });

        $app['orm.mapping_driver_chain.locator'] = $app->protect(function($name = null) use ($app) {
            $app['orm.ems.options.initializer']();

            if (null === $name) {
                $name = $app['orm.ems.default'];
            }

            $cacheInstanceKey = 'orm.mapping_driver_chain.instances.'.$name;
            if (isset($app[$cacheInstanceKey])) {
                return $app[$cacheInstanceKey];
            }

            return $app[$cacheInstanceKey] = $app['orm.mapping_driver_chain.factory']($name);
        });

        $app['orm.mapping_driver_chain.factory'] = $app->protect(function($name) use ($app) {
            return new MappingDriverChain;
        });

        $app['orm.add_mapping_driver'] = $app->protect(function(MappingDriver $mappingDriver, $namespace, $name = null) use ($app) {
            $app['orm.ems.options.initializer']();

            if (null === $name) {
                $name = $app['orm.ems.default'];
            }

            $driverChain = $app['orm.mapping_driver_chain.locator']($name);
            $driverChain->addDriver($mappingDriver, $namespace);
        });

        $app['orm.generate_psr0_mapping'] = $app->protect(function($resourceMapping) use ($app) {
            $mapping = array();
            foreach ($resourceMapping as $resourceNamespace => $entityNamespace) {
                $directory = $app['psr0_resource_locator']->findFirstDirectory($resourceNamespace);
                if (!$directory) {
                    throw new \InvalidArgumentException("Resources for mapping '$entityNamespace' could not be located; Looked for mapping resources at '$resourceNamespace'");
                }
                $mapping[$directory] = $entityNamespace;
            }

            return $mapping;
        });

        $app['orm.strategy.naming'] = $app->share(function($app) {
            return new DefaultNamingStrategy;
        });

        $app['orm.strategy.quote'] = $app->share(function($app) {
            return new DefaultQuoteStrategy;
        });

        $app['orm.entity_listener_resolver'] = $app->share(function($app) {
            return new DefaultEntityListenerResolver;
        });

        $app['orm.repository_factory'] = $app->share(function($app) {
            return new DefaultRepositoryFactory;
        });

        $app['orm.em'] = $app->share(function($app) {
            $ems = $app['orm.ems'];

            return $ems[$app['orm.ems.default']];
        });

        $app['orm.em.config'] = $app->share(function($app) {
            $configs = $app['orm.ems.config'];

            return $configs[$app['orm.ems.default']];
        });
    }

    /**
     * Get default ORM configuration settings.
     *
     * @param Application $app Application
     *
     * @return array
     */
    protected function getOrmDefaults()
    {
        return array(
            'orm.proxies_dir' => __DIR__.'/../../../../../../../../cache/doctrine/proxies',
            'orm.proxies_namespace' => 'DoctrineProxy',
            'orm.auto_generate_proxies' => true,
            'orm.default_cache' => 'array',
            'orm.custom.functions.string' => array(),
            'orm.custom.functions.numeric' => array(),
            'orm.custom.functions.datetime' => array(),
            'orm.custom.hydration_modes' => array(),
            'orm.class_metadata_factory_name' => 'Doctrine\ORM\Mapping\ClassMetadataFactory',
            'orm.default_repository_class' => 'Doctrine\ORM\EntityRepository',
        );
    }
}
