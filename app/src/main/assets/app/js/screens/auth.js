/**
 * Auth — sign in / create account, with real validation and real errors.
 *
 * The client enforces exactly the rules docs/API.md documents for the server
 * (email shape, password ≥10 with a letter and a digit, 3–20 character
 * display names) so mistakes surface before a request is spent; server error
 * codes are then mapped to messages that stay enumeration-safe — nothing here
 * claims an address does or does not exist.
 */

import { el, clear } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { topbar, toast, emptyState } from '../ui/components.js';
import { getSession, isLoggedIn, setSession, haptic, playSound } from '../store.js';
import { api, apiConfigured, setTokens, accessToken, isOfflineError } from '../api.js';
import { go } from '../router.js';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const NAME_RE = /^[A-Za-z0-9_]{3,20}$/;

function passwordProblem(value) {
  if (value.length < 10) return 'Use at least 10 characters.';
  if (!/[A-Za-z]/.test(value)) return 'Include at least one letter.';
  if (!/[0-9]/.test(value)) return 'Include at least one digit.';
  return '';
}

export function authScreen(ctx = {}) {
  const root = el('div', { class: 'screen' });
  const scroll = el('div', { class: 'scroll' });

  let cancelled = false;
  let mode = 'login';         // 'login' | 'register'
  let busy = false;

  root.appendChild(topbar({
    title: 'Sign in',
    onBack: () => (ctx.router && typeof ctx.router.back === 'function' ? ctx.router.back() : go('/')),
  }));
  root.appendChild(scroll);

  // ------------------------------------------------------------ helpers
  function makeField({ label, type = 'text', autocomplete = '', placeholder = '', inputmode = '' }) {
    const input = el('input', {
      class: 'input',
      type,
      autocomplete,
      placeholder,
      ...(inputmode ? { inputmode } : {}),
      ...(type === 'email' ? { autocapitalize: 'off', spellcheck: false } : {}),
    });
    const error = el('div', { class: 'field__error' });
    const wrap = el('label', { class: 'field' },
      el('span', { class: 'field__label', text: label }),
      input, error);
    const set = (message) => {
      if (message) {
        wrap.classList.add('field--invalid');
        error.textContent = message;
      } else {
        wrap.classList.remove('field--invalid');
        error.textContent = '';
      }
    };
    input.addEventListener('input', () => set(''));
    return { wrap, input, set };
  }

  const noticeHolder = el('div', {});
  function notice(text, tone = '') {
    clear(noticeHolder);
    if (!text) return;
    noticeHolder.appendChild(el('div', { class: `notice ${tone}`.trim() },
      icon(tone === 'notice--warn' || tone === 'notice--danger' ? 'info' : 'sparkle', 16),
      el('div', { text })));
  }

  // ------------------------------------------------------------- fields
  const nameField = makeField({ label: 'Display name', autocomplete: 'username', placeholder: 'e.g. numaiz' });
  const emailField = makeField({ label: 'Email', type: 'email', autocomplete: 'email', placeholder: 'you@example.com', inputmode: 'email' });
  const passwordField = makeField({ label: 'Password', type: 'password', autocomplete: 'current-password', placeholder: 'At least 10 characters' });

  const showPassword = el('button', { class: 'btn btn--ghost btn--sm', type: 'button' });
  function paintShowButton(reveal) {
    clear(showPassword);
    showPassword.appendChild(icon(reveal ? 'eyeOff' : 'eye', 16));
    showPassword.appendChild(el('span', { text: reveal ? 'Hide password' : 'Show password' }));
    showPassword.setAttribute('aria-pressed', String(reveal));
  }
  paintShowButton(false);
  showPassword.addEventListener('click', () => {
    const reveal = passwordField.input.type === 'password';
    passwordField.input.type = reveal ? 'text' : 'password';
    paintShowButton(reveal);
  });

  const ruleHint = el('p', { class: 'tiny muted', text: '10+ characters with at least one letter and one digit.' });

  const submitBtn = el('button', { class: 'btn btn--primary btn--block btn--lg', type: 'submit' });

  const form = el('form', { class: 'auth-form', novalidate: true, onSubmit: (ev) => { ev.preventDefault(); submit(ev); } },
    nameField.wrap,
    emailField.wrap,
    passwordField.wrap,
    showPassword,
    ruleHint,
    submitBtn,
  );

  // ------------------------------------------------------- session state
  const sessionHolder = el('div', {});
  function paintSession() {
    clear(sessionHolder);
    if (!isLoggedIn()) return;
    const session = getSession();
    sessionHolder.appendChild(el('div', { class: 'notice mt-2' },
      icon('user', 16),
      el('div', {},
        el('div', { text: `Signed in as ${session && (session.display_name || session.email) ? (session.display_name || session.email) : 'this device'}. Signing in again switches accounts.` }),
        el('button', {
          class: 'btn btn--secondary btn--sm mt-2',
          type: 'button',
          text: 'Go to profile',
          onClick: () => go('/profile'),
        })),
    ));
  }

  // ---------------------------------------------------------- validation
  function validate() {
    let ok = true;
    let firstBad = null;

    const email = emailField.input.value.trim();
    if (!email) { emailField.set('Enter your email address.'); ok = false; firstBad = firstBad || emailField; }
    else if (!EMAIL_RE.test(email)) { emailField.set('That does not look like an email address.'); ok = false; firstBad = firstBad || emailField; }

    const password = passwordField.input.value;
    if (!password) { passwordField.set('Enter your password.'); ok = false; firstBad = firstBad || passwordField; }
    else if (mode === 'register' && passwordProblem(password)) {
      passwordField.set(passwordProblem(password));
      ok = false;
      firstBad = firstBad || passwordField;
    }

    if (mode === 'register') {
      const name = nameField.input.value.trim();
      if (!name) { nameField.set('Choose a display name.'); ok = false; firstBad = firstBad || nameField; }
      else if (!NAME_RE.test(name)) {
        nameField.set('3–20 characters: letters, digits and underscore only.');
        ok = false;
        firstBad = firstBad || nameField;
      }
    }

    if (!ok) {
      haptic(20);
      playSound('error');
      firstBad?.input?.focus?.();
    }
    return ok;
  }

  // -------------------------------------------------------- busy painting
  function setBusy(value) {
    busy = value;
    submitBtn.disabled = value;
    clear(submitBtn);
    if (value) submitBtn.appendChild(el('span', { class: 'btn__spinner' }));
    else submitBtn.appendChild(icon(mode === 'login' ? 'logout' : 'user', 18));
    submitBtn.appendChild(el('span', {
      text: value
        ? (mode === 'login' ? 'Signing in…' : 'Creating account…')
        : (mode === 'login' ? 'Sign in' : 'Create account'),
    }));
  }

  // --------------------------------------------------------- error mapping
  function authError(err) {
    const code = err && err.code ? err.code : 'UNKNOWN';

    if (code === 'EMAIL_TAKEN') {
      emailField.set('An account already uses this email — switch to Sign in.');
      emailField.input.focus();
      return;
    }
    if (code === 'INVALID_CREDENTIALS') {
      // The server answers identically for unknown email and wrong password.
      passwordField.set('Email or password is incorrect.');
      passwordField.input.focus();
      return;
    }
    if (code === 'VALIDATION_ERROR') {
      // The server's rules mirror ours — re-run them so the field that is
      // actually wrong lights up; the notice covers a mismatch.
      validate();
      notice('The server rejected these details. Check the highlighted fields.', 'notice--warn');
      return;
    }
    if (code === 'RATE_LIMITED') {
      notice('Too many attempts from this device — wait about a minute, then try again.', 'notice--warn');
      return;
    }
    if (code === 'NOT_CONFIGURED') {
      notice('No server configured — accounts live on a backend. Set one in Settings → Network.', 'notice--warn');
      return;
    }
    if (isOfflineError(err)) {
      notice(navigator.onLine === false
        ? 'You are offline — signing in needs a connection. Playing as a guest works offline.'
        : 'Could not reach the server. Check the address in Settings → Network.', 'notice--warn');
      return;
    }
    if (code === 'MAIL_NOT_CONFIGURED' || code === 'HTTP_503') {
      notice('This server has no mail service configured, so it cannot send that email.', 'notice--warn');
      return;
    }
    if (code.startsWith('HTTP_5')) {
      notice(`The server had a problem (${code}). Try again in a moment.`, 'notice--warn');
      return;
    }
    if (code === 'HTTP_404' || code === 'NOT_FOUND') {
      notice('That endpoint does not exist on the configured server (404) — it may not be a Checkmate API.', 'notice--danger');
      return;
    }
    notice(`Sign-in failed (${code}).`, 'notice--danger');
  }

  // --------------------------------------------------------------- submit
  async function submit() {
    if (busy) return;
    if (!apiConfigured()) {
      authError({ code: 'NOT_CONFIGURED' });
      return;
    }
    if (!validate()) return;

    const email = emailField.input.value.trim();
    const password = passwordField.input.value;
    const displayName = nameField.input.value.trim();

    setBusy(true);
    notice('');
    try {
      const res = mode === 'login'
        ? await api.login({ email, password })
        : await api.register({ email, password, display_name: displayName });
      if (cancelled) return;
      const data = res && res.data ? res.data : {};

      if (data.access_token) {
        setTokens({ access: data.access_token, refresh: data.refresh_token });
      }

      let user = data.user || null;
      if (!user && accessToken()) {
        // Some deployments return tokens only; /auth/me fills in the profile.
        try {
          const me = await api.me();
          if (cancelled) return;
          user = me && me.data ? me.data.user : null;
        } catch {
          user = null;
        }
      }
      if (user) setSession(user);

      haptic(18);
      playSound('success');
      toast(mode === 'login' ? 'Signed in' : 'Account created — check your email to verify it', { type: 'success' });
      go('/');
    } catch (err) {
      if (cancelled) return;
      authError(err);
      playSound('error');
    } finally {
      if (!cancelled) setBusy(false);
    }
  }

  // ------------------------------------------------------ secondary flows
  async function forgotPassword(btn) {
    const email = emailField.input.value.trim();
    if (!EMAIL_RE.test(email)) {
      emailField.set('Enter your email address first.');
      emailField.input.focus();
      return;
    }
    if (!apiConfigured()) { authError({ code: 'NOT_CONFIGURED' }); return; }

    btn.disabled = true;
    notice('');
    try {
      await api.forgotPassword(email);
      if (cancelled) return;
      // The server answers identically whether or not the address exists.
      notice('If that address exists, a reset link has been sent.', '');
      toast('Reset link requested', { type: 'success' });
    } catch (err) {
      if (cancelled) return;
      authError(err);
    } finally {
      if (!cancelled) btn.disabled = false;
    }
  }

  async function resendVerification(btn) {
    const email = emailField.input.value.trim();
    if (email && !EMAIL_RE.test(email)) {
      emailField.set('That does not look like an email address.');
      emailField.input.focus();
      return;
    }
    if (!apiConfigured()) { authError({ code: 'NOT_CONFIGURED' }); return; }

    btn.disabled = true;
    notice('');
    try {
      await api.resendVerification(email || null);
      if (cancelled) return;
      notice('If that address exists and is not verified yet, a new verification link has been sent.', '');
      toast('Verification email requested', { type: 'success' });
    } catch (err) {
      if (cancelled) return;
      authError(err);
    } finally {
      if (!cancelled) btn.disabled = false;
    }
  }

  // ------------------------------------------------------------- chrome
  const forgotBtn = el('button', { class: 'btn btn--ghost btn--sm', type: 'button', text: 'Forgot password?' });
  forgotBtn.addEventListener('click', () => forgotPassword(forgotBtn));

  const resendBtn = el('button', { class: 'btn btn--ghost btn--sm', type: 'button', text: 'Resend verification email' });
  resendBtn.addEventListener('click', () => resendVerification(resendBtn));

  const altHolder = el('div', { class: 'auth-alt' });

  function paintAlt() {
    clear(altHolder);
    if (mode === 'login') {
      altHolder.appendChild(el('span', { text: 'No account yet? ' }));
      altHolder.appendChild(el('button', { type: 'button', text: 'Create one', onClick: () => setMode('register') }));
    } else {
      altHolder.appendChild(el('span', { text: 'Already have an account? ' }));
      altHolder.appendChild(el('button', { type: 'button', text: 'Sign in', onClick: () => setMode('login') }));
    }
  }

  function setMode(next) {
    if (mode === next) return;
    mode = next;
    notice('');
    nameField.set('');
    emailField.set('');
    passwordField.set('');
    passwordField.input.value = '';
    nameField.wrap.classList.toggle('hidden', mode !== 'register');
    passwordField.input.autocomplete = mode === 'register' ? 'new-password' : 'current-password';
    const title = root.querySelector('.topbar__title');
    if (title) title.textContent = mode === 'register' ? 'Create account' : 'Sign in';
    setBusy(false);
    paintAlt();
    scroll.scrollTop = 0;
  }

  const guestBtn = el('button', {
    class: 'btn btn--secondary btn--block mt-3',
    type: 'button',
    onClick: () => { playSound('tap'); go('/'); },
  }, icon('user', 18), el('span', { text: 'Play as guest' }));

  // ------------------------------------------------------------- layout
  scroll.appendChild(sessionHolder);
  scroll.appendChild(noticeHolder);
  scroll.appendChild(form);

  scroll.appendChild(el('div', { class: 'row justify-between mt-2' },
    forgotBtn,
    resendBtn,
  ));
  scroll.appendChild(altHolder);

  scroll.appendChild(el('div', { class: 'mt-4' }, guestBtn));
  scroll.appendChild(el('p', { class: 'tiny muted center mt-2',
    text: 'No account needed — games, puzzles and lessons are stored on this device either way.' }));

  scroll.appendChild(emptyState({
    iconName: 'shield',
    title: 'Why sign in?',
    hint: 'Rated games against real accounts, ratings kept on your account, and history that follows you to another device. Your local games are never deleted by signing out.',
  }));

  nameField.wrap.classList.add('hidden');
  paintSession();
  paintAlt();
  setBusy(false);

  return {
    el: root,
    keepScreenOn: false,
    onLeave: () => { cancelled = true; },
  };
}
