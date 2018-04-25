<?php
/**
 * This file is part of the prooph/pdo-event-store.
 * (c) 2016-2018 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace ProophTest\EventStore\Pdo\Container;

use PHPUnit\Framework\TestCase;
use Prooph\Common\Messaging\MessageFactory;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Pdo\Container\PostgresProjectionManagerFactory;
use Prooph\EventStore\Pdo\Exception\InvalidArgumentException;
use Prooph\EventStore\Pdo\PersistenceStrategy;
use Prooph\EventStore\Pdo\PostgresEventStore;
use Prooph\EventStore\Pdo\Projection\PostgresProjectionManager;
use ProophTest\EventStore\Pdo\TestUtil;
use Psr\Container\ContainerInterface;

/**
 * @group postgres
 */
class PostgresProjectionManagerFactoryTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_service()
    {
        $config['prooph']['projection_manager']['default'] = [
            'connection' => 'my_connection',
        ];

        $connection = TestUtil::getConnection();

        $container = $this->prophesize(ContainerInterface::class);
        $eventStore = new PostgresEventStore(
            $this->createMock(MessageFactory::class),
            TestUtil::getConnection(),
            $this->createMock(PersistenceStrategy::class)
        );

        $container->get('my_connection')->willReturn($connection)->shouldBeCalled();
        $container->get(EventStore::class)->willReturn($eventStore)->shouldBeCalled();
        $container->get('config')->willReturn($config)->shouldBeCalled();

        $factory = new PostgresProjectionManagerFactory();
        $projectionManager = $factory($container->reveal());

        $this->assertInstanceOf(PostgresProjectionManager::class, $projectionManager);
    }

    /**
     * @test
     */
    public function it_creates_service_via_callstatic()
    {
        $config['prooph']['projection_manager']['default'] = [
            'connection' => 'my_connection',
        ];

        $connection = TestUtil::getConnection();

        $container = $this->prophesize(ContainerInterface::class);
        $eventStore = new PostgresEventStore(
            $this->createMock(MessageFactory::class),
            TestUtil::getConnection(),
            $this->createMock(PersistenceStrategy::class)
        );

        $container->get('my_connection')->willReturn($connection)->shouldBeCalled();
        $container->get(EventStore::class)->willReturn($eventStore)->shouldBeCalled();
        $container->get('config')->willReturn($config)->shouldBeCalled();

        $name = 'default';
        $pdo = PostgresProjectionManagerFactory::$name($container->reveal());

        $this->assertInstanceOf(PostgresProjectionManager::class, $pdo);
    }

    /**
     * @test
     */
    public function it_throws_exception_when_invalid_container_given()
    {
        $this->expectException(InvalidArgumentException::class);

        $projectionName = 'custom';
        PostgresProjectionManagerFactory::$projectionName('invalid container');
    }
}
