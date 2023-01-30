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
use PHPUnit\Framework\TestCase;

/**
 * DoctrineOrmServiceProvider Test.
 *
 * @author Beau Simensen <beau@dflydev.com>
 */
class DoctrineOrmServiceProviderTest extends TestCase
{
    protected function createMockDefaultAppAndDeps()
    {
        $container = new Container();

        $eventManager = $this->getMockBuilder(\Doctrine\Common\EventManager::class)->getMock();
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
        $this->assertInstanceOf('Symfony\Component\Cache\Adapter\ArrayAdapter', $container['orm.em.config']->getQueryCache());
        $this->assertInstanceOf('Symfony\Component\Cache\Adapter\ArrayAdapter', $container['orm.em.config']->getResultCache());
        $this->assertInstanceOf('Symfony\Component\Cache\Adapter\ArrayAdapter', $container['orm.em.config']->getMetadataCache());
        $this->assertInstanceOf('Symfony\Component\Cache\Adapter\ArrayAdapter', $container['orm.em.config']->getHydrationCache());
        $this->assertInstanceOf('Doctrine\Persistence\Mapping\Driver\MappingDriverChain', $container['orm.em.config']->getMetadataDriverImpl());
    }

    /**
     * Test registration (test equality for defined implementations)
     */
    public function testRegisterDefinedImplementations()
    {
        $container = $this->createMockDefaultApp();

        $queryCache = $this->getMockBuilder(\Symfony\Component\Cache\Adapter\ArrayAdapter::class)->getMock();
        $resultCache = $this->getMockBuilder(\Symfony\Component\Cache\Adapter\ArrayAdapter::class)->getMock();
        $metadataCache = $this->getMockBuilder(\Symfony\Component\Cache\Adapter\ArrayAdapter::class)->getMock();

        $mappingDriverChain = $this->getMockBuilder(\Doctrine\Persistence\Mapping\Driver\MappingDriverChain::class)->getMock();

        $container['orm.cache.instances.default.query'] = $queryCache;
        $container['orm.cache.instances.default.result'] = $resultCache;
        $container['orm.cache.instances.default.metadata'] = $metadataCache;

        $container['orm.mapping_driver_chain.instances.default'] = $mappingDriverChain;

        $container->register(new DoctrineOrmServiceProvider);

        $this->assertEquals($container['orm.em'], $container['orm.ems']['default']);
        $this->assertEquals($queryCache, $container['orm.em.config']->getQueryCache());
        $this->assertEquals($resultCache, $container['orm.em.config']->getResultCache());
        $this->assertEquals($metadataCache, $container['orm.em.config']->getMetadataCache());
        $this->assertEquals($mappingDriverChain, $container['orm.em.config']->getMetadataDriverImpl());
    }

    /**
     * Test proxy configuration (defaults)
     */
    public function testProxyConfigurationDefaults()
    {
        $container = $this->createMockDefaultApp();

        $container->register(new DoctrineOrmServiceProvider);

        $this->assertStringContainsString('/../../../../../../../cache/doctrine/proxies', $container['orm.em.config']->getProxyDir());
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

        $entityRepositoryClassName = get_class($this->getMockBuilder(\Doctrine\Persistence\ObjectRepository::class)->getMock());
        $metadataFactoryName = get_class($this->getMockBuilder(\Doctrine\Persistence\Mapping\ClassMetadataFactory::class)->getMock());

        $entityListenerResolver = $this->getMockBuilder(\Doctrine\ORM\Mapping\EntityListenerResolver::class)->getMock();
        $repositoryFactory = $this->getMockBuilder(\Doctrine\ORM\Repository\RepositoryFactory::class)->getMock();

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

        $mappingDriver = $this->getMockBuilder(\Doctrine\Persistence\Mapping\Driver\MappingDriver::class)->getMock();

        $mappingDriverChain = $this->getMockBuilder(\Doctrine\Persistence\Mapping\Driver\MappingDriverChain::class)->getMock();
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

        $mappingDriver = $this->getMockBuilder(\Doctrine\Persistence\Mapping\Driver\MappingDriver::class)->getMock();

        $mappingDriverChain = $this->getMockBuilder(\Doctrine\Persistence\Mapping\Driver\MappingDriverChain::class)->getMock();
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
            $this->assertEquals("Factory 'orm.cache.factory.INVALID' for cache type 'INVALID' not defined (is it spelled correctly?)", $e->getMessage());
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
            $this->assertEquals("Factory 'orm.cache.factory.INVALID' for cache type 'INVALID' not defined (is it spelled correctly?)", $e->getMessage());
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
                array(
                    'type' => 'annotation',
                    'namespace' => 'Foo\Entities',
                    'path' => __DIR__.'/src/Foo/Entities',
                    'cache' => null,
                ),
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
                    'alias' => $alias,
                    'cache' => null,
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

        $namingStrategy = $this->getMockBuilder(\Doctrine\ORM\Mapping\DefaultNamingStrategy::class)->getMock();
        $quoteStrategy = $this->getMockBuilder(\Doctrine\ORM\Mapping\DefaultQuoteStrategy::class)->getMock();

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

        $numericFunction = $this->getMockBuilder(\Doctrine\ORM\Query\AST\Functions\FunctionNode::class, array(), array('mynum'));
        $stringFunction = $this->getMockBuilder(\Doctrine\ORM\Query\AST\Functions\FunctionNode::class, array(), array('mynum'));
        $datetimeFunction = $this->getMockBuilder(\Doctrine\ORM\Query\AST\Functions\FunctionNode::class, array(), array('mynum'));

        $app['orm.custom.functions.string'] = array('mystring' => $numericFunction);
        $app['orm.custom.functions.numeric'] = array('mynumeric' => $stringFunction);
        $app['orm.custom.functions.datetime'] = array('mydatetime' => $datetimeFunction);

        $this->assertEquals($numericFunction, $app['orm.em.config']->getCustomStringFunction('mystring'));
        $this->assertEquals($numericFunction, $app['orm.em.config']->getCustomNumericFunction('mynumeric'));
        $this->assertEquals($numericFunction, $app['orm.em.config']->getCustomDatetimeFunction('mydatetime'));
    }
}
