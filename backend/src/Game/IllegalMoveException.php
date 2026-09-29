<?php

declare(strict_types=1);

namespace Checkmate\Game;

use RuntimeException;

/**
 * Thrown when a submitted move list contains an illegal move.
 * Carries the zero-based index of the first illegal move so the HTTP layer
 * can surface the API error ILLEGAL_MOVE with the offending position.
 */
final class IllegalMoveException extends RuntimeException
{
    public function __construct(
        public readonly int $index,
        public readonly string $move,
        public readonly string $fenBefore,
    ) {
        parent::__construct(
            "Illegal move at index {$index}: \"{$move}\" (position: {$fenBefore})",
        );
    }
}
