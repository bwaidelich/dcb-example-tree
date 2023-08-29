<?php

declare(strict_types=1);

namespace Wwwision\DCBExampleTree\Projection;

use Wwwision\DCBEventStore\Types\SequenceNumber;

use function array_filter;
use function chr;
use function str_repeat;

final class Node
{
    /**
     * @var self[]
     */
    public array $children = [];

    public function __construct(
        public readonly string $id,
        public SequenceNumber $sequenceNumber,
        public ?self $parent,
    ) {
    }

    public function addChild(self $newChildNode, SequenceNumber $sequenceNumber): void
    {
        $newChildNode->sequenceNumber = $sequenceNumber;
        $newChildNode->parent = $this;
        $this->children[] = $newChildNode;
    }

    public function removeChild(string $id): void
    {
        $this->children = array_filter($this->children, static fn (self $childNode) => $childNode->id !== $id);
    }

    public function print(int $level): string
    {
        $result = str_repeat(' ', $level * 2) . $this->id . ' (' . $this->sequenceNumber->value . ')' . chr(10);
        foreach ($this->children as $childNode) {
            $result .= $childNode->print($level + 1);
        }
        return $result;
    }
}
