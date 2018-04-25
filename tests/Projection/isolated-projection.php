<?php
/**
 * This file is part of the prooph/pdo-event-store.
 * (c) 2016-2018 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\EventStore\Pdo\MySqlEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy\MySqlSimpleStreamStrategy;
use Prooph\EventStore\Pdo\Projection\MySqlProjectionManager;
use Prooph\EventStore\Pdo\Projection\PdoEventStoreProjector;
use ProophTest\EventStore\Mock\UserCreated;
use ProophTest\EventStore\Pdo\TestUtil;

require __DIR__ . '/../../vendor/autoload.php';

$connection = TestUtil::getConnection();

$eventStore = new MySqlEventStore(
    new FQCNMessageFactory(),
    $connection,
    new MySqlSimpleStreamStrategy()
);

$projectionManager = new MySqlProjectionManager(
    $eventStore,
    $connection
);
$projection = $projectionManager->createProjection(
    'test_projection',
    [
        PdoEventStoreProjector::OPTION_PCNTL_DISPATCH => true,
    ]
);
pcntl_signal(SIGQUIT, function () use ($projection) {
    $projection->stop();
    exit(SIGUSR1);
});
$projection
    ->fromStream('user-123')
    ->when([
        UserCreated::class => function (array $state, UserCreated $event) {
            return $state;
        },
    ])
    ->run();
