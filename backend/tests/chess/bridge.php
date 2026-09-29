<?php

declare(strict_types=1);

/**
 * Stateful JSON-lines bridge between crosscheck.mjs (Node) and
 * backend/src/Chess/Rules.php (PHP).
 *
 * Protocol: one JSON object per line on stdin, one JSON reply per line on
 * stdout. Keeping one PHP process alive for the whole run preserves move
 * history (position keys), which is required for correct threefold-
 * repetition detection during self-play games.
 *
 * Commands:
 *   {"cmd":"load","fen":FEN}   load a fresh position;  -> state
 *   {"cmd":"state"}            report current position -> state
 *   {"cmd":"apply","uci":UCI}  apply a legal move;     -> {ok, san, state}
 *   {"cmd":"quit"}             -> {ok:true}, then exit
 *
 * state = {fen, turn, legal:[uci...], san:{uci:san}, check, mate, stale,
 *          insufficient, threefold, fifty, draw, canClaimDraw, gameOver,
 *          result, reason}
 *
 * Errors are reported as {"ok":false,"error":"..."} and never terminate
 * the bridge (except malformed JSON lines, which get an error reply).
 */

require_once __DIR__ . '/../../src/Chess/Rules.php';

use Checkmate\Chess\Rules;

function stateOf(Rules $r): array
{
    return [
        'fen'          => $r->fen(),
        'turn'         => $r->sideToMove(),
        'legal'        => $r->legalMovesUci(),
        'san'          => $r->legalMovesSan(),
        'check'        => $r->isCheck(),
        'mate'         => $r->isCheckmate(),
        'stale'        => $r->isStalemate(),
        'insufficient' => $r->isInsufficientMaterial(),
        'threefold'    => $r->isThreefoldRepetition(),
        'fifty'        => $r->isFiftyMove(),
        'draw'         => $r->isDraw(),
        'canClaimDraw' => $r->canClaimDraw(),
        'gameOver'     => $r->isGameOver(),
        'result'       => $r->result(),
        'reason'       => $r->resultReason(),
    ];
}

function emit(array $payload): void
{
    $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = '{"ok":false,"error":"encode failure"}';
    }
    fwrite(STDOUT, $line . "\n");
    fflush(STDOUT);
}

$current = null;

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $req = json_decode($line, true);
    if (!is_array($req) || !isset($req['cmd']) || !is_string($req['cmd'])) {
        emit(['ok' => false, 'error' => 'malformed request']);
        continue;
    }

    switch ($req['cmd']) {
        case 'quit':
            emit(['ok' => true]);
            exit(0);

        case 'load':
            $fen = $req['fen'] ?? null;
            if (!is_string($fen)) {
                emit(['ok' => false, 'error' => 'missing fen']);
                break;
            }
            $r = Rules::fromFen($fen);
            if ($r === null) {
                emit(['ok' => false, 'error' => 'invalid fen']);
                break;
            }
            $current = $r;
            emit(['ok' => true, 'state' => stateOf($r)]);
            break;

        case 'state':
            if ($current === null) {
                emit(['ok' => false, 'error' => 'no position loaded']);
                break;
            }
            emit(['ok' => true, 'state' => stateOf($current)]);
            break;

        case 'apply':
            $uci = $req['uci'] ?? null;
            if ($current === null || !is_string($uci)) {
                emit(['ok' => false, 'error' => 'no position or missing uci']);
                break;
            }
            $mv = $current->moveUci($uci);
            if ($mv === null) {
                emit(['ok' => false, 'error' => "illegal move {$uci}"]);
                break;
            }
            emit(['ok' => true, 'uci' => $uci, 'san' => $mv['san'], 'state' => stateOf($current)]);
            break;

        default:
            emit(['ok' => false, 'error' => 'unknown cmd ' . $req['cmd']]);
            break;
    }
}
