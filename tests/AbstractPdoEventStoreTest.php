<?php
/**
 * This file is part of the prooph/pdo-event-store.
 * (c) 2016-2018 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace ProophTest\EventStore\Pdo;

use ArrayIterator;
use PDO;
use Prooph\Common\Messaging\FQCNMessageFactory;
use Prooph\Common\Messaging\Message;
use Prooph\EventStore\Exception\ConcurrencyException;
use Prooph\EventStore\Metadata\FieldType;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Metadata\Operator;
use Prooph\EventStore\Pdo\Exception\RuntimeException;
use Prooph\EventStore\Pdo\MariaDbEventStore;
use Prooph\EventStore\Pdo\MySqlEventStore;
use Prooph\EventStore\Pdo\PersistenceStrategy;
use Prooph\EventStore\Pdo\PostgresEventStore;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;
use ProophTest\EventStore\AbstractEventStoreTest;
use ProophTest\EventStore\Mock\TestDomainEvent;
use ProophTest\EventStore\Mock\UserCreated;
use ProophTest\EventStore\Mock\UsernameChanged;
use Ramsey\Uuid\Uuid;

abstract class AbstractPdoEventStoreTest extends AbstractEventStoreTest
{
    /**
     * @var PDO
     */
    protected $connection;

    /**
     * @var PersistenceStrategy
     */
    protected $persistenceStrategy;

    protected function setupEventStoreWith(
        PersistenceStrategy $persistenceStrategy, $loadBatchSize = 10000,
        $eventStreamsTable = 'event_streams',
        $disableTransactionHandling = false)
    {
        $this->persistenceStrategy = $persistenceStrategy;

        switch (TestUtil::getDatabaseVendor()) {
            case 'mariadb':
                $class = MariaDbEventStore::class;
                break;
            case 'mysql':
                $class = MySqlEventStore::class;
                break;
            case 'postgres':
                $class = PostgresEventStore::class;
                break;
        }
        $this->eventStore = new $class(
            new FQCNMessageFactory(),
            $this->connection,
            $persistenceStrategy,
            $loadBatchSize,
            $eventStreamsTable,
            $disableTransactionHandling
        );
    }

    protected function tearDown()
    {
        TestUtil::tearDownDatabase();
    }

    /**
     * @test
     */
    public function it_handles_not_existing_event_streams_table()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Maybe the event streams table is not setup?');

        $this->connection->exec('DROP TABLE event_streams;');

        $this->eventStore->create($this->getTestStream());
    }

    /**
     * @test
     */
    public function it_throws_exception_using_aggregate_stream_strategy_if_aggregate_version_is_missing_in_metadata()
    {
        $this->expectException(RuntimeException::class);

        $event = TestDomainEvent::fromArray([
            'uuid' => Uuid::uuid4()->toString(),
            'message_name' => 'test-message',
            'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'payload' => [],
            'metadata' => [],
        ]);

        $stream = new Stream(new StreamName('Prooph\Model\User'), new \ArrayIterator([$event]));

        $this->eventStore->create($stream);
    }

    /**
     * @test
     */
    public function it_fails_to_write_duplicate_version_using_aggregate_stream_strategy()
    {
        $this->expectException(ConcurrencyException::class);

        $streamEvent = UserCreated::with(
            ['name' => 'Max Mustermann', 'email' => 'contact@prooph.de'],
            1
        );

        $aggregateId = Uuid::uuid4()->toString();

        $streamEvent = $streamEvent->withAddedMetadata('tag', 'person');
        $streamEvent = $streamEvent->withAddedMetadata('_aggregate_id', $aggregateId);
        $streamEvent = $streamEvent->withAddedMetadata('_aggregate_type', 'user');

        $stream = new Stream(new StreamName('Prooph\Model\User'), new \ArrayIterator([$streamEvent]));

        $this->eventStore->create($stream);

        $streamEvent = UsernameChanged::with(
            ['name' => 'John Doe'],
            1
        );

        $streamEvent = $streamEvent->withAddedMetadata('tag', 'person');
        $streamEvent = $streamEvent->withAddedMetadata('_aggregate_id', $aggregateId);
        $streamEvent = $streamEvent->withAddedMetadata('_aggregate_type', 'user');

        $this->eventStore->appendTo(new StreamName('Prooph\Model\User'), new \ArrayIterator([$streamEvent]));
    }

    /**
     * @test
     */
    public function it_throws_exception_when_fetching_stream_names_with_missing_db_table()
    {
        $this->expectException(RuntimeException::class);

        $this->connection->exec('DROP TABLE event_streams;');
        $this->eventStore->fetchStreamNames(null, null, 200, 0);
    }

    /**
     * @test
     */
    public function it_throws_exception_when_fetching_stream_names_regex_with_missing_db_table()
    {
        $this->expectException(RuntimeException::class);

        $this->connection->exec('DROP TABLE event_streams;');
        $this->eventStore->fetchStreamNamesRegex('^foo', null, 200, 0);
    }

    /**
     * @test
     */
    public function it_throws_exception_when_fetching_category_names_with_missing_db_table()
    {
        $this->expectException(RuntimeException::class);

        $this->connection->exec('DROP TABLE event_streams;');
        $this->eventStore->fetchCategoryNames(null, 200, 0);
    }

    /**
     * @test
     */
    public function it_throws_exception_when_fetching_category_names_regex_with_missing_db_table()
    {
        $this->expectException(RuntimeException::class);

        $this->connection->exec('DROP TABLE event_streams;');
        $this->eventStore->fetchCategoryNamesRegex('^foo', 200, 0);
    }

    /**
     * @test
     */
    public function it_returns_only_matched_metadata()
    {
        $event = UserCreated::with(['name' => 'John'], 1);
        $event = $event->withAddedMetadata('foo', 'bar');
        $event = $event->withAddedMetadata('int', 5);
        $event = $event->withAddedMetadata('int2', 4);
        $event = $event->withAddedMetadata('int3', 6);
        $event = $event->withAddedMetadata('int4', 7);

        $uuid = $event->uuid()->toString();
        $uuid2 = Uuid::uuid4()->toString();
        $before = $event->createdAt()->modify('-5 secs')->format('Y-m-d\TH:i:s.u');
        $later = $event->createdAt()->modify('+5 secs')->format('Y-m-d\TH:i:s.u');

        $stream = new Stream(new StreamName('Prooph\Model\User'), new ArrayIterator([$event]));

        $this->eventStore->create($stream);

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('foo', Operator::EQUALS(), 'bar');
        $metadataMatcher = $metadataMatcher->withMetadataMatch('foo', Operator::NOT_EQUALS(), 'baz');
        $metadataMatcher = $metadataMatcher->withMetadataMatch('int', Operator::GREATER_THAN(), 4);
        $metadataMatcher = $metadataMatcher->withMetadataMatch('int2', Operator::GREATER_THAN_EQUALS(), 4);
        $metadataMatcher = $metadataMatcher->withMetadataMatch('int', Operator::IN(), [4, 5, 6]);
        $metadataMatcher = $metadataMatcher->withMetadataMatch('int3', Operator::LOWER_THAN(), 7);
        $metadataMatcher = $metadataMatcher->withMetadataMatch('int4', Operator::LOWER_THAN_EQUALS(), 7);
        $metadataMatcher = $metadataMatcher->withMetadataMatch('int', Operator::NOT_IN(), [4, 6]);
        $metadataMatcher = $metadataMatcher->withMetadataMatch('foo', Operator::REGEX(), '^b[a]r$');

        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_id', Operator::EQUALS(), $uuid, FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_id', Operator::NOT_EQUALS(), $uuid2, FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::GREATER_THAN(), $before, FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::GREATER_THAN_EQUALS(), $before, FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_id', Operator::IN(), [$uuid, $uuid2], FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::LOWER_THAN(), $later, FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::LOWER_THAN_EQUALS(), $later, FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::NOT_IN(), [$before, $later], FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_name', Operator::REGEX(), '.+UserCreated$', FieldType::MESSAGE_PROPERTY());

        $streamEvents = $this->eventStore->load($stream->streamName(), 1, null, $metadataMatcher);

        $this->assertCount(1, $streamEvents);
    }

    /**
     * @test
     */
    public function it_returns_only_matched_metadata_reverse()
    {
        $event = UserCreated::with(['name' => 'John'], 1);
        $event = $event->withAddedMetadata('foo', 'bar');
        $event = $event->withAddedMetadata('int', 5);
        $event = $event->withAddedMetadata('int2', 4);
        $event = $event->withAddedMetadata('int3', 6);
        $event = $event->withAddedMetadata('int4', 7);

        $uuid = $event->uuid()->toString();
        $uuid2 = Uuid::uuid4()->toString();
        $before = $event->createdAt()->modify('-5 secs')->format('Y-m-d\TH:i:s.u');
        $later = $event->createdAt()->modify('+5 secs')->format('Y-m-d\TH:i:s.u');

        $streamName = new StreamName('Prooph\Model\User');

        $stream = new Stream($streamName, new ArrayIterator([$event]));

        $this->eventStore->create($stream);

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('foo', Operator::EQUALS(), 'bar');
        $metadataMatcher = $metadataMatcher->withMetadataMatch('foo', Operator::NOT_EQUALS(), 'baz');
        $metadataMatcher = $metadataMatcher->withMetadataMatch('int', Operator::GREATER_THAN(), 4);
        $metadataMatcher = $metadataMatcher->withMetadataMatch('int2', Operator::GREATER_THAN_EQUALS(), 4);
        $metadataMatcher = $metadataMatcher->withMetadataMatch('int', Operator::IN(), [4, 5, 6]);
        $metadataMatcher = $metadataMatcher->withMetadataMatch('int3', Operator::LOWER_THAN(), 7);
        $metadataMatcher = $metadataMatcher->withMetadataMatch('int4', Operator::LOWER_THAN_EQUALS(), 7);
        $metadataMatcher = $metadataMatcher->withMetadataMatch('int', Operator::NOT_IN(), [4, 6]);
        $metadataMatcher = $metadataMatcher->withMetadataMatch('foo', Operator::REGEX(), '^b[a]r$');

        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_id', Operator::EQUALS(), $uuid, FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_id', Operator::NOT_EQUALS(), $uuid2, FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::GREATER_THAN(), $before, FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::GREATER_THAN_EQUALS(), $before, FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_id', Operator::IN(), [$uuid, $uuid2], FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::LOWER_THAN(), $later, FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::LOWER_THAN_EQUALS(), $later, FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::NOT_IN(), [$before, $later], FieldType::MESSAGE_PROPERTY());
        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_name', Operator::REGEX(), '.+UserCreated$', FieldType::MESSAGE_PROPERTY());

        $streamEvents = $this->eventStore->loadReverse($stream->streamName(), 1, null, $metadataMatcher);

        $this->assertCount(1, $streamEvents);
    }

    /**
     * @test
     */
    public function it_returns_only_matched_message_property()
    {
        $event = UserCreated::with(['name' => 'John'], 1);
        $event = $event->withAddedMetadata('foo', 'bar');
        $event = $event->withAddedMetadata('int', 5);
        $event = $event->withAddedMetadata('int2', 4);
        $event = $event->withAddedMetadata('int3', 6);
        $event = $event->withAddedMetadata('int4', 7);

        $uuid = $event->uuid()->toString();
        $uuid2 = Uuid::uuid4()->toString();
        $createdAt = $event->createdAt()->format('Y-m-d\TH:i:s.u');

        $before = $event->createdAt()->modify('-5 secs')->format('Y-m-d\TH:i:s.u');
        $later = $event->createdAt()->modify('+5 secs')->format('Y-m-d\TH:i:s.u');

        $streamName = new StreamName('Prooph\Model\User');

        $stream = new Stream($streamName, new ArrayIterator([$event]));

        $this->eventStore->create($stream);

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_id', Operator::EQUALS(), $uuid2, FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->load($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_id', Operator::NOT_EQUALS(), $uuid, FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->load($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::GREATER_THAN(), $later, FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->load($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::GREATER_THAN_EQUALS(), $later, FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->load($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::IN(), [$before, $later], FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->load($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::LOWER_THAN(), $before, FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->load($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::LOWER_THAN_EQUALS(), $before, FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->load($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::NOT_IN(), [$before, $createdAt, $later], FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->load($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_name', Operator::REGEX(), 'foobar', FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->load($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());
    }

    /**
     * @test
     */
    public function it_returns_only_matched_message_property_reverse()
    {
        $event = UserCreated::with(['name' => 'John'], 1);
        $event = $event->withAddedMetadata('foo', 'bar');
        $event = $event->withAddedMetadata('int', 5);
        $event = $event->withAddedMetadata('int2', 4);
        $event = $event->withAddedMetadata('int3', 6);
        $event = $event->withAddedMetadata('int4', 7);

        $uuid = $event->uuid()->toString();
        $uuid2 = Uuid::uuid4()->toString();
        $createdAt = $event->createdAt()->format('Y-m-d\TH:i:s.u');
        $before = $event->createdAt()->modify('-5 secs')->format('Y-m-d\TH:i:s.u');
        $later = $event->createdAt()->modify('+5 secs')->format('Y-m-d\TH:i:s.u');

        $streamName = new StreamName('Prooph\Model\User');
        $stream = new Stream($streamName, new ArrayIterator([$event]));

        $this->eventStore->create($stream);

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_id', Operator::EQUALS(), $uuid2, FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->loadReverse($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_id', Operator::NOT_EQUALS(), $uuid, FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->loadReverse($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::GREATER_THAN(), $later, FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->loadReverse($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::GREATER_THAN_EQUALS(), $later, FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->loadReverse($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::IN(), [$before, $later], FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->loadReverse($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::LOWER_THAN(), $before, FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->loadReverse($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::LOWER_THAN_EQUALS(), $before, FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->loadReverse($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('created_at', Operator::NOT_IN(), [$before, $createdAt, $later], FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->loadReverse($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('event_name', Operator::REGEX(), 'foobar', FieldType::MESSAGE_PROPERTY());

        $result = $this->eventStore->loadReverse($streamName, 1, null, $metadataMatcher);

        $this->assertFalse($result->valid());
    }

    /**
     * @test
     */
    public function it_adds_event_position_to_metadata_if_field_not_occupied()
    {
        $event = UserCreated::with(['name' => 'John'], 1);

        $streamName = new StreamName('Prooph\Model\User');
        $stream = new Stream($streamName, new ArrayIterator([$event]));

        $this->eventStore->create($stream);

        $streamEvents = $this->eventStore->load($streamName);

        $readEvent = $streamEvents->current();

        $this->assertArrayHasKey('_position', $readEvent->metadata());
        $this->assertEquals(1, $readEvent->metadata()['_position']);
    }

    /**
     * @test
     */
    public function it_does_not_add_event_position_to_metadata_if_field_is_occupied()
    {
        $event = UserCreated::with(['name' => 'John'], 1);
        $event = $event->withAddedMetadata('_position', 'foo');

        $streamName = new StreamName('Prooph\Model\User');
        $stream = new Stream($streamName, new ArrayIterator([$event]));

        $this->eventStore->create($stream);

        $streamEvents = $this->eventStore->load($streamName);

        $readEvent = $streamEvents->current();

        $this->assertArrayHasKey('_position', $readEvent->metadata());
        $this->assertSame('foo', $readEvent->metadata()['_position']);
    }

    /**
     * @test
     * issue: https://github.com/prooph/pdo-event-store/issues/106
     */
    public function it_doesnt_double_escape_metadata()
    {
        $event = UserCreated::with(['name' => 'John'], 1);
        $event = $event->withAddedMetadata('_aggregate_type', 'Prooph\Model\User');

        $streamName = new StreamName('Prooph\Model\User');
        $stream = new Stream($streamName, new ArrayIterator([$event]));

        $this->eventStore->create($stream);

        $metadataMatcher = new MetadataMatcher();
        $metadataMatcher = $metadataMatcher->withMetadataMatch('_aggregate_type', Operator::EQUALS(), 'Prooph\Model\User');
        $streamEvents = $this->eventStore->load($streamName, 0, 10, $metadataMatcher);

        $this->assertCount(1, $streamEvents);
    }

    /**
     * @test
     */
    public function it_does_not_use_json_force_object_for_stream_metadata_and_event_payload_and_metadata()
    {
        $event = UserCreated::with(['name' => ['John', 'Jane']], 1);
        $event = $event->withAddedMetadata('key', 'value');

        $streamName = new StreamName('Prooph\Model\User');
        $stream = new Stream($streamName, new ArrayIterator([$event]), ['some' => ['metadata', 'as', 'well']]);

        $this->eventStore->create($stream);

        $statement = $this->connection->prepare('SELECT * FROM event_streams');
        $statement->execute();

        $result = $statement->fetch(\PDO::FETCH_ASSOC);

        // mariadb does not add spaces to json, while mysql and postgres do, so strip them
        $this->assertSame('{"some":["metadata","as","well"]}', str_replace(' ', '', $result['metadata']));

        switch (TestUtil::getDatabaseVendor()) {
            case 'postgres':
                $statement = $this->connection->prepare(sprintf('SELECT * FROM "%s"', $this->persistenceStrategy->generateTableName($streamName)));
                break;
            default:
                $statement = $this->connection->prepare(sprintf('SELECT * FROM `%s`', $this->persistenceStrategy->generateTableName($streamName)));
        }

        $statement->execute();

        $result = $statement->fetch(\PDO::FETCH_ASSOC);

        // mariadb does not add spaces to json, while mysql and postgres do, so strip them
        $this->assertSame('{"name":["John","Jane"]}', str_replace(' ', '', $result['payload']));
    }

    /**
     * @return Message[]
     */
    protected function getMultipleTestEvents()
    {
        $events = [];

        $event = UserCreated::with(['name' => 'Alex'], 1);
        $events[] = $event->withAddedMetadata('_aggregate_id', 'one')->withAddedMetadata('_aggregate_type', 'user');

        $event = UserCreated::with(['name' => 'Sascha'], 1);
        $events[] = $event->withAddedMetadata('_aggregate_id', 'two')->withAddedMetadata('_aggregate_type', 'user');

        for ($i = 2; $i < 100; $i++) {
            $event = UsernameChanged::with(['name' => uniqid('name_')], $i);
            $events[] = $event->withAddedMetadata('_aggregate_id', 'two')->withAddedMetadata('_aggregate_type', 'user');

            $event = UsernameChanged::with(['name' => uniqid('name_')], $i);
            $events[] = $event->withAddedMetadata('_aggregate_id', 'one')->withAddedMetadata('_aggregate_type', 'user');
        }

        $event = UsernameChanged::with(['name' => 'Sandro'], 100);
        $events[] = $event->withAddedMetadata('_aggregate_id', 'one')->withAddedMetadata('_aggregate_type', 'user');

        $event = UsernameChanged::with(['name' => 'Bradley'], 100);
        $events[] = $event->withAddedMetadata('_aggregate_id', 'two')->withAddedMetadata('_aggregate_type', 'user');

        return $events;
    }
}
