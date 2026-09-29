/**
 * Profile — identity, ratings and account management.
 *
 * Everything shown is one of: the session the server handed us, ratings that
 * came back with it, or results this device stored locally. Signing out and
 * account deletion are real operations (tokens cleared, DELETE /account); the
 * screen never pretends a server call succeeded when it did not.
 */

import { el, clear, formatDate } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { topbar, section, toast, modal, confirm, emptyState, skeleton, statTile, avatarNode } from '../ui/components.js';
import { getSession, isLoggedIn, setSession, subscribe, haptic, playSound } from '../store.js';
import { api, apiConfigured, accessToken, clearTokens, isOfflineError } from '../api.js';
import { listGames, dbCount } from '../db.js';
import { go } from '../router.js';

const RATING_MODES = ['bullet', 'blitz', 'rapid', 'classical'];

export function profileScreen(ctx = {}) {
  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });

  let cancelled = false;

  root.appendChild(topbar({
    title: 'Profile',
    onBack: () => (ctx.router && typeof ctx.router.back === 'function' ? ctx.router.back() : go('/')),
  }));
  root.appendChild(scroll);

  const headHolder = el('div', {});
  const noticeHolder = el('div', {});
  const ratingsHolder = el('div', {});
  const localHolder = el('div', {});
  const actionsHolder = el('div', {});

  scroll.append(headHolder, noticeHolder, ratingsHolder, localHolder, actionsHolder);

  // ------------------------------------------------------------ local data
  /**
   * Compact on-device summary. Same honesty rule as Statistics: only finished
   * games with a single human side are attributed to a win/loss/draw.
   */
  async function loadLocal() {
    clear(localHolder);
    localHolder.appendChild(skeleton(64, 3));
    const [games, total] = await Promise.all([listGames({}), dbCount('games')]);
    if (cancelled) return;
    clear(localHolder);

    const finished = (Array.isArray(games) ? games : []).filter((g) => g.result && g.result !== '*');
    let wins = 0;
    let losses = 0;
    let draws = 0;
    for (const g of finished) {
      // Shared-device games are excluded from the record: there is no single
      // "you" behind them, so counting them would fabricate a W/L/D.
      if (g.mode === 'local') continue;
      if (g.result === 'win' || g.result === 'loss' || g.result === 'draw') {
        if (g.result === 'win') wins++;
        else if (g.result === 'loss') losses++;
        else draws++;
        continue;
      }
      if (g.result === '1/2-1/2') { draws++; continue; }
      const winner = g.winner || (g.result === '1-0' ? 'w' : g.result === '0-1' ? 'b' : null);
      if (!winner || g.mode === 'local' || !g.humanSide) continue;
      if (winner === g.humanSide) wins++; else losses++;
    }

    localHolder.appendChild(section('On-device results', el('span', { class: 'tiny muted', text: 'this device' })));
    if (!finished.length) {
      localHolder.appendChild(emptyState({
        iconName: 'board',
        title: 'No finished games on this device',
        hint: 'Games you finish are stored locally and summarised here. Nothing is shown before you play.',
        action: el('button', {
          class: 'btn btn--primary mt-2',
          type: 'button',
          text: 'Start a game',
          onClick: () => go('/play'),
        }),
      }));
      return;
    }

    const decided = wins + losses + draws;
    localHolder.appendChild(el('div', { class: 'stat-grid' },
      statTile({ label: 'Games', value: String(finished.length) }),
      statTile({ label: 'Wins', value: String(wins), tone: 'green' }),
      statTile({ label: 'Losses', value: String(losses), tone: 'danger' }),
      statTile({ label: 'Draws', value: String(draws) }),
      statTile({ label: 'Win rate', value: decided ? `${Math.round((wins / decided) * 100)}%` : '—' }),
      statTile({ label: 'Stored locally', value: String(total) }),
    ));
    localHolder.appendChild(el('div', { class: 'row justify-between mt-2' },
      el('span', { class: 'tiny muted', text: 'Wins + losses + draws cover attributed games only — shared-device games are excluded.' }),
      el('button', { class: 'link', type: 'button', text: 'Full statistics', onClick: () => go('/stats') }),
    ));
  }

  // --------------------------------------------------------- session state
  async function ensureSession() {
    if (isLoggedIn()) return getSession();
    if (!accessToken() || !apiConfigured()) return null;
    try {
      const res = await api.me();
      const user = res && res.data ? res.data.user : null;
      if (user) { setSession(user); return user; }
    } catch {
      // Dead token — fall through to the signed-out state.
    }
    return null;
  }

  function paintHead(session) {
    clear(headHolder);
    if (!session) {
      headHolder.appendChild(el('div', { class: 'profile-head' },
        avatarNode('Guest', 'guest', 'lg'),
        el('div', { class: 'profile-head__name', text: 'Playing as guest' }),
        el('div', { class: 'profile-head__mail', text: 'No account on this device' }),
      ));
      return;
    }
    const name = session.display_name || session.email || 'Player';
    const seed = session.avatar_seed || String(session.id ?? name);
    headHolder.appendChild(el('div', { class: 'profile-head' },
      avatarNode(name, seed, 'lg'),
      el('div', { class: 'profile-head__name', text: name }),
      el('div', { class: 'profile-head__mail', text: session.email || '' }),
      el('div', { class: 'profile-head__badges' },
        el('span', { class: `badge ${session.email_verified ? 'badge--green' : 'badge--gold'}` },
          icon(session.email_verified ? 'check' : 'mail', 12),
          el('span', { text: session.email_verified ? 'Email verified' : 'Email unverified' })),
        session.created_at
          ? el('span', { class: 'badge' }, icon('clock', 12), el('span', { text: `Joined ${formatDate(session.created_at)}` }))
          : null,
      ),
    ));
  }

  async function paintNotices(session) {
    clear(noticeHolder);
    if (!session) {
      noticeHolder.appendChild(el('div', { class: 'notice mt-3' },
        icon('info', 16),
        el('div', {},
          el('div', { text: 'Signing in enables rated play against real accounts, ratings stored on your account, and game history that follows you to any device. Games, puzzles and lessons on this device keep working signed out.' }),
          el('button', {
            class: 'btn btn--primary btn--sm mt-2',
            type: 'button',
            text: 'Sign in or create account',
            onClick: () => go('/auth'),
          })),
      ));
      return;
    }

    if (!session.email_verified) {
      const btn = el('button', {
        class: 'btn btn--secondary btn--sm mt-2',
        type: 'button',
        text: 'Resend verification email',
        onClick: async () => {
          btn.disabled = true;
          try {
            await api.resendVerification(session.email || null);
            if (cancelled) return;
            toast('If that address exists and is unverified, a new link has been sent', { type: 'success' });
          } catch (err) {
            if (cancelled) return;
            const code = err && err.code ? err.code : 'UNKNOWN';
            if (code === 'MAIL_NOT_CONFIGURED' || code === 'HTTP_503') {
              toast('This server has no mail service configured — ask its admin', { type: 'error' });
            } else if (isOfflineError(err)) {
              toast('Offline — verification can wait until you reconnect', { type: 'error' });
            } else {
              toast(`Could not resend (${code})`, { type: 'error' });
            }
          } finally {
            if (!cancelled) btn.disabled = false;
          }
        },
      });
      noticeHolder.appendChild(el('div', { class: 'notice notice--warn mt-3' },
        icon('mail', 16),
        el('div', {},
          el('div', { text: 'Your email is not verified yet — rated play and account recovery may be limited until it is.' }),
          btn),
      ));
    }
  }

  function paintRatings(session) {
    clear(ratingsHolder);
    if (!session) return;

    ratingsHolder.appendChild(section('Ratings', el('span', { class: 'tiny muted', text: 'server account' })));

    const ratings = session.ratings && typeof session.ratings === 'object' ? session.ratings : {};
    const keys = RATING_MODES.filter((m) => ratings[m] !== undefined)
      .concat(Object.keys(ratings).filter((m) => !RATING_MODES.includes(m)));

    if (!keys.length) {
      ratingsHolder.appendChild(emptyState({
        iconName: 'target',
        title: 'No ratings yet',
        hint: 'Ratings are created by your server the first time you finish a rated online game. Local and computer games do not affect them.',
      }));
      return;
    }

    ratingsHolder.appendChild(el('div', { class: 'mode-rating-grid' },
      keys.map((m) => {
        const entry = ratings[m];
        const value = typeof entry === 'number' ? entry : entry && typeof entry.rating === 'number' ? entry.rating : '—';
        const games = entry && typeof entry.games === 'number' ? `${entry.games} games` : 'no games yet';
        return statTile({ label: `${m} · ${games}`, value: String(value), tone: 'blue' });
      }),
    ));
    ratingsHolder.appendChild(el('p', { class: 'tiny muted mt-2',
      text: 'Straight from your server account — rated games only.' }));
  }

  function paintActions(session) {
    clear(actionsHolder);
    if (!session) {
      actionsHolder.appendChild(el('div', { class: 'mt-4' },
        el('button', {
          class: 'btn btn--primary btn--block',
          type: 'button',
          onClick: () => go('/auth'),
        }, icon('user', 18), el('span', { text: 'Sign in' })),
      ));
      return;
    }

    const signOut = async () => {
      const ok = await confirm({
        title: 'Sign out?',
        message: 'Your games, puzzles and lessons stay on this device. Server features ask you to sign in again.',
        confirmLabel: 'Sign out',
      });
      if (!ok || cancelled) return;
      try { await api.logout(); } catch { /* revocation is best effort; local sign-out still happens */ }
      clearTokens();
      setSession(null);
      haptic(14);
      playSound('tap');
      toast('Signed out', { type: 'success' });
      render();
    };

    const deleteAccount = () => {
      if (!apiConfigured()) {
        // Explained rather than faked: there is no server to delete anything on.
        const handle = modal({
          title: 'Delete account',
          body: el('div', {},
            el('p', { class: 'muted small', text: 'Account deletion is a server operation — there is no server configured on this device, so it cannot be done right now. Set one in Settings → Network, or clear local data from More instead.' }),
          ),
          actions: [el('button', { class: 'btn btn--primary', type: 'button', text: 'OK', onClick: () => handle.close() })],
        });
        return;
      }
      askPassword();
    };

    async function askPassword() {
      const input = el('input', {
        class: 'input',
        type: 'password',
        autocomplete: 'current-password',
        placeholder: 'Your account password',
      });
      const err = el('div', { class: 'field__error' });
      const field = el('label', { class: 'field mt-3' },
        el('span', { class: 'field__label', text: 'Confirm with your password' }),
        input, err);

      const submit = el('button', { class: 'btn btn--danger', type: 'button', text: 'Delete permanently' });
      const cancel = el('button', { class: 'btn btn--ghost', type: 'button', text: 'Cancel' });

      const handle = modal({
        title: 'Delete your account',
        body: el('div', {},
          el('p', { class: 'muted small', text: 'This permanently removes your account, ratings and uploaded games from the server. Games stored on this device are not affected.' }),
          field),
        actions: [cancel, submit],
        onClose: () => { /* released */ },
      });
      cancel.addEventListener('click', () => handle.close());

      const fail = (message) => {
        field.classList.add('field--invalid');
        err.textContent = message;
        submit.disabled = false;
        input.disabled = false;
      };

      submit.addEventListener('click', async () => {
        const password = input.value;
        if (!password) { fail('Enter your password to confirm.'); return; }
        field.classList.remove('field--invalid');
        err.textContent = '';
        submit.disabled = true;
        input.disabled = true;
        submit.textContent = 'Deleting…';
        try {
          await api.deleteAccount(password);
          if (cancelled) return;
          handle.close();
          clearTokens();
          setSession(null);
          playSound('end');
          toast('Account deleted', { type: 'success' });
          render();
        } catch (e2) {
          if (cancelled) return;
          submit.textContent = 'Delete permanently';
          const code = e2 && e2.code ? e2.code : 'UNKNOWN';
          if (code === 'INVALID_CREDENTIALS') fail('That password is incorrect.');
          else if (isOfflineError(e2)) fail('You are offline — connect and try again.');
          else if (code === 'NOT_CONFIGURED') fail('No server configured.');
          else fail(`Deletion failed (${code}).`);
        }
      });
    }

    actionsHolder.appendChild(section('Account'));
    actionsHolder.appendChild(el('div', { class: 'list' },
      el('button', { class: 'list__row', type: 'button', onClick: signOut },
        icon('logout', 20),
        el('span', { class: 'list__row__label', text: 'Sign out' }),
        icon('chevron', 18, 'chev'),
      ),
      el('button', {
        class: 'list__row',
        type: 'button',
        style: { color: 'var(--danger)' },
        onClick: deleteAccount,
      },
        icon('trash', 20),
        el('span', { class: 'list__row__label', text: 'Delete account' }),
        icon('chevron', 18, 'chev'),
      ),
    ));
    actionsHolder.appendChild(el('p', { class: 'tiny muted mt-2',
      text: 'Deleting removes the server-side account only. Use More → Clear local data to wipe this device.' }));
  }

  // ------------------------------------------------------------- lifecycle
  async function render() {
    const head = headHolder;
    clear(head);
    head.appendChild(skeleton(96, 1));

    const session = await ensureSession();
    if (cancelled) return;
    clear(head);
    paintHead(session);
    paintNotices(session).catch(() => {});
    paintRatings(session);
    paintActions(session);
  }

  const unsubscribe = subscribe((settings, patch) => {
    // Sign-in/sign-out elsewhere should be reflected without a manual refresh.
    if (patch === 'session' && !cancelled) render();
  });

  render();
  loadLocal();

  return {
    el: root,
    keepScreenOn: false,
    onLeave: () => {
      cancelled = true;
      unsubscribe();
    },
  };
}
