<?php

declare(strict_types=1);

namespace Wwwision\DCBExampleTree\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Wwwision\DCBEventStore\EventStore;
use Wwwision\DCBEventStore\Exceptions\ConditionalAppendFailed;
use Wwwision\DCBEventStore\Types\StreamQuery\StreamQuery;
use Wwwision\DCBEventStoreDoctrine\DoctrineEventStore;
use Wwwision\DCBExampleTree\ConstraintException;
use Wwwision\DCBExampleTree\Tests\ReferenceTree\ReferenceTree;
use Wwwision\DCBExampleTree\Tree;
use function array_rand;
use function chr;
use function getenv;
use function is_string;
use function json_decode;
use const PHP_EOL;

#[CoversNothing]
final class ConcurrencyTest extends TestCase
{

    private static ?DoctrineEventStore $eventStore = null;
    private static ?Connection $connection = null;

    public static function prepare(): void
    {
        $connection = self::connection();
        $eventStore = self::createEventStore();
        $eventStore->setup();
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $connection->executeStatement('TRUNCATE TABLE ' . self::eventTableName() . ' RESTART IDENTITY');
        } elseif ($connection->getDatabasePlatform() instanceof SqlitePlatform) {
            /** @noinspection SqlWithoutWhere */
            $connection->executeStatement('DELETE FROM ' . self::eventTableName());
            $connection->executeStatement('DELETE FROM sqlite_sequence WHERE name =\'' . self::eventTableName() . '\'');
        } else {
            $connection->executeStatement('TRUNCATE TABLE ' . self::eventTableName());
        }
        echo PHP_EOL . 'Prepared tables for ' . $connection->getDatabasePlatform()::class . PHP_EOL;
    }

    public static function consistency_dataProvider(): iterable
    {
        for ($i = 0; $i < 10; $i++) {
            yield [$i];
        }
    }

    #[DataProvider('consistency_dataProvider')]
    #[Group('parallel')]
    public function test_consistency(): void {
        $randomNodeIds = range('a', 't');
        $randomNodeIds[] = 'root';
        $tree = new Tree(self::createEventStore());
        for ($i = 0; $i < 900; $i ++) {
            try {
                if (self::either(false, true)) {
                    $nodeId = self::either(...$randomNodeIds);
                    $parentNodeId = self::either(...$randomNodeIds);
                    $tree->addNode($nodeId, $parentNodeId);
                } else {
                    $id = self::either(...$randomNodeIds);
                    $newParentId = self::either(...$randomNodeIds);
                    $tree->moveNode($id, $newParentId);
                }
            } catch (ConstraintException|ConditionalAppendFailed $e) {
                //echo $e->getMessage() . chr(10);
            }
        }
        self::assertTrue(true);
    }


    public static function validateEvents(): void
    {
        $referenceTree = new ReferenceTree();
        foreach (self::createEventStore()->read(StreamQuery::wildcard()) as $eventEnvelope) {
            $payload = json_decode($eventEnvelope->event->data->value, true, 512, JSON_THROW_ON_ERROR);
            try {
                if ($eventEnvelope->event->type->value === 'NodeAdded') {
                    $referenceTree->addNode($payload['id'], $payload['parentId']);
                } else {
                    $referenceTree->moveNode($payload['id'], $payload['newParentId']);
                }
            } catch (\Exception $exception) {
                echo "Validation failed at event " . $eventEnvelope->sequenceNumber->value . ":\n";
                echo $referenceTree->toString() . "\n";
                throw $exception;
            }
        }
        echo $referenceTree->toString() . chr(10);
    }

    public static function cleanup(): void
    {
        self::connection()->executeStatement('DROP TABLE dcb_events_test');
    }

    private static function either(...$choices): mixed
    {
        return $choices[array_rand($choices)];
    }

    private static function createEventStore(): EventStore
    {
        if (self::$eventStore === null) {
            self::$eventStore = DoctrineEventStore::create(self::connection(), self::eventTableName());
        }
        return self::$eventStore;
    }

    private static function connection(): Connection
    {
        if (self::$connection === null) {
            $dsn = getenv('DCB_TEST_DSN');
            if (!is_string($dsn)) {
                $dsn = 'sqlite:///events_test.sqlite';
            }
            self::$connection = DriverManager::getConnection(['url' => $dsn]);
        }
        return self::$connection;
    }

    private static function eventTableName(): string
    {
        return 'dcb_events_test';
    }

}