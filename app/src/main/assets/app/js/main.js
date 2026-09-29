/**
 * Application entry point.
 *
 * Responsibilities (and nothing more):
 *  - boot order: settings -> document attributes -> components -> router,
 *  - route table + tab bar wiring,
 *  - splash dismissal only after the first screen has actually rendered,
 *  - global listeners (online/offline, visibility -> clock pause, back key).
 *
 * Screens live in `js/screens/*` and are plain functions returning
 * `{ el, onEnter?, onLeave? }` (see js/router.js).
 */

import { Router } from './router.js';
import { icon } from './ui/icons.js';
import { initComponents } from './ui/components.js';
import { applyDocumentSettings, getSetting, updateSettings, subscribe, platformInfo, appVersion } from './store.js';

// screens
import { homeScreen } from './screens/home.js';
import { onboardingScreen } from './screens/onboarding.js';
import { playScreen } from './screens/play.js';
import { gameScreen } from './screens/game.js';
import { statsScreen } from './screens/stats.js';
import { leaderboardScreen } from './screens/leaderboard.js';
import { puzzlesScreen, puzzleDetailScreen } from './screens/puzzles.js';
import { learnScreen, lessonDetailScreen } from './screens/learn.js';
import { reviewScreen } from './screens/review.js';
import { profileScreen } from './screens/profile.js';
import { settingsScreen } from './screens/settings.js';
import { watchScreen } from './screens/watch.js';
import { moreScreen } from './screens/more.js';
import { authScreen } from './screens/auth.js';

const ROUTES = [
  { path: '/', screen: homeScreen, tabs: true, title: 'Home' },
  { path: '/onboarding', screen: onboardingScreen, title: 'Welcome' },
  { path: '/play', screen: playScreen, tabs: true, title: 'Play' },
  { path: '/game', screen: gameScreen, title: 'Game' },
  { path: '/stats', screen: statsScreen, title: 'Statistics' },
  { path: '/leaderboard', screen: leaderboardScreen, title: 'Leaderboard' },
  { path: '/puzzles', screen: puzzlesScreen, tabs: true, title: 'Puzzles' },
  { path: '/puzzles/:id', screen: puzzleDetailScreen, title: 'Puzzle' },
  { path: '/learn', screen: learnScreen, tabs: true, title: 'Learn' },
  { path: '/learn/:id', screen: lessonDetailScreen, title: 'Lesson' },
  { path: '/review/:id', screen: reviewScreen, title: 'Review' },
  { path: '/profile', screen: profileScreen, title: 'Profile' },
  { path: '/settings', screen: settingsScreen, title: 'Settings' },
  { path: '/watch', screen: watchScreen, title: 'Watch' },
  { path: '/more', screen: moreScreen, tabs: true, title: 'More' },
  { path: '/auth', screen: authScreen, title: 'Sign in' },
];

/** Screens that are suspended (clocks paused) when hidden. */
export let activeScreenHandle = null;

function paintTabIcons(tabbar) {
  for (const btn of tabbar.querySelectorAll('.tabbar__tab')) {
    const holder = btn.querySelector('.tabbar__ico');
    if (holder && !holder.firstChild) holder.appendChild(icon(holder.dataset.icon, 22));
  }
}

function boot() {
  applyDocumentSettings();

  const outlet = document.getElementById('view');
  const tabbar = document.getElementById('tabbar');

  initComponents({
    toasts: document.getElementById('toast-root'),
    modals: document.getElementById('modal-root'),
  });

  paintTabIcons(tabbar);

  const router = new Router({
    outlet,
    routes: ROUTES,
    tabbar,
    onNavigate: (path, route) => {
      activeScreenHandle = router.current ? router.current.handle : null;
      // Never show the tab bar during an active game or onboarding.
      const hideTabs = path === '/game' || path === '/onboarding';
      tabbar.classList.toggle('hidden', hideTabs || !route.tabs);
      updateSettings({ lastRoute: path });
    },
  });

  // tab clicks
  tabbar.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.tabbar__tab');
    if (!btn) return;
    router.navigate(btn.dataset.route);
  });

  // router.js `go()` helper broadcasts here
  window.addEventListener('cm:navigate', (ev) => {
    router.navigate(ev.detail.path, ev.detail.opts || {});
  });

  // --------------------------------------------------------- global wiring

  // Offline/online indicator -> mirrored for CSS + screens.
  const setOnline = (online) => {
    document.documentElement.dataset.online = online ? '1' : '0';
    window.dispatchEvent(new CustomEvent('cm:connection', { detail: { online } }));
  };
  setOnline(navigator.onLine !== false);
  window.addEventListener('online', () => setOnline(true));
  window.addEventListener('offline', () => setOnline(false));

  // Pause live clocks while the app is backgrounded (visibility + native).
  document.addEventListener('visibilitychange', () => {
    const hidden = document.visibilityState === 'hidden';
    window.dispatchEvent(new CustomEvent('cm:visibility', { detail: { hidden } }));
    try {
      window.CheckmateNative?.setKeepScreenOn(!hidden && Boolean(activeScreenHandle && activeScreenHandle.keepScreenOn));
    } catch { /* bridge optional */ }
  });

  // Settings changes that affect the document.
  subscribe((settings, patch) => {
    if (patch === '*' || 'boardTheme' in patch || 'pieceTheme' in patch || 'coords' in patch || 'reduceMotion' in patch) {
      applyDocumentSettings();
    }
  });

  router.start();

  // Splash stays until the first screen has painted — no fake progress.
  const splash = document.getElementById('splash');
  const foot = document.getElementById('splash-foot');
  if (foot) foot.textContent = `${appVersion()} · ${platformInfo()}`;
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      splash.classList.add('splash--leaving');
      setTimeout(() => splash.remove(), 400);
      // First run -> onboarding (once).
      if (!getSetting('seenOnboarding') && (router.path() === '/' || router.path() === '')) {
        router.navigate('/onboarding', { replace: true });
      }
    });
  });

  // Expose a tiny debug surface for the shell / tests (no secrets).
  window.__checkmate = { router, version: appVersion(), platform: platformInfo() };
  window.__checkmateShellReady = true;
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}

export { ROUTES };
