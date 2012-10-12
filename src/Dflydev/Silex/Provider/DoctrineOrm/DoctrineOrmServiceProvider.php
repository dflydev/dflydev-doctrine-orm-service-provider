<?php

/*
 * This file is a part of dflydev/doctrine-orm-service-provider.
 *
 * (c) Dragonfly Development Inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Dflydev\Silex\Provider\DoctrineOrm;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\Driver\DriverChain;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Cache\ApcCache;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\MemcacheCache;
use Doctrine\Common\Cache\XcacheCache;
use Silex\Application;
use Silex\ServiceProviderInterface;

/**
 * Doctrine ORM Silex Service Provider.
 *
 * @author Beau Simensen <beau@dflydev.com>
 */
class DoctrineOrmServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function boot(Application $app)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function register(Application $app)
    {
        foreach ($this->getOrmDefaults() as $key => $value) {
            if (!isset($app[$key])) {
                $app[$key] = $value;
            }
        }

        $app['orm.em.default_options'] = array(
            'connection' => 'default',
        );

        $app['orm.ems.options.initializer'] = $app->protect(function () use ($app) {
            static $initialized = false;

            if ($initialized) {
                return;
            }

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

                $ems[$name] = EntityManager::create($app['dbs'][$options['connection']], $config);
            }

            return $ems;
        });

        $app['orm.ems.config'] = $app->share(function($app) {
            $app['orm.ems.options.initializer']();

            $configs = new \Pimple();
            foreach ($app['orm.ems.options'] as $name => $options) {
                $config = new Configuration;

                $app['orm.cache.configurer']($config, $options);

                $config->setProxyDir($app['orm.proxies_dir']);
                $config->setProxyNamespace($app['orm.proxies_namespace']);
                $config->setAutoGenerateProxyClasses($app['orm.auto_generate_proxies']);

                $chain = $app['orm.driver_chain.locator']($name);
                foreach ((array) $options['mappings'] as $entity) {
                    switch ($entity['type']) {
                        case 'annotation':
                            $reader = new AnnotationReader;
                            $driver = new AnnotationDriver($reader, (array) $entity['path']);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'yml':
                            $driver = new YamlDriver((array) $entity['path']);
                            $driver->setFileExtension('.yml');
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'xml':
                            $driver = new XmlDriver((array) $entity['path'], $entity['namespace']);
                            $driver->setFileExtension('.xml');
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        default:
                            throw new \InvalidArgumentException(sprintf('"%s" is not a recognized driver', $entity['type']));
                            break;
                    }
                }
                $config->setMetadataDriverImpl($chain);

                $configs[$name] = $config;
            }

            return $configs;
        });

        $app['orm.cache.configurer'] = $app->protect(function(Configuration $config, $options) use ($app) {
            $config->setMetadataCacheImpl($app['orm.cache.locator']('metadata_cache', $options);
            $config->setQueryCacheImpl($app['orm.cache.locator']('query_cache', $options);
            $config->setResultCacheImpl($app['orm.cache.locator']('result_cache', $options);
        });

        $app['orm.cache.locator'] = $app->protect(function($cacheName, $options) use ($app) {
            $driver = isset($options[$cacheName]['driver'])
                ? $options[$cacheName]['driver']
                : $app['orm.default_cache_driver'];

            $cacheInstanceKey = 'orm.cache.instances.'.$driver;
            if (isset($app[$cacheInstanceKey])) {
                return $app[$cacheInstanceKey];
            }

            $cacheOptions = isset($options[$cacheName]['options'])
                ? $options[$cacheName]['options']
                : $app['orm.default_cache_options'];

            return $app[$cacheInstanceKey] = $app['orm.cache.factory']($driver, $cacheOptions);
        });

        $app['orm.cache.factory'] = $app->protect(function($driver, $cacheOptions) use ($app) {
            switch ($driver) {
                case 'array':
                    return new ArrayCache;
                case 'apc':
                    return new ApcCache;
                case 'xcache':
                    return new XcacheCache;
                case 'memcache':
                    // @TODO: Finish this.
                    return new MemcacheCache;
                default:
                    throw new \RuntimeException("Unsupported cache specified");
            }
        });

        $app['orm.driver_chain.locator'] = $app->protect(function($name = null) use ($app) {
            $app['orm.ems.options.initializer']();

            if (null === $name) {
                $name = $app['orm.ems.default'];
            }

            $cacheInstanceKey = 'orm.driver_chain.instances.'.$name;
            if (isset($app[$cacheInstanceKey])) {
                return $app[$cacheInstanceKey];
            }

            return $app[$cacheInstanceKey] = $app['orm.driver_chain.factory']($name);
        });

        $app['orm.driver_chain.factory'] = $app->protect(function($name) use ($app) {
            return new DriverChain;
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
    protected function getOrmDefaults(Application $app)
    {
        return = array(
            'orm.proxies_dir' => __DIR__.'/../../../../../cache/doctrine/Proxy',
            'orm.proxies_namespace' => 'DoctrineProxy',
            'orm.auto_generate_proxies' => true,
            'orm.default_cache_driver' => 'array',
            'orm.default_cache_options' => array(),
        );
    }
}
