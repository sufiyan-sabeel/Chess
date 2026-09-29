#!/usr/bin/env node

/**
 * crosscheck.mjs — cross-validates the PHP chess rules engine
 * (backend/src/Chess/Rules.php, via the stateful bridge.php process)
 * against the vendored reference implementation chess.js 1.4.0.
 *
 * What is compared:
 *   1. 30 fixed positions (all 6 perft positions + castling / en passant /
 *      promotion / pin / mate / stalemate / insufficient-material /
 *      disambiguation / fifty-move positions):
 *      full legal UCI move set, uci=>SAN map, side to move, FEN,
 *      check/mate/stale/insufficient/threefold/fifty/draw/game-over flags,
 *      result string and result reason.
 *   2. startpos breadth: every legal root move applied and fully compared.
 *   3. Sampled play: >=200 plies across the fixed positions, FEN compared
 *      after every ply.
 *   4. Seeded self-play: 30 games x <=200 plies (mulberry32), legal sets
 *      and FEN compared every ply.
 *
 * Exit code 0 = all comparisons matched, 1 = at least one mismatch.
 */

import { spawn } from 'node:child_process';
import readline from 'node:readline';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Chess } from '../../../app/src/main/assets/app/js/vendor/chess.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BRIDGE = path.join(__dirname, 'bridge.php');
const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

/* ------------------------------------------------------------------ */
/* bridge plumbing                                                     */
/* ------------------------------------------------------------------ */

function startBridge() {
    const proc = spawn('php', [BRIDGE], { stdio: ['pipe', 'pipe', 'pipe'] });
    const rl = readline.createInterface({ input: proc.stdout });
    const queue = [];
    let stderr = '';
    let exited = false;

    proc.stderr.on('data', (d) => { stderr += d; });
    rl.on('line', (line) => {
        const waiter = queue.shift();
        if (!waiter) return;
        try {
            waiter.resolve(JSON.parse(line));
        } catch (e) {
            waiter.reject(new Error(`bridge sent invalid JSON: ${line.slice(0, 200)}`));
        }
    });
    proc.on('exit', (code) => {
        exited = true;
        while (queue.length) {
            queue.shift().reject(new Error(`bridge exited (code ${code})${stderr ? ': ' + stderr.slice(-400) : ''}`));
        }
    });

    return {
        cmd(obj, timeoutMs = 20000) {
            return new Promise((resolve, reject) => {
                if (exited) {
                    reject(new Error('bridge already exited'));
                    return;
                }
                const timer = setTimeout(() => {
                    reject(new Error(`bridge timeout on ${JSON.stringify(obj).slice(0, 120)}`));
                }, timeoutMs);
                queue.push({
                    resolve: (v) => { clearTimeout(timer); resolve(v); },
                    reject: (e) => { clearTimeout(timer); reject(e); },
                });
                proc.stdin.write(JSON.stringify(obj) + '\n');
            });
        },
        stop() {
            try { proc.stdin.end(); } catch { /* ignore */ }
            proc.kill();
        },
    };
}

/* ------------------------------------------------------------------ */
/* reference state (chess.js)                                          */
/* ------------------------------------------------------------------ */

function jsResult(c) {
    if (c.isCheckmate()) return c.turn() === 'w' ? '0-1' : '1-0';
    if (c.isGameOver()) return '1/2-1/2';
    return '*';
}

function jsReason(c) {
    if (c.isCheckmate()) return 'checkmate';
    if (c.isStalemate()) return 'stalemate';
    if (c.isInsufficientMaterial()) return 'insufficient_material';
    if (c.isThreefoldRepetition()) return 'threefold_repetition';
    if (c.isDrawByFiftyMoves()) return 'fifty_move';
    return null;
}

function jsState(c) {
    const ms = c.moves({ verbose: true });
    const legal = [];
    const san = {};
    for (const m of ms) {
        const u = `${m.from}${m.to}${m.promotion || ''}`;
        legal.push(u);
        san[u] = m.san;
    }
    legal.sort();
    return {
        fen: c.fen(),
        turn: c.turn(),
        legal,
        san,
        check: c.isCheck(),
        mate: c.isCheckmate(),
        stale: c.isStalemate(),
        insufficient: c.isInsufficientMaterial(),
        threefold: c.isThreefoldRepetition(),
        fifty: c.isDrawByFiftyMoves(),
        draw: c.isDraw(),
        canClaimDraw: c.isThreefoldRepetition() || c.isDrawByFiftyMoves(),
        gameOver: c.isGameOver(),
        result: jsResult(c),
        reason: jsReason(c),
    };
}

/* ------------------------------------------------------------------ */
/* comparison                                                          */
/* ------------------------------------------------------------------ */

const failures = [];
let comparisons = 0;

function fail(msg) {
    failures.push(msg);
    if (failures.length <= 40) console.log(`FAIL ${msg}`);
}

const SCALAR_FIELDS = [
    'fen', 'turn', 'check', 'mate', 'stale', 'insufficient',
    'threefold', 'fifty', 'draw', 'canClaimDraw', 'gameOver', 'result', 'reason',
];

function compare(tag, js, ph) {
    comparisons++;
    for (const f of SCALAR_FIELDS) {
        if (js[f] !== ph[f]) {
            fail(`${tag}: ${f} differs — chess.js=${JSON.stringify(js[f])} php=${JSON.stringify(ph[f])}`);
        }
    }
    const pl = [...ph.legal].sort();
    if (JSON.stringify(js.legal) !== JSON.stringify(pl)) {
        const onlyJs = js.legal.filter((x) => !pl.includes(x));
        const onlyPh = pl.filter((x) => !js.legal.includes(x));
        fail(`${tag}: legal move set differs — only chess.js: [${onlyJs}] only php: [${onlyPh}]`);
        return;
    }
    for (const u of js.legal) {
        if (js.san[u] !== ph.san[u]) {
            fail(`${tag}: SAN for ${u} differs — chess.js=${js.san[u]} php=${ph.san[u]}`);
        }
    }
}

function mulberry32(seed) {
    let a = seed >>> 0;
    return function () {
        a = (a + 0x6D2B79F5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/* ------------------------------------------------------------------ */
/* positions                                                           */
/* ------------------------------------------------------------------ */

const POSITIONS = [
    ['startpos', START_FEN],
    ['kiwipete', 'r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1'],
    ['position 3', '8/2p5/3p4/KP5r/1R3p1k/8/4P1P1/8 w - - 0 1'],
    ['position 4', 'r3k2r/Pppp1ppp/1b3nbN/nP6/BBP1P3/q4N2/Pp1P2PP/R2Q1RK1 w kq - 0 1'],
    ['position 5', 'rnbq1k1r/pp1Pbppp/2p5/8/2B5/8/PPP1NnPP/RNBQK2R w KQ - 1 8'],
    ['position 6', 'r4rk1/1pp1qppp/p1np1n2/2b1p1B1/2B1P1b1/P1NP1N2/1PP1QPPP/R4RK1 w - - 0 1'],
    ['castling base', 'r3k2r/8/8/8/8/8/8/R3K2R w KQkq - 0 1'],
    ['castling black to move', 'r3k2r/8/8/8/8/8/8/R3K2R b KQkq - 0 1'],
    ['castling in check', 'r3k2r/8/8/8/8/8/5q2/R3K2R w KQkq - 0 1'],
    ['castling pass square attacked', 'r3k2r/8/8/8/8/8/6q1/R3K2R w KQkq - 0 1'],
    ['castling rook attacked', 'r5k1/8/2b5/8/8/8/8/R3K2R w KQ - 0 1'],
    ['en passant available', 'rnbqkbnr/ppp1p1pp/8/3pPp2/8/8/PPPP1PPP/RNBQKBNR w KQkq f6 0 3'],
    ['en passant pinned', '4r2k/8/8/3pP3/8/8/8/4K3 w - d6 0 1'],
    ['promotion', 'k7/4P3/8/8/8/8/8/4K3 w - - 0 1'],
    ['capture promotion', '1n2k3/P7/8/8/8/8/8/4K3 w - - 0 1'],
    ['double check', '4k3/8/4N3/8/8/8/8/4R1K1 w - - 0 1'],
    ['discovered check', '4k3/8/8/8/4N3/8/4R3/4K3 w - - 0 1'],
    ['pinned knight', '4k3/8/8/8/8/8/8/rN2K3 w - - 0 1'],
    ['back-rank mate', '6k1/5ppp/8/8/8/8/8/R3K3 w - - 0 1'],
    ['smothered mate', '6rk/6pp/3N4/8/8/8/8/6K1 w - - 0 1'],
    ['queen mate', '3k4/3r4/8/8/B2Q4/8/8/4K3 w - - 0 1'],
    ['stalemate', '7k/5Q2/6K1/8/8/8/8/8 b - - 0 1'],
    ['insufficient K vs K', '4k3/8/8/8/8/8/8/4K3 w - - 0 1'],
    ['insufficient same-colour bishops', '4k3/8/8/8/8/8/8/2B1K1B1 w - - 0 1'],
    ['not insufficient different colours', '4k3/8/8/8/8/8/8/2B1KB2 w - - 0 1'],
    ['not insufficient two knights', '4k3/8/8/8/8/8/8/3K1NN1 w - - 0 1'],
    ['three-knight disambiguation', '7k/8/8/1N6/8/8/8/1N1N3K w - - 0 1'],
    ['pawn capture disambiguation', '7k/8/8/3p4/2P1P3/8/8/K7 w - - 0 1'],
    ['fifty-move at 99', '4k3/8/8/8/8/8/8/R3K3 w - - 99 80'],
    ['knight check', '8/8/8/4k3/7N/8/8/K7 w - - 0 1'],
];

/* ------------------------------------------------------------------ */
/* main                                                                */
/* ------------------------------------------------------------------ */

async function main() {
    const php = startBridge();
    let sampledPlies = 0;
    let rootMoves = 0;
    let gamesPlayed = 0;
    let gamePlies = 0;

    try {
        const loadBoth = async (fen) => {
            const res = await php.cmd({ cmd: 'load', fen });
            if (!res.ok) throw new Error(res.error);
            const c = new Chess();
            c.load(fen); // throws on invalid FEN
            return [c, res.state];
        };
        const resetBoth = async (fen) => {
            const res = await php.cmd({ cmd: 'load', fen });
            if (!res.ok) throw new Error(res.error);
            const c = new Chess();
            c.load(fen);
            return [c, res.state];
        };

        /* phase 1: fixed positions */
        console.log(`phase 1: ${POSITIONS.length} fixed positions`);
        for (const [label, fen] of POSITIONS) {
            try {
                const [c, st] = await loadBoth(fen);
                compare(`position "${label}"`, jsState(c), st);
            } catch (e) {
                fail(`position "${label}": load failed — ${e.message}`);
            }
        }

        /* phase 2: startpos breadth — apply every legal root move */
        console.log('phase 2: startpos — every legal root move');
        {
            let [c, st] = await loadBoth(START_FEN);
            for (const u of [...st.legal].sort()) {
                const jm = c.move({ from: u.slice(0, 2), to: u.slice(2, 4), promotion: u.slice(4) || undefined });
                const pr = await php.cmd({ cmd: 'apply', uci: u });
                if (!jm) { fail(`startpos root ${u}: chess.js rejected its own legal move`); }
                if (!pr.ok) { fail(`startpos root ${u}: php rejected — ${pr.error}`); }
                if (jm && pr.ok) {
                    if (jm.san !== pr.san) fail(`startpos root ${u}: SAN chess.js=${jm.san} php=${pr.san}`);
                    compare(`startpos after ${u}`, jsState(c), pr.state);
                }
                rootMoves++;
                [c, st] = await resetBoth(START_FEN);
            }
        }

        /* phase 3: sampled play across the fixed positions (>=200 plies) */
        console.log('phase 3: sampled play across fixed positions');
        for (let i = 0; i < POSITIONS.length; i++) {
            const [label, fen] = POSITIONS[i];
            let c, st;
            try {
                [c, st] = await loadBoth(fen);
            } catch {
                continue; // already reported in phase 1
            }
            const rnd = mulberry32(0x1234 + i * 7919);
            for (let ply = 0; ply < 10; ply++) {
                if (st.gameOver || st.legal.length === 0) break;
                const u = st.legal[Math.floor(rnd() * st.legal.length)];
                const jm = c.move({ from: u.slice(0, 2), to: u.slice(2, 4), promotion: u.slice(4) || undefined });
                const pr = await php.cmd({ cmd: 'apply', uci: u });
                if (!jm) { fail(`${label} ply ${ply}: chess.js rejected ${u}`); break; }
                if (!pr.ok) { fail(`${label} ply ${ply}: php rejected ${u} — ${pr.error}`); break; }
                if (jm.san !== pr.san) fail(`${label} ply ${ply}: SAN chess.js=${jm.san} php=${pr.san} after ${u}`);
                compare(`${label} after ply ${ply} (${u})`, jsState(c), pr.state);
                st = pr.state;
                sampledPlies++;
            }
        }

        /* phase 4: seeded self-play */
        const GAMES = 30;
        const MAX_PLIES = 200;
        console.log(`phase 4: self-play ${GAMES} games x <=${MAX_PLIES} plies (mulberry32)`);
        for (let g = 0; g < GAMES; g++) {
            let [c, st] = await loadBoth(START_FEN);
            const rnd = mulberry32(0x9E3779B9 ^ (g + 1));
            let plies = 0;
            while (plies < MAX_PLIES && !st.gameOver && st.legal.length > 0) {
                const u = st.legal[Math.floor(rnd() * st.legal.length)];
                const jm = c.move({ from: u.slice(0, 2), to: u.slice(2, 4), promotion: u.slice(4) || undefined });
                const pr = await php.cmd({ cmd: 'apply', uci: u });
                if (!jm || !pr.ok) {
                    fail(`game ${g} ply ${plies}: apply ${u} — chess.js=${jm ? jm.san : 'REJECTED'} php=${pr.ok ? pr.san : pr.error}`);
                    break;
                }
                if (jm.san !== pr.san) fail(`game ${g} ply ${plies}: SAN chess.js=${jm.san} php=${pr.san} after ${u}`);
                compare(`game ${g} after ply ${plies}`, jsState(c), pr.state);
                st = pr.state;
                plies++;
            }
            gamesPlayed++;
            gamePlies += plies;
        }

        await php.cmd({ cmd: 'quit' });
    } finally {
        php.stop();
    }

    console.log('----------------------------------------');
    console.log(
        `crosscheck: ${POSITIONS.length} positions | ${rootMoves} root moves | ` +
        `${sampledPlies} sampled plies | ${gamesPlayed} games (${gamePlies} plies) | ` +
        `${comparisons} state comparisons | ${failures.length} failures`
    );
    if (failures.length > 0) {
        console.log(`crosscheck: ${failures.length} FAILED comparisons (first 40 shown above)`);
        console.log('RESULT: FAIL');
        process.exit(1);
    }
    console.log('crosscheck: all comparisons matched');
    console.log('RESULT: PASS');
    process.exit(0);
}

main().catch((e) => {
    console.error(`crosscheck aborted: ${e.stack || e.message}`);
    console.log('RESULT: FAIL');
    process.exit(1);
});
