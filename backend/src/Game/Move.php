<?php

declare(strict_types=1);

namespace Checkmate\Game;

/**
 * An immutable description of a single move on the board.
 *
 * Squares are indexes into the 64-square array used by {@see Position}:
 * 0 = a8, 1 = b8, ... 7 = h8, 8 = a7, ... 63 = h1.
 *
 * Piece codes are the lower-case FEN letters: p n b r q k.
 */
final class Move
{
    public const FLAG_NORMAL     = 1;
    public const FLAG_CAPTURE    = 2;
    public const FLAG_BIG_PAWN   = 4;   // pawn double push
    public const FLAG_EN_PASSANT = 8;
    public const FLAG_PROMOTION  = 16;
    public const FLAG_KSIDE      = 32;
    public const FLAG_QSIDE      = 64;

    public function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly string $piece,
        public readonly int $flags,
        public readonly ?string $captured = null,
        public readonly ?string $promotion = null,
    ) {
    }

    public function isCapture(): bool
    {
        return ($this->flags & self::FLAG_CAPTURE) !== 0;
    }

    public function isEnPassant(): bool
    {
        return ($this->flags & self::FLAG_EN_PASSANT) !== 0;
    }

    public function isPromotion(): bool
    {
        return ($this->flags & self::FLAG_PROMOTION) !== 0;
    }

    public function isBigPawn(): bool
    {
        return ($this->flags & self::FLAG_BIG_PAWN) !== 0;
    }

    public function isKingsideCastle(): bool
    {
        return ($this->flags & self::FLAG_KSIDE) !== 0;
    }

    public function isQueensideCastle(): bool
    {
        return ($this->flags & self::FLAG_QSIDE) !== 0;
    }

    public function isCastle(): bool
    {
        return $this->isKingsideCastle() || $this->isQueensideCastle();
    }

    /** "e2" */
    public function fromAlgebraic(): string
    {
        return Position::squareName($this->from);
    }

    /** "e4" */
    public function toAlgebraic(): string
    {
        return Position::squareName($this->to);
    }

    /** "e2e4" or "e7e8q" */
    public function uci(): string
    {
        return $this->fromAlgebraic() . $this->toAlgebraic() . ($this->promotion ?? '');
    }
}
