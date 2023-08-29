<?php
declare(strict_types=1);

namespace Wwwision\DCBExampleTreeApp;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use RuntimeException;
use Wwwision\DCBEventStoreDoctrine\DoctrineEventStore;
use Wwwision\DCBExampleTree\ConstraintException;
use Wwwision\DCBExampleTree\Tree;

require __DIR__ . '/vendor/autoload.php';

final readonly class App
{

    private Tree $tree;

    public function __construct(
        private Connection $connection,
    ) {
        $eventStore = DoctrineEventStore::create($connection, 'tree_events');
        $this->tree = new Tree($eventStore);
    }

    public function start(): void
    {
        $this->clearScreen();
        $this->printUsage();
        $this->printTree();
        $this->promptInput();
    }

    private function printUsage(): void
    {
        echo <<<USAGE
Usage:
* add <parent_id>.<new_id> - add a node, example: "add root.a"
* move <id> -> <new_parent_id> - move a node, example: "move a -> b"
* render - display the tree
* reset - reset tree
* quit - exit the program

USAGE;
    }

    private function clearScreen(): void
    {
        echo "\e[H\e[J";
    }

    private function promptInput(): void
    {
        $command = self::prompt('command:');
        if ($command === 'quit' || $command === 'exit') {
            exit;
        }
        if ($command === 'reset') {
            $this->resetTree();
            $this->promptInput();
        } elseif ($command === 'render') {
            $this->clearScreen();
            $this->printTree();
            $this->promptInput();
        } elseif (preg_match('/add\s(?<parentId>\S+)\.(?<newId>\S+)/', $command, $matches) === 1) {
            $this->addNode($matches['newId'], $matches['parentId']);
            $this->promptInput();
        } elseif (preg_match('/move\s(?<id>\S+)\s*->\s*(?<newParentId>\S+)/', $command, $matches) === 1) {
            $this->moveNode($matches['id'], $matches['newParentId']);
            $this->promptInput();
        } else {
            $this->printError("Unknown command \"$command\"");
            $this->printUsage();
            $this->promptInput();
        }
    }

    private function resetTree(): void
    {
        try {
            $this->connection->executeStatement('TRUNCATE TABLE tree_events');
        } catch (Exception $e) {
            throw new RuntimeException(sprintf('Failed to truncate events table: %s', $e->getMessage()), 1693291258, $e);
        }
        $this->tree->reset();
        $this->printNotice("Removed all nodes from tree");
    }

    private function addNode(string $newId, string $parentId): void
    {
        try {
            $this->tree->addNode($newId, $parentId);
        } catch (ConstraintException $exception) {
            $this->printError($exception->getMessage());
            return;
        }
        $this->printNotice("added node '$newId' underneath '$parentId':\n");
        $this->printTree();
    }

    private function moveNode(string $id, string $newParentId): void
    {
        try {
            $this->tree->moveNode($id, $newParentId);
        } catch (ConstraintException $exception) {
            $this->printError($exception->getMessage());
            return;
        }
        $this->printNotice("moved node '$id' to '$newParentId':\n");
        $this->printTree();
    }

    private function printError(string $message): void
    {
        echo "\033[31m $message \033[0m\n";
    }

    private function printNotice(string $message): void
    {
        echo "\033[33m $message \033[0m\n";
    }

    private function printTree(): void
    {
        echo $this->tree;
    }

    public static function prompt(string $message): string
    {
        echo $message . ' ';
        $response = fgets(STDIN);
        assert(is_string($response));
        return trim($response);
    }
}

$dsn = $argv[1] ?? null;
if ($dsn === null) {
    $dsn = App::prompt('Enter DSN for database (for example "<driver>://<username>:<password>@<host>:<port>/<database>", default: "pdo-sqlite://:memory:")');
    if ($dsn === '') {
        $dsn = 'pdo-sqlite://:memory:';
    }
}

$connection = DriverManager::getConnection(['url' => $dsn]);

$app = new App($connection);
$app->start();
