<?php

declare(strict_types=1);

namespace Wwwision\DCBExampleTree;

use Wwwision\DCBEventStore\EventStore;
use Wwwision\DCBEventStore\Setupable;
use Wwwision\DCBEventStore\Types\AppendCondition;
use Wwwision\DCBEventStore\Types\EventData;
use Wwwision\DCBEventStore\Types\EventId;
use Wwwision\DCBEventStore\Types\EventMetadata;
use Wwwision\DCBEventStore\Types\Events;
use Wwwision\DCBEventStore\Types\EventType;
use Wwwision\DCBEventStore\Types\EventTypes;
use Wwwision\DCBEventStore\Types\ExpectedHighestSequenceNumber;
use Wwwision\DCBEventStore\Types\StreamQuery\Criteria;
use Wwwision\DCBEventStore\Types\StreamQuery\Criteria\EventTypesAndTagsCriterion;
use Wwwision\DCBEventStore\Types\StreamQuery\Criteria\TagsCriterion;
use Wwwision\DCBEventStore\Types\StreamQuery\StreamQuery;
use Wwwision\DCBEventStore\Types\Tags;
use Wwwision\DCBExampleTree\Projection\HierarchyProjection;
use Wwwision\DCBExampleTree\Projection\Node;

use function in_array;
use function json_decode;
use function json_encode;

final readonly class Tree
{
    private HierarchyProjection $hierarchyProjection;

    public function __construct(
        private EventStore $eventStore,
    ) {
        if ($this->eventStore instanceof Setupable) {
            $this->eventStore->setup();
        }
        $this->hierarchyProjection = new HierarchyProjection();
        $this->updateHierarchyProjection();
    }

    public function addNode(string $id, string $parentId): void
    {
        if ($id === $parentId) {
            throw new ConstraintException("Failed to add node with id '$id' because that id must not be equal to parent node id");
        }
        $query = StreamQuery::create(Criteria::create(
            new EventTypesAndTagsCriterion(EventTypes::fromStrings('NodeAdded'), Tags::fromArray([['key' => 'id', 'value' => $id]])),
            new EventTypesAndTagsCriterion(EventTypes::fromStrings('NodeAdded'), Tags::fromArray([['key' => 'id', 'value' => $parentId]]))
        ));
        $stream = $this->eventStore->read($query);
        $highestSequenceNumber = 0;
        $nodeExists = $id === 'root';
        $parentNodeExists = $parentId === 'root';
        foreach ($stream as $eventEnvelope) {
            $highestSequenceNumber = $eventEnvelope->sequenceNumber->value;
            if ($eventEnvelope->event->type->value !== 'NodeAdded') {
                continue;
            }
            $payload = json_decode($eventEnvelope->event->data->value, true, 512, JSON_THROW_ON_ERROR);
            assert(is_array($payload));
            if ($payload['id'] === $id) {
                $nodeExists = true;
            } elseif ($payload['id'] === $parentId) {
                $parentNodeExists = true;
            }
        }

        if ($nodeExists) {
            throw new ConstraintException("Failed to add node with id '$id' because a node with that id already exists");
        }
        if (!$parentNodeExists) {
            throw new ConstraintException("Failed to add node with id '$id' because parent node '$parentId' does not exist");
        }

        $this->appendEvent(
            type: 'NodeAdded',
            payload: ['id' => $id, 'parentId' => $parentId, 'ids' => "'id:$id', 'id:$parentId'", 'highestSequenceNumber' => $highestSequenceNumber],
            tags: [['key' => 'id', 'value' => $id], ['key' => 'parent_id', 'value' => $parentId]],
            query: $query,
            highestSequenceNumber: $highestSequenceNumber,
        );
    }

    public function moveNode(string $id, string $newParentId): void
    {
        if ($id === $newParentId) {
            throw new ConstraintException("Failed to move node with id '$id' to '$newParentId' because the id must not be equal to new parent node id");
        }
        if ($id === 'root') {
            throw new ConstraintException("Failed to move node with id '$id' to '$newParentId' because the root node must not be moved");
        }
        $this->updateHierarchyProjection();
        $nodeToMove = $this->hierarchyProjection->findNode($id);
        if ($nodeToMove === null) {
            throw new ConstraintException("Failed to move node with id '$id' to '$newParentId' because the node to move does not exist");
        }
        $newParentNode = $this->hierarchyProjection->findNode($newParentId);
        if ($newParentNode === null) {
            throw new ConstraintException("Failed to move node with id '$id' to '$newParentId' because the new parent node does not exist");
        }
        if ($nodeToMove->parent !== null && $newParentNode->id === $nodeToMove->parent->id) {
            throw new ConstraintException("Failed to move node with id '$id' to '$newParentId' because that is already the parent node", 1688410931);
        }
        $highestSequenceNumber = 0;
        $newAncestorNodeIds = $this->ancestorNodeIds($newParentNode, $highestSequenceNumber);
        if (in_array($id, $newAncestorNodeIds, true)) {
            throw new ConstraintException("Failed to move node with id '$id' to '$newParentId' because the new parent node is a descendant node of the node to move");
        }

        $oldAncestorNodeIds = $this->ancestorNodeIds($nodeToMove, $highestSequenceNumber);
        $affectedNodeIds = array_unique([...$oldAncestorNodeIds, ...$newAncestorNodeIds]);
        $this->appendEvent(
            type: 'NodeMoved',
            payload: ['id' => $id, 'newParentId' => $newParentId, 'oldAncestorNodeIds' => $oldAncestorNodeIds, 'newAncestorNodeIds' => $newAncestorNodeIds, 'highestSequenceNumber' => $highestSequenceNumber],
            tags: [['key' => 'id', 'value' => $id], ['key' => 'parent_id', 'value' => $newParentId]],
            query: StreamQuery::create(Criteria::create(...array_map(static fn(string $nodeId) => new TagsCriterion(Tags::single('id', $nodeId)), $affectedNodeIds))),
            highestSequenceNumber: $highestSequenceNumber,
        );
    }

    /**
     * @return array<string>
     */
    private function ancestorNodeIds(Node $node, int &$highestSequenceNumber): array
    {
        $ancestorNodeIds = [];
        $ancestorNode = $node;
        while ($ancestorNode !== null) {
            if ($ancestorNode->sequenceNumber->value > $highestSequenceNumber) {
                $highestSequenceNumber = $ancestorNode->sequenceNumber->value;
            }
            $ancestorNodeIds[] = $ancestorNode->id;
            $ancestorNode = $ancestorNode->parent;
        }
        return $ancestorNodeIds;
    }

    /**
     * @param array<mixed> $payload
     * @param array<mixed> $tags
     */
    private function appendEvent(string $type, array $payload, array $tags, StreamQuery $query, int $highestSequenceNumber): void
    {
        $payloadString = json_encode($payload);
        assert(is_string($payloadString));
        $eventData = EventData::fromString($payloadString);
        $this->eventStore->append(Events::single(EventId::create(), EventType::fromString($type), $eventData, Tags::fromArray($tags), EventMetadata::none()), new AppendCondition($query, ExpectedHighestSequenceNumber::fromInteger($highestSequenceNumber)));
    }

    private function updateHierarchyProjection(): void
    {
        foreach ($this->eventStore->read(StreamQuery::wildcard(), $this->hierarchyProjection->sequenceNumber) as $eventEnvelope) {
            $this->hierarchyProjection->apply($eventEnvelope);
        }
    }

    public function reset(): void
    {
        $this->hierarchyProjection->reset();
        $this->updateHierarchyProjection();
    }

    public function __toString(): string
    {
        $this->updateHierarchyProjection();
        return $this->hierarchyProjection->toString();
    }
}
