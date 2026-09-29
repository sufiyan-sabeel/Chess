/**
 * Shared UI primitives: toasts, modals/confirm sheets, top bars, empty states.
 *
 * All of these are DOM-only (no framework) and mount into dedicated global
 * roots that `main.js` creates, so screens never fight over the same node.
 */

import { el, clear } from './dom.js';
import { icon } from './icons.js';

let toastRoot = null;
let modalRoot = null;

export function initComponents({ toasts, modals } = {}) {
  toastRoot = toasts || null;
  modalRoot = modals || null;
}

function ensureToastRoot() {
  if (toastRoot && document.body.contains(toastRoot)) return toastRoot;
  toastRoot = el('div', { class: 'toast-root', 'aria-live': 'polite' });
  document.body.appendChild(toastRoot);
  return toastRoot;
}

function ensureModalRoot() {
  if (modalRoot && document.body.contains(modalRoot)) return modalRoot;
  modalRoot = el('div', { class: 'modal-root' });
  document.body.appendChild(modalRoot);
  return modalRoot;
}

/**
 * Transient message.
 * @param {string} message
 * @param {{type?:'success'|'error'|'info', durationMs?:number}} opts
 */
export function toast(message, { type = 'info', durationMs = 2600 } = {}) {
  const root = ensureToastRoot();
  const cls = type === 'success' ? 'toast--success' : type === 'error' ? 'toast--error' : '';
  const node = el('div', { class: `toast ${cls}`.trim(), role: 'status' }, String(message));
  root.appendChild(node);
  const remove = () => {
    node.classList.add('toast--leaving');
    setTimeout(() => node.remove(), 220);
  };
  setTimeout(remove, durationMs);
  return node;
}

/**
 * Modal sheet. Content is a Node; actions are resolved through buttons you
 * place yourself (no fake promise magic).
 * @param {{title?:string, body?:Node|string, actions?:Node[], dismissible?:boolean, onClose?:()=>void}} opts
 */
export function modal({ title = '', body = null, actions = [], dismissible = true, onClose = null } = {}) {
  const root = ensureModalRoot();
  clear(root);
  root.classList.remove('hidden');

  const card = el('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true' });
  if (title) card.appendChild(el('h3', { class: 'modal__title', text: title }));
  if (body) card.appendChild(el('div', { class: 'modal__body' }, body));
  if (actions.length) card.appendChild(el('div', { class: 'modal__actions' }, actions));

  let closed = false;
  const close = () => {
    if (closed) return;
    closed = true;
    root.removeEventListener('pointerdown', onBackdrop);
    clear(root);
    root.classList.add('hidden');
    onClose?.();
  };

  // The root itself acts as the backdrop (it already paints the scrim).
  const onBackdrop = (ev) => {
    if (dismissible && ev.target === root) close();
  };
  root.addEventListener('pointerdown', onBackdrop);

  root.appendChild(card);
  card.querySelector('button')?.focus?.();

  return { close, card };
}

/**
 * Confirmation dialog returning a promise (used for resign/quit/reset).
 * @param {{title:string, message:string, confirmLabel?:string, cancelLabel?:string, danger?:boolean}} opts
 * @returns {Promise<boolean>}
 */
export function confirm({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false }) {
  return new Promise((resolve) => {
    let settled = false;
    const done = (value) => {
      if (settled) return;
      settled = true;
      handle.close();
      resolve(value);
    };
    const handle = modal({
      title,
      body: el('p', { class: 'muted small', text: message }),
      actions: [
        el('button', { class: 'btn btn--ghost', type: 'button', text: cancelLabel, onClick: () => done(false) }),
        el('button', {
          class: `btn ${danger ? 'btn--danger' : 'btn--primary'}`,
          type: 'button',
          text: confirmLabel,
          onClick: () => done(true),
        }),
      ],
      onClose: () => { if (!settled) { settled = true; resolve(false); } },
    });
  });
}

/**
 * Screen header with optional back button.
 * @param {{title:string, onBack?:()=>void, right?:Node, subtitle?:string}} opts
 */
export function topbar({ title, onBack = null, right = null, subtitle = '' }) {
  const bar = el('header', { class: 'topbar' });
  if (onBack) {
    bar.appendChild(
      el('button', {
        class: 'btn btn--icon topbar__back',
        type: 'button',
        'aria-label': 'Back',
        onClick: onBack,
      }, icon('back', 22)),
    );
  }
  bar.appendChild(el('div', {}, el('div', { class: 'topbar__title', text: title }),
    subtitle ? el('div', { class: 'tiny muted', text: subtitle }) : null));
  if (right) bar.appendChild(right);
  return bar;
}

/** Section heading with optional trailing action node. */
export function section(title, action = null) {
  return el('div', { class: 'section-title' },
    el('h2', { text: title }),
    action || null);
}

/**
 * Empty state — used whenever there is genuinely nothing to show.
 * @param {{iconName?:string, title:string, hint?:string, action?:Node}} opts
 */
export function emptyState({ iconName = 'info', title, hint = '', action = null }) {
  return el('div', { class: 'empty' },
    el('div', { class: 'empty__icon' }, icon(iconName, 28)),
    el('div', { class: 'empty__title', text: title }),
    hint ? el('div', { class: 'empty__hint muted small', text: hint }) : null,
    action || null);
}

/** Loading skeleton block (never implies data we do not have). */
export function skeleton(height = 56, count = 3) {
  const frag = document.createDocumentFragment();
  for (let i = 0; i < count; i++) {
    frag.appendChild(el('div', { class: 'skeleton', style: { height: `${height}px`, marginBottom: '8px' } }));
  }
  return frag;
}

/** Stat tile. */
export function statTile({ label, value, tone = '' }) {
  return el('div', { class: `stat-tile ${tone ? `stat-tile--${tone}` : ''}`.trim() },
    el('div', { class: 'stat-tile__value', text: String(value) }),
    el('div', { class: 'stat-tile__label', text: label }));
}

/** Coloured avatar circle with deterministic initials. */
export function avatarNode(name, seed, size = 'md') {
  const hue = hueOf(seed);
  const cls = size === 'lg' ? 'avatar avatar--lg' : size === 'sm' ? 'avatar avatar--sm' : 'avatar';
  return el('div', {
    class: cls,
    style: { background: `hsl(${hue} 34% 32%)`, color: `hsl(${hue} 70% 86%)` },
    'aria-hidden': 'true',
    text: initialsOf(name),
  });
}

function hueOf(seed) {
  let h = 0;
  const s = String(seed || '');
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
  return h % 360;
}

function initialsOf(name) {
  const parts = String(name || '?').trim().split(/[\s_\-]+/).filter(Boolean);
  if (!parts.length) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}
