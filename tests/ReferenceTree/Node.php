<?php

declare(strict_types=1);

namespace Wwwision\DCBExampleTree\Tests\ReferenceTree;

use Wwwision\DCBEventStore\Types\SequenceNumber;
use function array_filter;
use function chr;
use function str_repeat;

final class Node {

    /**
     * @var self[]
     */
    public array $children = [];

    public function __construct(
        public readonly string $id,
    ) {
    }

    public function addChild(self $newChildNode): void {
        $this->children[] = $newChildNode;
    }

    public function removeChild(string $id): void {
        $this->children = array_filter($this->children, static fn (self $childNode) => $childNode->id !== $id);
    }

    public function toArray(): array
    {
        $childNodeArray = [];
        foreach ($this->children as $childNode) {
            $childNodeArray[$childNode->id] = $childNode->toArray();
        }
        return [$this->id => $childNodeArray];
    }

    public function print(int $level): string
    {
        $result = str_repeat(' ', $level * 2) . $this->id . chr(10);
        foreach ($this->children as $childNode) {
            $result .= $childNode->print($level + 1);
        }
        return $result;
    }
}