<?php

declare(strict_types=1);

namespace Checkmate\Game;

/**
 * The outcome of a finished game.
 *
 * Outcomes: checkmate, stalemate, insufficient, 50-move, repetition,
 * resign, time-forfeit, abort.  Draw-type outcomes have no winner;
 * decisive outcomes name the winner as 'w' (white) or 'b' (black).
 *
 * This is an immutable value object; construct it through the named
 * factories so the invariants (winner only for decisive outcomes) hold.
 */
final class GameResult
{
    public const CHECKMATE     = 'checkmate';
    public const STALEMATE     = 'stalemate';
    public const INSUFFICIENT  = 'insufficient';
    public const FIFTY_MOVE    = '50-move';
    public const REPETITION    = 'repetition';
    public const RESIGN        = 'resign';
    public const TIME_FORFEIT  = 'time-forfeit';
    public const ABORT         = 'abort';

    public const OUTCOMES = [
        self::CHECKMATE,
        self::STALEMATE,
        self::INSUFFICIENT,
        self::FIFTY_MOVE,
        self::REPETITION,
        self::RESIGN,
        self::TIME_FORFEIT,
        self::ABORT,
    ];

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $winner,
    ) {
    }

    /** $winner is the colour that delivered mate ('w' or 'b'). */
    public static function checkmate(string $winner): self
    {
        return new self(self::CHECKMATE, self::assertColor($winner));
    }

    public static function stalemate(): self
    {
        return new self(self::STALEMATE, null);
    }

    public static function insufficient(): self
    {
        return new self(self::INSUFFICIENT, null);
    }

    public static function fiftyMove(): self
    {
        return new self(self::FIFTY_MOVE, null);
    }

    public static function repetition(): self
    {
        return new self(self::REPETITION, null);
    }

    /** $resigned is the colour that resigned; the opponent wins. */
    public static function resign(string $resigned): self
    {
        return new self(self::RESIGN, self::opposite($resigned));
    }

    /** $loser is the colour that ran out of time; the opponent wins. */
    public static function timeForfeit(string $loser): self
    {
        return new self(self::TIME_FORFEIT, self::opposite($loser));
    }

    /** Aborted games have no winner. */
    public static function abort(): self
    {
        return new self(self::ABORT, null);
    }

    public function isDraw(): bool
    {
        return $this->winner === null;
    }

    public function isDecisive(): bool
    {
        return $this->winner !== null;
    }

    /** PGN-style result token: "1-0", "0-1" or "1/2-1/2". */
    public function pgnResult(): string
    {
        if ($this->winner === null) {
            return '1/2-1/2';
        }
        return $this->winner === 'w' ? '1-0' : '0-1';
    }

    private static function assertColor(string $color): string
    {
        if ($color !== 'w' && $color !== 'b') {
            throw new \InvalidArgumentException("Winner must be \"w\" or \"b\", got \"{$color}\"");
        }
        return $color;
    }

    private static function opposite(string $color): string
    {
        return self::assertColor($color) === 'w' ? 'b' : 'w';
    }
}
