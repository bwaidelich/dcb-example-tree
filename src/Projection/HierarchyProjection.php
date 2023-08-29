<?php

declare(strict_types=1);

namespace Wwwision\DCBExampleTree\Projection;

use RuntimeException;
use Wwwision\DCBEventStore\Types\EventEnvelope;
use Wwwision\DCBEventStore\Types\SequenceNumber;

use function array_shift;
use function json_decode;

use const JSON_THROW_ON_ERROR;

final class HierarchyProjection
{
    private readonly Node $rootNode;

    public ?SequenceNumber $sequenceNumber = null;

    public function __construct()
    {
        $this->rootNode = new Node('root', SequenceNumber::fromInteger(1), null);
    }

    public function apply(EventEnvelope $eventEnvelope): void
    {
        $event = $eventEnvelope->event;
        $payload = json_decode($event->data->value, true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($payload));
        match ($event->type->value) {
            'NodeAdded' => $this->addNode($payload['id'], $payload['parentId'], $eventEnvelope->sequenceNumber),
            'NodeMoved' => $this->moveNode($payload['id'], $payload['newParentId'], $eventEnvelope->sequenceNumber),
            default => throw new RuntimeException(sprintf('Unknown event type "%s"', $event->type->value)),
        };
        $this->sequenceNumber = $eventEnvelope->sequenceNumber;
    }

    private function addNode(string $id, string $parentId, SequenceNumber $sequenceNumber): void
    {
        if ($id === $parentId) {
            return;
        }
        if ($this->findNode($id) !== null) {
            return;
        }
        $parentNode = $this->findNode($parentId);
        if ($parentNode === null) {
            return;
        }
        $parentNode->children[] = new Node($id, $sequenceNumber, $parentNode);
    }

    private function moveNode(string $id, string $newParentId, SequenceNumber $sequenceNumber): void
    {
        if ($id === $newParentId) {
            return;
        }
        if ($id === 'root') {
            return;
        }
        $nodeToMove = $this->findNode($id);
        if ($nodeToMove === null) {
            return;
        }
        $oldParentNode = $nodeToMove->parent;
        if ($oldParentNode === null) {
            return;
        }
        $newParentNode = $this->findNode($newParentId);
        if ($newParentNode === null) {
            return;
        }
        $oldParentNode->removeChild($id);
        $newParentNode->addChild($nodeToMove, $sequenceNumber);
    }

    public function findNode(string $id): ?Node
    {
        return $this->findDescendantNode($this->rootNode, $id);
    }

    private function findDescendantNode(Node $startingNode, string $id): ?Node
    {
        $stack = [$startingNode];
        while ($stack !== []) {
            $node = array_shift($stack);
            if ($node->id === $id) {
                return $node;
            }
            foreach ($node->children as $childNode) {
                $stack[] = $childNode;
            }
        }
        return null;
    }

    public function reset(): void
    {
        foreach ($this->rootNode->children as $childNode) {
            $this->rootNode->removeChild($childNode->id);
        }
        $this->sequenceNumber = null;
    }

    public function toString(): string
    {
        return 'Tree (' . $this->sequenceNumber?->value . '):' . PHP_EOL . $this->rootNode->print(0);
    }
}
