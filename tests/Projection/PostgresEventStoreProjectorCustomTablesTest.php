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

use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\EventStore\Pdo\PersistenceStrategy\PostgresSimpleStreamStrategy;
use Prooph\EventStore\Pdo\PostgresEventStore;
use Prooph\EventStore\Pdo\Projection\PostgresProjectionManager;
use ProophTest\EventStore\Mock\UserCreated;
use ProophTest\EventStore\Pdo\TestUtil;

/**
 * @group postgres
 */
class PostgresEventStoreProjectorCustomTablesTest extends PdoEventStoreProjectorCustomTablesTest
{
    protected function setUp()
    {
        if (TestUtil::getDatabaseDriver() !== 'pdo_pgsql') {
            throw new \RuntimeException('Invalid database vendor');
        }

        $this->connection = TestUtil::getConnection();
        TestUtil::initCustomDatabaseTables($this->connection);

        $this->eventStore = new PostgresEventStore(
            new FQCNMessageFactory(),
            TestUtil::getConnection(),
            new PostgresSimpleStreamStrategy(),
            10000,
            'events/streams'
        );

        $this->projectionManager = new PostgresProjectionManager(
            $this->eventStore,
            $this->connection,
            'events/streams',
            'events/projections'
        );
    }

    /**
     * @test
     */
    public function it_handles_missing_projection_table()
    {
        $this->expectException(\Prooph\EventStore\Pdo\Exception\RuntimeException::class);
        $this->expectExceptionMessage("Error 42P01. Maybe the projection table is not setup?\nError-Info: ERROR:  relation \"events/projections\" does not exist\nLINE 1: SELECT status FROM");

        $this->prepareEventStream('user-123');

        $this->connection->exec('DROP TABLE "events/projections";');

        $projection = $this->projectionManager->createProjection('test_projection');

        $projection
            ->fromStream('user-123')
            ->when([
                UserCreated::class => function (array $state, UserCreated $event) {
                    $this->stop();

                    return $state;
                },
            ])
            ->run();
    }
}
