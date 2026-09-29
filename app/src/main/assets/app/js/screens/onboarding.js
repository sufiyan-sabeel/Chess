/**
 * Splash-onboarding (first run only) — honest about what the app is.
 * No fake statistics, no fabricated testimonials.
 */

import { el } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { updateSettings, getSettings } from '../store.js';
import { toast } from '../ui/components.js';

export function onboardingScreen() {
  const root = el('div', { class: 'screen' });

  const features = [
    { icon: 'wifiOff', title: 'Play offline', sub: 'Full games against the built-in engine, no connection needed.' },
    { icon: 'clock', title: 'Real clocks', sub: 'Timed controls with increment, running on a monotonic timer.' },
    { icon: 'users', title: 'Play others', sub: 'Sign in to queue for rated games against real accounts.' },
    { icon: 'book', title: 'Learn & solve', sub: 'Lessons and verified tactical puzzles stored on your device.' },
  ];

  root.appendChild(
    el('div', { class: 'onboard' },
      el('svg', { class: 'onboard__logo', viewBox: '0 0 45 45', 'aria-hidden': 'true' },
        el('rect', { width: 45, height: 45, rx: 10, fill: '#1d2a14' }),
        el('path', { d: 'M21.3 8h2.4v2.7h2.7v2.4h-2.7v2.7h-2.4v-2.7h-2.7v-2.4h2.7z', fill: '#81B64C' }),
        el('path', { d: 'M13 30.5c0-6 3-8.5 3-12.5h12c0 4 3 6.5 3 12.5z', fill: '#81B64C' }),
        el('rect', { x: 12, y: 31, width: 21, height: 4, rx: 2, fill: '#81B64C' }),
      ),
      el('h1', { class: 'onboard__hero', html: 'Serious chess.<br><em>On your phone.</em>' }),
      el('p', { class: 'onboard__sub', text: 'CHECKMATE runs entirely from bundled assets — no web view of a remote site, no trackers.' }),

      el('div', { class: 'mt-4' },
        features.map((f) => el('div', { class: 'feature mb-2' },
          el('div', { class: 'feature__icon' }, icon(f.icon, 22)),
          el('div', {},
            el('div', { class: 'feature__title', text: f.title }),
            el('div', { class: 'feature__sub', text: f.sub }),
          ),
        )),
      ),

      el('div', { class: 'onboard__actions' },
        el('button', {
          class: 'btn btn--primary btn--lg btn--block',
          type: 'button',
          text: 'Start playing',
          onClick: () => finish(true),
        }),
        el('button', {
          class: 'btn btn--ghost btn--block',
          type: 'button',
          text: 'Skip for now',
          onClick: () => finish(false),
        }),
      ),

      el('div', { class: 'onboard__legal', text: 'Games and preferences stay on this device unless you sign in.' }),
    ),
  );

  function finish(remember) {
    updateSettings({ seenOnboarding: true });
    if (remember) toast('Welcome to Checkmate', { type: 'success', durationMs: 1800 });
    window.dispatchEvent(new CustomEvent('cm:navigate', { detail: { path: '/', opts: { replace: true } } }));
  }

  // Already onboarded (e.g. deep link) -> go straight home.
  if (getSettings().seenOnboarding) {
    setTimeout(() => window.dispatchEvent(new CustomEvent('cm:navigate', { detail: { path: '/', opts: { replace: true } } })), 0);
  }

  return { el: root, keepScreenOn: false };
}
