<?php
/**
 * This file is part of the prooph/pdo-event-store.
 * (c) 2016-2018 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace ProophTest\EventStore\Pdo\Projection;

use ArrayIterator;
use PDO;
use Prooph\Common\Messaging\Message;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\EventStoreDecorator;
use Prooph\EventStore\Exception\InvalidArgumentException;
use Prooph\EventStore\Pdo\Projection\PdoEventStoreReadModelProjector;
use Prooph\EventStore\Projection\ProjectionManager;
use Prooph\EventStore\Projection\ReadModel;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;
use ProophTest\EventStore\Mock\ReadModelMock;
use ProophTest\EventStore\Mock\UserCreated;
use ProophTest\EventStore\Mock\UsernameChanged;
use ProophTest\EventStore\Pdo\TestUtil;
use ProophTest\EventStore\Projection\AbstractEventStoreReadModelProjectorTest;

abstract class PdoEventStoreReadModelProjectorTest extends AbstractEventStoreReadModelProjectorTest
{
    /**
     * @var ProjectionManager
     */
    protected $projectionManager;

    /**
     * @var EventStore
     */
    protected $eventStore;

    /**
     * @var PDO
     */
    protected $connection;

    protected function tearDown()
    {
        TestUtil::tearDownDatabase();
    }

    protected function prepareEventStream($name)
    {
        $events = [];
        $events[] = UserCreated::with([
            'name' => 'Alex',
        ], 1);
        for ($i = 2; $i < 50; $i++) {
            $events[] = UsernameChanged::with([
                'name' => uniqid('name_'),
            ], $i);
        }
        $events[] = UsernameChanged::with([
            'name' => 'Sascha',
        ], 50);

        $this->eventStore->create(new Stream(new StreamName($name), new ArrayIterator($events)));
    }

    /**
     * @test
     */
    public function it_updates_read_model_using_when_and_loads_and_continues_again()
    {
        $this->prepareEventStream('user-123');

        $readModel = new ReadModelMock();

        $projection = $this->projectionManager->createReadModelProjection('test_projection', $readModel);

        $projection
            ->fromAll()
            ->when([
                UserCreated::class => function ($state, Message $event) {
                    $this->readModel()->stack('insert', 'name', $event->payload()['name']);
                },
                UsernameChanged::class => function ($state, Message $event) {
                    $this->readModel()->stack('update', 'name', $event->payload()['name']);

                    if ($event->metadata()['_aggregate_version'] === 50) {
                        $this->stop();
                    }
                },
            ])
            ->run();

        $this->assertEquals('Sascha', $readModel->read('name'));

        $events = [];
        for ($i = 51; $i < 100; $i++) {
            $events[] = UsernameChanged::with([
                'name' => uniqid('name_'),
            ], $i);
        }
        $events[] = UsernameChanged::with([
            'name' => 'Oliver',
        ], 100);

        $this->eventStore->appendTo(new StreamName('user-123'), new ArrayIterator($events));

        $projection = $this->projectionManager->createReadModelProjection('test_projection', $readModel);

        $projection
            ->fromAll()
            ->when([
                UserCreated::class => function ($state, Message $event) {
                    $this->readModel()->stack('insert', 'name', $event->payload()['name']);
                },
                UsernameChanged::class => function ($state, Message $event) {
                    $this->readModel()->stack('update', 'name', $event->payload()['name']);

                    if ($event->metadata()['_aggregate_version'] === 100) {
                        $this->stop();
                    }
                },
            ])
            ->run();

        $this->assertEquals('Oliver', $readModel->read('name'));

        $projection->reset();

        $this->assertFalse($readModel->hasKey('name'));
    }

    /**
     * @test
     */
    public function it_throws_exception_when_invalid_wrapped_event_store_instance_passed()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown event store instance given');

        $eventStore = $this->prophesize(EventStore::class);
        $wrappedEventStore = $this->prophesize(EventStoreDecorator::class);
        $wrappedEventStore->getInnerEventStore()->willReturn($eventStore->reveal())->shouldBeCalled();

        new PdoEventStoreReadModelProjector(
            $wrappedEventStore->reveal(),
            $this->connection,
            'test_projection',
            new ReadModelMock(),
            'event_streams',
            'projections',
            1,
            1,
            1
        );
    }

    /**
     * @test
     */
    public function it_throws_exception_when_unknown_event_store_instance_passed()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown event store instance given');

        $eventStore = $this->prophesize(EventStore::class);
        $connection = $this->prophesize(PDO::class);
        $readModel = $this->prophesize(ReadModel::class);

        new PdoEventStoreReadModelProjector(
            $eventStore->reveal(),
            $connection->reveal(),
            'test_projection',
            $readModel->reveal(),
            'event_streams',
            'projections',
            10,
            10,
            10
        );
    }

    /**
     * @test
     */
    public function it_dispatches_pcntl_signals_when_enabled()
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('The PCNTL extension is not available.');

            return;
        }

        $command = 'exec php ' . realpath(__DIR__) . '/isolated-read-model-projection.php';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /**
         * Created process inherits env variables from this process.
         * Script returns with non-standard code SIGUSR1 from the handler and -1 else
         */
        $projectionProcess = proc_open($command, $descriptorSpec, $pipes);
        $processDetails = proc_get_status($projectionProcess);
        sleep(1);
        posix_kill($processDetails['pid'], SIGQUIT);
        sleep(1);

        $processDetails = proc_get_status($projectionProcess);
        $this->assertEquals(
            SIGUSR1,
            $processDetails['exitcode']
        );
    }
}
