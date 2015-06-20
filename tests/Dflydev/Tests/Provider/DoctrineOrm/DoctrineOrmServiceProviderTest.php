<?php

/*
 * This file is a part of dflydev/doctrine-orm-service-provider.
 *
 * (c) Dragonfly Development Inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Dflydev\Tests\Provider\DoctrineOrm;

use Dflydev\Provider\DoctrineOrm\DoctrineOrmServiceProvider;
use Pimple\Container;

/**
 * DoctrineOrmServiceProvider Test.
 *
 * @author Beau Simensen <beau@dflydev.com>
 */
class DoctrineOrmServiceProviderTest extends \PHPUnit_Framework_TestCase
{
    protected function createMockDefaultAppAndDeps()
    {
        $container = new Container();

        $eventManager = $this->getMock('Doctrine\Common\EventManager');
        $connection = $this
            ->getMockBuilder('Doctrine\DBAL\Connection')
            ->disableOriginalConstructor()
            ->getMock();

        $connection
            ->expects($this->any())
            ->method('getEventManager')
            ->will($this->returnValue($eventManager));

        $container['dbs'] = new Container(array(
            'default' => $connection,
        ));

        $container['dbs.event_manager'] = new Container(array(
            'default' => $eventManager,
        ));

        return array($container, $connection, $eventManager);;
    }

    /**
     * @return Container
     */
    protected function createMockDefaultApp()
    {
        list ($container, $connection, $eventManager) = $this->createMockDefaultAppAndDeps();

        return $container;
    }

    /**
     * Test registration (test expected class for default implementations)
     */
    public function testRegisterDefaultImplementations()
    {
        $container = $this->createMockDefaultApp();

        $container->register(new DoctrineOrmServiceProvider());

        $this->assertEquals($container['orm.em'], $container['orm.ems']['default']);
        $this->assertInstanceOf('Doctrine\Common\Cache\ArrayCache', $container['orm.em.config']->getQueryCacheImpl());
        $this->assertInstanceOf('Doctrine\Common\Cache\ArrayCache', $container['orm.em.config']->getResultCacheImpl());
        $this->assertInstanceOf('Doctrine\Common\Cache\ArrayCache', $container['orm.em.config']->getMetadataCacheImpl());
        $this->assertInstanceOf('Doctrine\Common\Cache\ArrayCache', $container['orm.em.config']->getHydrationCacheImpl());
        $this->assertInstanceOf('Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain', $container['orm.em.config']->getMetadataDriverImpl());
    }

    /**
     * Test registration (test equality for defined implementations)
     */
    public function testRegisterDefinedImplementations()
    {
        $container = $this->createMockDefaultApp();

        $queryCache = $this->getMock('Doctrine\Common\Cache\ArrayCache');
        $resultCache = $this->getMock('Doctrine\Common\Cache\ArrayCache');
        $metadataCache = $this->getMock('Doctrine\Common\Cache\ArrayCache');

        $mappingDriverChain = $this->getMock('Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain');

        $container['orm.cache.instances.default.query'] = $queryCache;
        $container['orm.cache.instances.default.result'] = $resultCache;
        $container['orm.cache.instances.default.metadata'] = $metadataCache;

        $container['orm.mapping_driver_chain.instances.default'] = $mappingDriverChain;

        $container->register(new DoctrineOrmServiceProvider);

        $this->assertEquals($container['orm.em'], $container['orm.ems']['default']);
        $this->assertEquals($queryCache, $container['orm.em.config']->getQueryCacheImpl());
        $this->assertEquals($resultCache, $container['orm.em.config']->getResultCacheImpl());
        $this->assertEquals($metadataCache, $container['orm.em.config']->getMetadataCacheImpl());
        $this->assertEquals($mappingDriverChain, $container['orm.em.config']->getMetadataDriverImpl());
    }

    /**
     * Test proxy configuration (defaults)
     */
    public function testProxyConfigurationDefaults()
    {
        $container = $this->createMockDefaultApp();

        $container->register(new DoctrineOrmServiceProvider);

        $this->assertContains('/../../../../../../../cache/doctrine/proxies', $container['orm.em.config']->getProxyDir());
        $this->assertEquals('DoctrineProxy', $container['orm.em.config']->getProxyNamespace());
        $this->assertEquals(1,$container['orm.em.config']->getAutoGenerateProxyClasses());
    }

    /**
     * Test proxy configuration (defined)
     */
    public function testProxyConfigurationDefined()
    {
        $container = $this->createMockDefaultApp();

        $container->register(new DoctrineOrmServiceProvider);

        $entityRepositoryClassName = get_class($this->getMock('Doctrine\Common\Persistence\ObjectRepository'));
        $metadataFactoryName = get_class($this->getMock('Doctrine\Common\Persistence\Mapping\ClassMetadataFactory'));

        $entityListenerResolver = $this->getMock('Doctrine\ORM\Mapping\EntityListenerResolver');
        $repositoryFactory = $this->getMock('Doctrine\ORM\Repository\RepositoryFactory');

        $container['orm.proxies_dir'] = '/path/to/proxies';
        $container['orm.proxies_namespace'] = 'TestDoctrineOrmProxiesNamespace';
        $container['orm.auto_generate_proxies'] = false;
        $container['orm.class_metadata_factory_name'] = $metadataFactoryName;
        $container['orm.default_repository_class'] = $entityRepositoryClassName;
        $container['orm.entity_listener_resolver'] = $entityListenerResolver;
        $container['orm.repository_factory'] = $repositoryFactory;
        $container['orm.custom.hydration_modes'] = array('mymode' => 'Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator');

        $this->assertEquals('/path/to/proxies', $container['orm.em.config']->getProxyDir());
        $this->assertEquals('TestDoctrineOrmProxiesNamespace', $container['orm.em.config']->getProxyNamespace());
        $this->assertEquals(0,$container['orm.em.config']->getAutoGenerateProxyClasses());
        $this->assertEquals($metadataFactoryName, $container['orm.em.config']->getClassMetadataFactoryName());
        $this->assertEquals($entityRepositoryClassName, $container['orm.em.config']->getDefaultRepositoryClassName());
        $this->assertEquals($entityListenerResolver, $container['orm.em.config']->getEntityListenerResolver());
        $this->assertEquals($repositoryFactory, $container['orm.em.config']->getRepositoryFactory());
        $this->assertEquals('Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator', $container['orm.em.config']->getCustomHydrationMode('mymode'));
    }

    /**
     * Test Driver Chain locator
     */
    public function testMappingDriverChainLocator()
    {
        $container = $this->createMockDefaultApp();

        $container->register(new DoctrineOrmServiceProvider);

        $default = $container['orm.mapping_driver_chain.locator']();
        $this->assertEquals($default, $container['orm.mapping_driver_chain.locator']('default'));
        $this->assertEquals($default, $container['orm.em.config']->getMetadataDriverImpl());
    }

    /**
     * Test adding a mapping driver (use default entity manager)
     */
    public function testAddMappingDriverDefault()
    {
        $container = $this->createMockDefaultApp();

        $mappingDriver = $this->getMock('Doctrine\Common\Persistence\Mapping\Driver\MappingDriver');

        $mappingDriverChain = $this->getMock('Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain');
        $mappingDriverChain
            ->expects($this->once())
            ->method('addDriver')
            ->with($mappingDriver, 'Test\Namespace');

        $container['orm.mapping_driver_chain.instances.default'] = $mappingDriverChain;

        $container->register(new DoctrineOrmServiceProvider);

        $container['orm.add_mapping_driver']($mappingDriver, 'Test\Namespace');
    }

    /**
     * Test adding a mapping driver (specify default entity manager by name)
     */
    public function testAddMappingDriverNamedEntityManager()
    {
        $container = $this->createMockDefaultApp();

        $mappingDriver = $this->getMock('Doctrine\Common\Persistence\Mapping\Driver\MappingDriver');

        $mappingDriverChain = $this->getMock('Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain');
        $mappingDriverChain
            ->expects($this->once())
            ->method('addDriver')
            ->with($mappingDriver, 'Test\Namespace');

        $container['orm.mapping_driver_chain.instances.default'] = $mappingDriverChain;

        $container->register(new DoctrineOrmServiceProvider);

        $container['orm.add_mapping_driver']($mappingDriver, 'Test\Namespace');
    }

    /**
     * Test specifying an invalid cache type (just named)
     */
    public function testInvalidCacheTypeNamed()
    {
        $container = $this->createMockDefaultApp();

        $container->register(new DoctrineOrmServiceProvider);

        $container['orm.em.options'] = array(
            'query_cache' => 'INVALID',
        );

        try {
            $container['orm.em'];

            $this->fail('Expected invalid query cache driver exception');
        } catch (\RuntimeException $e) {
            $this->assertEquals("Unsupported cache type 'INVALID' specified", $e->getMessage());
        }
    }

    /**
     * Test specifying an invalid cache type (driver as option)
     */
    public function testInvalidCacheTypeDriverAsOption()
    {
        $container = $this->createMockDefaultApp();

        $container->register(new DoctrineOrmServiceProvider);

        $container['orm.em.options'] = array(
            'query_cache' => array(
                'driver' => 'INVALID',
            ),
        );

        try {
            $container['orm.em'];

            $this->fail('Expected invalid query cache driver exception');
        } catch (\RuntimeException $e) {
            $this->assertEquals("Unsupported cache type 'INVALID' specified", $e->getMessage());
        }
    }

    /**
     * Test orm.em_name_from_param_key ()
     */
    public function testNameFromParamKey()
    {
        $container = $this->createMockDefaultApp();

        $container['my.baz'] = 'baz';

        $container->register(new DoctrineOrmServiceProvider);

        $container['orm.ems.default'] = 'foo';

        $this->assertEquals('foo', $container['orm.ems.default']);
        $this->assertEquals('foo', $container['orm.em_name_from_param_key']('my.bar'));
        $this->assertEquals('baz', $container['orm.em_name_from_param_key']('my.baz'));
    }

    /**
     * Test specifying an invalid mapping configuration (not an array of arrays)
     *
     * @expectedException        \InvalidArgumentException
     * @expectedExceptionMessage The 'orm.em.options' option 'mappings' should be an array of arrays.
     */
    public function testInvalidMappingAsOption()
    {
        $container = $this->createMockDefaultApp();

        $container->register(new DoctrineOrmServiceProvider);

        $container['orm.em.options'] = array(
            'mappings' => array(
                'type' => 'annotation',
                'namespace' => 'Foo\Entities',
                'path' => __DIR__.'/src/Foo/Entities',
            ),
        );

        $container['orm.ems.config'];
    }

    /**
     * Test if namespace alias can be set through the mapping options
     */
    public function testMappingAlias()
    {
        $container = $this->createMockDefaultApp();

        $container->register(new DoctrineOrmServiceProvider);

        $alias = 'Foo';
        $namespace = 'Foo\Entities';

        $container['orm.em.options'] = array(
            'mappings' => array(
                array(
                    'type' => 'annotation',
                    'namespace' => $namespace,
                    'path' => __DIR__.'/src/Foo/Entities',
                    'alias' => $alias
                )
            ),
        );

        $this->assertEquals($namespace, $container['orm.em.config']->getEntityNameSpace($alias));
    }

    public function testStrategy()
    {
        $app = $this->createMockDefaultApp();

        $doctrineOrmServiceProvider = new DoctrineOrmServiceProvider;
        $doctrineOrmServiceProvider->register($app);

        $namingStrategy = $this->getMock('Doctrine\ORM\Mapping\DefaultNamingStrategy');
        $quoteStrategy = $this->getMock('Doctrine\ORM\Mapping\DefaultQuoteStrategy');

        $app['orm.strategy.naming'] = $namingStrategy;
        $app['orm.strategy.quote'] = $quoteStrategy;

        $this->assertEquals($namingStrategy, $app['orm.em.config']->getNamingStrategy());
        $this->assertEquals($quoteStrategy, $app['orm.em.config']->getQuoteStrategy());
    }

    public function testCustomFunctions()
    {
        $app = $this->createMockDefaultApp();

        $doctrineOrmServiceProvider = new DoctrineOrmServiceProvider;
        $doctrineOrmServiceProvider->register($app);

        $numericFunction = $this->getMock('Doctrine\ORM\Query\AST\Functions\FunctionNode', array(), array('mynum'));
        $stringFunction = $this->getMock('Doctrine\ORM\Query\AST\Functions\FunctionNode', array(), array('mynum'));
        $datetimeFunction = $this->getMock('Doctrine\ORM\Query\AST\Functions\FunctionNode', array(), array('mynum'));

        $app['orm.custom.functions.string'] = array('mystring' => $numericFunction);
        $app['orm.custom.functions.numeric'] = array('mynumeric' => $stringFunction);
        $app['orm.custom.functions.datetime'] = array('mydatetime' => $datetimeFunction);

        $this->assertEquals($numericFunction, $app['orm.em.config']->getCustomStringFunction('mystring'));
        $this->assertEquals($numericFunction, $app['orm.em.config']->getCustomNumericFunction('mynumeric'));
        $this->assertEquals($numericFunction, $app['orm.em.config']->getCustomDatetimeFunction('mydatetime'));
    }
}
