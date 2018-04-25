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
use Prooph\EventStore\Pdo\MySqlEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy\MySqlSimpleStreamStrategy;
use Prooph\EventStore\Pdo\Projection\MySqlProjectionManager;
use ProophTest\EventStore\Pdo\TestUtil;

/**
 * @group mysql
 */
class MySqlEventStoreQueryTest extends PdoEventStoreQueryTest
{
    protected function setUp()
    {
        if (TestUtil::getDatabaseDriver() !== 'pdo_mysql') {
            throw new \RuntimeException('Invalid database driver');
        }

        $this->isMariaDb = TestUtil::getDatabaseVendor() === 'mariadb';

        $this->connection = TestUtil::getConnection();
        TestUtil::initDefaultDatabaseTables($this->connection);

        $this->eventStore = new MySqlEventStore(
            new FQCNMessageFactory(),
            $this->connection,
            new MySqlSimpleStreamStrategy()
        );

        $this->projectionManager = new MySqlProjectionManager(
            $this->eventStore,
            $this->connection
        );
    }
}
