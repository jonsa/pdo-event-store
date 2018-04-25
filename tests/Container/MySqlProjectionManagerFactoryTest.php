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
use Prooph\EventStore\Pdo\Container\MySqlProjectionManagerFactory;
use Prooph\EventStore\Pdo\Exception\InvalidArgumentException;
use Prooph\EventStore\Pdo\HasQueryHint;
use Prooph\EventStore\Pdo\MySqlEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy;
use Prooph\EventStore\Pdo\Projection\MySqlProjectionManager;
use ProophTest\EventStore\Pdo\TestUtil;
use Psr\Container\ContainerInterface;

/**
 * @group mysql
 */
class MySqlProjectionManagerFactoryTest extends TestCase
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

        $messageFactory = $this->prophesize(MessageFactory::class);
        $persistenceStrategy = $this->prophesize(PersistenceStrategy::class);
        $persistenceStrategy->willImplement(HasQueryHint::class);

        $container = $this->prophesize(ContainerInterface::class);
        $eventStore = new MySqlEventStore(
            $messageFactory->reveal(),
            TestUtil::getConnection(),
            $persistenceStrategy->reveal()
        );

        $container->get('my_connection')->willReturn($connection)->shouldBeCalled();
        $container->get(EventStore::class)->willReturn($eventStore)->shouldBeCalled();
        $container->get('config')->willReturn($config)->shouldBeCalled();

        $factory = new MySqlProjectionManagerFactory();
        $projectionManager = $factory($container->reveal());

        $this->assertInstanceOf(MySqlProjectionManager::class, $projectionManager);
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

        $messageFactory = $this->prophesize(MessageFactory::class);
        $persistenceStrategy = $this->prophesize(PersistenceStrategy::class);
        $persistenceStrategy->willImplement(HasQueryHint::class);

        $container = $this->prophesize(ContainerInterface::class);
        $eventStore = new MySqlEventStore(
            $messageFactory->reveal(),
            TestUtil::getConnection(),
            $persistenceStrategy->reveal()
        );

        $container->get('my_connection')->willReturn($connection)->shouldBeCalled();
        $container->get(EventStore::class)->willReturn($eventStore)->shouldBeCalled();
        $container->get('config')->willReturn($config)->shouldBeCalled();

        $name = 'default';
        $pdo = MySqlProjectionManagerFactory::$name($container->reveal());

        $this->assertInstanceOf(MySqlProjectionManager::class, $pdo);
    }

    /**
     * @test
     */
    public function it_throws_exception_when_invalid_container_given()
    {
        $this->expectException(InvalidArgumentException::class);

        $projectionName = 'custom';
        MySqlProjectionManagerFactory::$projectionName('invalid container');
    }
}
