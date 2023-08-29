<?php

declare(strict_types=1);

namespace Wwwision\DCBExampleTree\Tests\ReferenceTree;

use InvalidArgumentException;
use function array_shift;

final class ReferenceTree {

    private Node $rootNode;

    public function __construct() {
        $this->rootNode = new Node('root');
    }

    public function addNode(string $id, string $parentId): void
    {
        if ($id === $parentId) {
            throw new InvalidArgumentException("Failed to add node with id '$id' because that id must not be equal to parent node id");
        }
        if ($this->findNode($id) !== null) {
            throw new InvalidArgumentException("Failed to add node with id '$id' because a node with that id already exists");
        }
        $parentNode = $this->findNode($parentId);
        if ($parentNode === null) {
            throw new InvalidArgumentException("Failed to add node with id '$id' because parent node '$parentId' does not exist");
        }
        $parentNode->children[] = new Node($id);
    }

    public function moveNode(string $id, string $newParentId): void
    {
        if ($id === $newParentId) {
            throw new InvalidArgumentException("Failed to move node with id '$id' to '$newParentId' because the id must not be equal to new parent node id");
        }
        if ($id === 'root') {
            throw new InvalidArgumentException("Failed to move node with id '$id' to '$newParentId' because the root node must not be moved");
        }
        $nodeToMove = $this->findNode($id);
        if ($nodeToMove === null) {
            throw new InvalidArgumentException("Failed to move node with id '$id' to '$newParentId' because the node to move does not exist");
        }
        $oldParentNode = $this->findParentNode($id);
        $newParentNode = $this->findNode($newParentId);
        if ($newParentNode === null) {
            throw new InvalidArgumentException("Failed to move node with id '$id' to '$newParentId' because the new parent node does not exist");
        }
        if ($newParentNode->id === $oldParentNode->id) {
            throw new InvalidArgumentException("Failed to move node with id '$id' to '$newParentId' because that is already the parent node");
        }
        if ($this->findAscendantNode($newParentNode, $id) !== null) {
            throw new InvalidArgumentException("Failed to move node with id '$id' to '$newParentId' because the new parent node is a descendant node of the node to move");
        }
        if ($oldParentNode !== null) {
            $oldParentNode->removeChild($id);
        }
        $newParentNode->addChild($nodeToMove);
    }

    private function findNode(string $id): ?Node
    {
        return $this->findDescendantNode($this->rootNode, $id);
    }

    private function findAscendantNode(Node $startingNode, string $id): ?Node
    {
        $stack = [$startingNode];
        while ($stack !== []) {
            $node = array_shift($stack);
            if ($node->id === $id) {
                return $node;
            }
            $parentNode = $this->findParentNode($node->id);
            if ($parentNode === null) {
                return null;
            }
            $stack[] = $parentNode;
        }
        return null;
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

    private function findParentNode(string $id): ?Node
    {
        $stack = [['parent' => null, 'child' => $this->rootNode]];
        while ($stack !== []) {
            ['parent' => $parent, 'child' => $child] = array_shift($stack);
            if ($child->id === $id) {
                return $parent;
            }
            foreach ($child->children as $childNode) {
                $stack[] = ['parent' => $child, 'child' => $childNode];
            }
        }
        return null;
    }

    public function toArray(): array
    {
        return $this->rootNode->toArray();
    }

    public function toString(): string
    {
        return $this->rootNode->print(0);
    }
}