/**
 * Hash-based SPA router.
 *
 * Hash routing is deliberate: the Android shell serves one index.html and
 * falls back to it for unknown paths, and the WebView back button maps
 * directly to history (canGoBack), so hardware back = in-app back.
 */
import { clear } from './ui/dom.js';

export class Router {
  constructor({ outlet, routes = [], tabbar = null, onNavigate = null }) {
    this.outlet = outlet;
    this.tabbar = tabbar;
    this.onNavigate = onNavigate;
    this.routes = [];
    this.current = null;      // { path, params, handle }
    this.stack = [];          // visited hashes for `back()`
    for (const r of routes) this.add(r);
  }

  add({ path, screen, tabs = false, title = '' }) {
    const keys = [];
    const pattern = path
      .split('/')
      .map((seg) => {
        if (seg.startsWith(':')) {
          keys.push(seg.slice(1));
          return '([^/]+)';
        }
        return seg.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      })
      .join('/');
    this.routes.push({ path, regex: new RegExp(`^${pattern}/?$`), keys, screen, tabs, title });
  }

  match(hashPath) {
    for (const r of this.routes) {
      const m = r.regex.exec(hashPath);
      if (m) {
        const params = {};
        r.keys.forEach((k, i) => { params[k] = decodeURIComponent(m[i + 1]); });
        return { route: r, params };
      }
    }
    return null;
  }

  path() {
    const h = window.location.hash || '#/';
    return h.slice(1) || '/';
  }

  navigate(path, { replace = false } = {}) {
    const target = path.startsWith('#') ? path : `#${path}`;
    if (replace) {
      window.location.replace(target);
    } else {
      window.location.hash = target;
    }
  }

  back() {
    if (window.history.length > 1 && this.stack.length > 1) {
      window.history.back();
    } else {
      this.navigate('/');
    }
  }

  start() {
    window.addEventListener('hashchange', () => this.render());
    this.render({ initial: true });
  }

  async render(opts = {}) {
    const path = this.path();
    const matched = this.match(path) || this.match('/');

    if (this.current && this.current.route.screen !== matched.route.screen) {
      await this.current.handle?.onLeave?.();
    }
    if (!opts.initial) this.stack.push(path);

    const ctx = { params: matched.params, path, router: this };
    let handle;
    try {
      const produced = await matched.route.screen(ctx);
      handle = produced && produced.el ? produced : { el: produced };
    } catch (err) {
      console.error('screen failed', path, err);
      handle = {
        el: (() => {
          const div = document.createElement('div');
          div.className = 'screen';
          div.textContent = 'This screen failed to load.';
          return div;
        })(),
      };
    }

    clear(this.outlet);
    this.outlet.appendChild(handle.el);

    // tab visibility + highlight
    if (this.tabbar) {
      this.tabbar.classList.toggle('hidden', !matched.route.tabs);
      if (matched.route.tabs) {
        for (const btn of this.tabbar.querySelectorAll('.tabbar__tab')) {
          const target = btn.dataset.route;
          const active = target !== '/' ? path.startsWith(target) : path === '/';
          if (active) btn.setAttribute('aria-current', 'page');
          else btn.removeAttribute('aria-current');
        }
      }
    }

    document.title = matched.route.title ? `${matched.route.title} · Checkmate` : 'Checkmate';

    this.current = { path, params: matched.params, route: matched.route, handle };
    handle.onEnter?.(ctx);
    this.onNavigate?.(path, matched.route);

    // scroll reset (screens own their scroll containers)
    this.outlet.scrollTop = 0;
    window.scrollTo(0, 0);
  }
}

export function go(path, opts) {
  const h = window.location.hash || '#/';
  const from = h.slice(1) || '/';
  if (from === path) return;
  window.dispatchEvent(new CustomEvent('cm:navigate', { detail: { path, opts } }));
}
