<?php

declare(strict_types=1);

namespace Checkmate\Game;

/**
 * A game in progress: wraps a start position plus the moves played so far,
 * and is IMMUTABLE - every move returns a new Game instance.
 *
 * Typical backend usage:
 *
 *   $game = Game::start();
 *   $bad  = $game->validateMoveList($submittedMoves);   // null = all legal
 *   $game = $game->applyMoves($submittedMoves);         // or throws
 *   $game->result();                                    // ?GameResult
 *
 * Move strings may be SAN ("Nf3", "exd5", "O-O", "e8=Q#") or UCI
 * ("e2e4", "e7e8q"); see {@see Position::parseMove()}.
 */
final class Game
{
    private Position $position;

    /** @var Position[] every position including the start, indexed by ply */
    private array $positions;

    /** @var Move[] moves played so far */
    private array $moves;

    /** @var string[] SAN of each played move */
    private array $sans;

    /** @var string[] UCI of each played move */
    private array $ucis;

    /** @var array<string, int> repetition counter over position keys */
    private array $keyCounts;

    private function __construct(
        Position $position,
        array $positions,
        array $moves,
        array $sans,
        array $ucis,
        array $keyCounts,
    ) {
        $this->position = $position;
        $this->positions = $positions;
        $this->moves = $moves;
        $this->sans = $sans;
        $this->ucis = $ucis;
        $this->keyCounts = $keyCounts;
    }

    // ------------------------------------------------------------------
    // Construction
    // ------------------------------------------------------------------

    public static function start(): self
    {
        return self::fromFen(Position::START_FEN);
    }

    /**
     * @throws InvalidFenException
     */
    public static function fromFen(string $fen): self
    {
        $position = Position::fromFen($fen);
        $key = $position->positionKey();
        return new self($position, [$position], [], [], [], [$key => 1]);
    }

    // ------------------------------------------------------------------
    // State accessors
    // ------------------------------------------------------------------

    public function fen(): string
    {
        return $this->position->fen();
    }

    public function position(): Position
    {
        return $this->position;
    }

    /** 'w' or 'b' */
    public function turn(): string
    {
        return $this->position->turn();
    }

    /** Number of half-moves played. */
    public function ply(): int
    {
        return count($this->moves);
    }

    /** Full-move number of the current position. */
    public function fullmoveNumber(): int
    {
        return $this->position->fullmoveNumber();
    }

    /** @return Move[] legal moves of the current position */
    public function moves(): array
    {
        return $this->position->legalMoves();
    }

    /** @return string[] legal moves of the current position in SAN */
    public function sanMoves(): array
    {
        $sans = [];
        foreach ($this->position->legalMoves() as $move) {
            $sans[] = $this->position->toSan($move);
        }
        return $sans;
    }

    /** @return string[] SAN of every move played, in order */
    public function history(): array
    {
        return $this->sans;
    }

    /** @return string[] UCI of every move played, in order */
    public function historyUci(): array
    {
        return $this->ucis;
    }

    // ------------------------------------------------------------------
    // Playing moves
    // ------------------------------------------------------------------

    /**
     * Play one move given as SAN or UCI and return the new game.
     *
     * @throws IllegalMoveException when the string is not a legal move
     */
    public function play(string $sanOrUci): self
    {
        $index = count($this->moves);
        $move = $this->position->parseMove($sanOrUci);
        if ($move === null) {
            throw new IllegalMoveException($index, $sanOrUci, $this->fen());
        }
        return $this->applyMove($move);
    }

    /**
     * Play a whole list of moves (SAN or UCI, mixed is fine) and return the
     * resulting game.
     *
     * @param string[] $sansOrUci
     * @throws IllegalMoveException with ->index set to the first illegal move
     */
    public function applyMoves(array $sansOrUci): self
    {
        $game = $this;
        foreach ($sansOrUci as $index => $sanOrUci) {
            $move = $game->position->parseMove($sanOrUci);
            if ($move === null) {
                throw new IllegalMoveException((int) $index, (string) $sanOrUci, $game->fen());
            }
            $game = $game->applyMove($move);
        }
        return $game;
    }

    /**
     * Validate a submitted move list against the CURRENT position without
     * mutating anything.
     *
     * @param string[] $sansOrUci
     * @return int|null index of the first illegal move, or null when all are legal
     */
    public function validateMoveList(array $sansOrUci): ?int
    {
        $position = $this->position;
        foreach ($sansOrUci as $index => $sanOrUci) {
            $move = $position->parseMove($sanOrUci);
            if ($move === null) {
                return (int) $index;
            }
            $position = $position->make($move);
        }
        return null;
    }

    private function applyMove(Move $move): self
    {
        $position = $this->position->make($move);
        $positions = $this->positions;
        $positions[] = $position;
        $moves = $this->moves;
        $moves[] = $move;
        $sans = $this->sans;
        $sans[] = $this->position->toSan($move);
        $ucis = $this->ucis;
        $ucis[] = $move->uci();
        $keyCounts = $this->keyCounts;
        $key = $position->positionKey();
        $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;

        return new self($position, $positions, $moves, $sans, $ucis, $keyCounts);
    }

    // ------------------------------------------------------------------
    // Game state
    // ------------------------------------------------------------------

    public function isCheck(): bool
    {
        return $this->position->isCheck($this->position->turn());
    }

    public function isCheckmate(): bool
    {
        return $this->position->isCheckmate();
    }

    public function isStalemate(): bool
    {
        return $this->position->isStalemate();
    }

    public function isInsufficientMaterial(): bool
    {
        return $this->position->isInsufficientMaterial();
    }

    public function isDrawByFiftyMoves(): bool
    {
        return $this->position->isFiftyMoveDraw();
    }

    /** Has the current position occurred three or more times in this game? */
    public function isThreefoldRepetition(): bool
    {
        $key = $this->position->positionKey();
        return ($this->keyCounts[$key] ?? 0) >= 3;
    }

    /** Non-mandatory draw claims: stalemate, material, 50-move, repetition. */
    public function isDraw(): bool
    {
        return $this->isStalemate()
            || $this->isInsufficientMaterial()
            || $this->isDrawByFiftyMoves()
            || $this->isThreefoldRepetition();
    }

    public function isGameOver(): bool
    {
        return $this->isCheckmate() || $this->isDraw();
    }

    /**
     * The result if the game is over by the rules, otherwise null.
     * Resignation, time forfeits and aborts are not board-derived; create
     * those with the matching {@see GameResult} factories.
     */
    public function result(): ?GameResult
    {
        if ($this->isCheckmate()) {
            return GameResult::checkmate($this->position->turn() === 'w' ? 'b' : 'w');
        }
        if ($this->isStalemate()) {
            return GameResult::stalemate();
        }
        if ($this->isInsufficientMaterial()) {
            return GameResult::insufficient();
        }
        if ($this->isDrawByFiftyMoves()) {
            return GameResult::fiftyMove();
        }
        if ($this->isThreefoldRepetition()) {
            return GameResult::repetition();
        }
        return null;
    }
}
