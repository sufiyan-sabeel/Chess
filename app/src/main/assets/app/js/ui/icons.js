/**
 * Original icon set — simple geometric strokes drawn for Checkmate.
 * All icons are 24x24, stroke-based, and inherit `currentColor`.
 * No third-party icon assets are bundled.
 */
import { svgEl } from './dom.js';

const P = {
  home: '<path d="M3.5 11.2 12 3.8l8.5 7.4"/><path d="M5.5 10v9.2a1 1 0 0 0 1 1H10v-5.4h4v5.4h3.5a1 1 0 0 0 1-1V10"/>',
  play: '<path d="M8.5 5.6 18.4 12 8.5 18.4z" stroke-linejoin="round"/>',
  board: '<rect x="3.5" y="3.5" width="17" height="17" rx="1.5"/><path d="M3.5 9.2h17M3.5 14.8h17M9.2 3.5v17M14.8 3.5v17"/>',
  book: '<path d="M4.5 5.4A2.4 2.4 0 0 1 6.9 3H19v15.6H6.9a2.4 2.4 0 0 0-2.4 2.4z"/><path d="M4.5 18.6a2.4 2.4 0 0 1 2.4-2.4H19"/><path d="M9 7.5h6"/>',
  chart: '<path d="M4 20h16"/><rect x="5" y="11" width="3.2" height="6" rx="1"/><rect x="10.4" y="7" width="3.2" height="10" rx="1"/><rect x="15.8" y="13" width="3.2" height="4" rx="1"/>',
  trophy: '<path d="M7.5 4h9v5.2a4.5 4.5 0 0 1-9 0z"/><path d="M7.5 5.6H5.2a2.4 2.4 0 0 0 2.4 4.6M16.5 5.6h2.3a2.4 2.4 0 0 1-2.4 4.6"/><path d="M12 13.7v3.1M9 20h6l-.7-2.2H9.7z" stroke-linejoin="round"/>',
  user: '<circle cx="12" cy="8.4" r="3.6"/><path d="M5 19.6c.7-3.3 3.6-5.1 7-5.1s6.3 1.8 7 5.1"/>',
  users: '<circle cx="9.4" cy="9" r="3.1"/><path d="M3.6 19c.6-2.9 3-4.5 5.8-4.5s5.2 1.6 5.8 4.5"/><path d="M15.5 6.4a3 3 0 0 1 0 5.4M17.4 14.9c1.6.7 2.7 2 3 4.1"/>',
  bot: '<rect x="4.5" y="7.5" width="15" height="11" rx="3"/><path d="M12 4.2v3.3M8.6 12.4v1.6M15.4 12.4v1.6"/><circle cx="12" cy="4" r="1.2"/>',
  gear: '<circle cx="12" cy="12" r="3.2"/><path d="M12 3.4v2.2M12 18.4v2.2M3.4 12h2.2M18.4 12h2.2M6 6l1.6 1.6M16.4 16.4 18 18M18 6l-1.6 1.6M7.6 16.4 6 18"/>',
  back: '<path d="M14.5 5.5 8 12l6.5 6.5"/>',
  chevron: '<path d="M9.5 5.5 16 12l-6.5 6.5"/>',
  chevronDown: '<path d="M5.5 9.5 12 16l6.5-6.5"/>',
  close: '<path d="M6.4 6.4l11.2 11.2M17.6 6.4 6.4 17.6"/>',
  check: '<path d="m5 12.6 4.6 4.6L19 7.4"/>',
  clock: '<circle cx="12" cy="12" r="8.2"/><path d="M12 7.4V12l3.2 2"/>',
  flag: '<path d="M6.2 20.6V4.2h11.6l-2.3 3.9 2.3 3.9H6.2"/>',
  swap: '<path d="M4.5 8.6h12.8l-3.4-3.4M19.5 15.4H6.7l3.4 3.4"/>',
  flip: '<path d="M12 3.6v16.8"/><path d="m7.6 8 4.4-4.4L16.4 8M7.6 16l4.4 4.4 4.4-4.4"/>',
  undo: '<path d="M8.4 6.6 4.6 10.4l3.8 3.8"/><path d="M4.6 10.4h9.2a5.2 5.2 0 0 1 0 10.4h-3"/>',
  sound: '<path d="M5 9.4h3.2L13 5.6v12.8l-4.8-3.8H5z" stroke-linejoin="round"/><path d="M16.4 9.4a4 4 0 0 1 0 5.2M18.8 7.4a7.2 7.2 0 0 1 0 9.2"/>',
  soundOff: '<path d="M5 9.4h3.2L13 5.6v12.8l-4.8-3.8H5z" stroke-linejoin="round"/><path d="m16.4 9.8 4.4 4.4M20.8 9.8l-4.4 4.4"/>',
  hash: '<path d="M9.6 4.2 8.4 19.8M15.6 4.2l-1.2 15.6M4.6 9.2h15M4 14.8h15"/>',
  expand: '<path d="M9.4 4.4H4.4v5M14.6 4.4h5v5M9.4 19.6h-5v-5M14.6 19.6h5v-5"/>',
  collapse: '<path d="M4.4 9.4h5v-5M19.6 9.4h-5v-5M4.4 14.6h5v5M19.6 14.6h-5v5"/>',
  copy: '<rect x="8.6" y="8.6" width="11" height="11" rx="2"/><path d="M5.4 15.4h-.8a2 2 0 0 1-2-2V5.4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v.8"/>',
  download: '<path d="M12 4v10.4M8 11l4 4 4-4M4.6 19.4h14.8"/>',
  upload: '<path d="M12 19.4V9M8 12.6l4-4 4 4M4.6 4.6h14.8"/>',
  search: '<circle cx="10.8" cy="10.8" r="6.2"/><path d="m15.4 15.4 4.2 4.2"/>',
  refresh: '<path d="M19.4 12a7.4 7.4 0 1 1-2.4-5.4"/><path d="M19.6 4.6v4.2h-4.2"/>',
  plus: '<path d="M12 5.4v13.2M5.4 12h13.2"/>',
  minus: '<path d="M5.4 12h13.2"/>',
  wifi: '<path d="M3.6 9.4a13 13 0 0 1 16.8 0M6.8 13a8.2 8.2 0 0 1 10.4 0M10 16.5a3.6 3.6 0 0 1 4 0"/><circle cx="12" cy="19.4" r="1.1"/>',
  wifiOff: '<path d="M3.6 9.4a13 13 0 0 1 5.2-3M15.4 6.6a13 13 0 0 1 5 2.8M8.2 13.2a8.2 8.2 0 0 1 3-1.8M10 16.5a3.6 3.6 0 0 1 4 0"/><path d="m4 4 16 16"/>',
  info: '<circle cx="12" cy="12" r="8.4"/><path d="M12 11v5.4"/><circle cx="12" cy="7.9" r="1"/>',
  shield: '<path d="M12 3.4 19.4 6v6.2c0 4.6-3.4 7.4-7.4 8.4-4-1-7.4-3.8-7.4-8.4V6z"/><path d="m8.8 12 2.4 2.4 4-4.4"/>',
  logout: '<path d="M14.4 4.4h3.2a2 2 0 0 1 2 2v11.2a2 2 0 0 1-2 2h-3.2"/><path d="m9.4 16-3.8-4 3.8-4M5.6 12h9.4"/>',
  trash: '<path d="M4.8 6.8h14.4M9.4 6.8V4.9a1 1 0 0 1 1-1h3.2a1 1 0 0 1 1 1v1.9"/><path d="m6.8 6.8 1 12.1a1.6 1.6 0 0 0 1.6 1.5h5.2a1.6 1.6 0 0 0 1.6-1.5l1-12.1"/>',
  mail: '<rect x="3.4" y="5.4" width="17.2" height="13.2" rx="2.4"/><path d="m4.6 7.6 7.4 5.4 7.4-5.4"/>',
  lock: '<rect x="4.6" y="10.4" width="14.8" height="9.6" rx="2.2"/><path d="M8.2 10.4V8.2a3.8 3.8 0 0 1 7.6 0v2.2"/>',
  eye: '<path d="M2.6 12S6.4 5.8 12 5.8 21.4 12 21.4 12 17.6 18.2 12 18.2 2.6 12 2.6 12z"/><circle cx="12" cy="12" r="2.8"/>',
  eyeOff: '<path d="M4 4.6 20 20.6"/><path d="M7.4 7.6C4.6 9.4 2.6 12 2.6 12s3.8 6.2 9.4 6.2c1.9 0 3.5-.7 4.9-1.6M10 6.1c.7-.2 1.3-.3 2-.3 5.6 0 9.4 6.2 9.4 6.2s-1 1.7-2.9 3.3"/>',
  sparkle: '<path d="M12 4.2 13.9 9l4.9 1.9-4.9 1.9L12 17.6l-1.9-4.8L5.2 10.9 10.1 9z" stroke-linejoin="round"/><path d="M18.6 4.2v3M20.1 5.7h-3"/>',
  target: '<circle cx="12" cy="12" r="8.2"/><circle cx="12" cy="12" r="4.6"/><circle cx="12" cy="12" r="1.2"/>',
  star: '<path d="m12 4.4 2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 10l5.4-.8z" stroke-linejoin="round"/>',
  crown: '<path d="M4.4 8.4 8 12.4l4-6.6 4 6.6 3.6-4-1.4 9.6H5.8z" stroke-linejoin="round"/><path d="M6.4 19.6h11.2"/>',
  handshake: '<path d="M8.4 12.4 11 15l2.4-2.4 2.6 2.2"/><path d="M4.4 9.6 8.4 7l3.6 1.6L15.6 7l4 2.6v4.6l-2.2 1.8-2.4-2"/><path d="m4.4 9.6-1.4 4.4 3.4 3 1.8-1.4M19.6 9.6l1.4 4.4-3.4 3"/>',
  puzzle: '<path d="M9.4 4.4h5.2v2.2a1.9 1.9 0 1 0 3.8 0V4.4h1.2v5.2h-2.2a1.9 1.9 0 1 0 0 3.8h2.2v6.2H4.4V4.4h5z" stroke-linejoin="round"/>',
  hand: '<path d="M8.6 11.4V5.8a1.6 1.6 0 0 1 3.2 0v4.8m0-5.6a1.6 1.6 0 0 1 3.2 0v5.6m0-4a1.6 1.6 0 0 1 3.2 0v6.8c0 3.6-2.6 6.2-6.2 6.2-3 0-4.6-1.4-6-4l-2-3.6a1.6 1.6 0 0 1 2.6-1.8l2 2.4"/>',
  gauge: '<path d="M4.4 17.6a8.6 8.6 0 1 1 15.2 0"/><path d="m12 13.6 3.6-4.2"/>',
  server: '<rect x="3.6" y="4.6" width="16.8" height="6" rx="2"/><rect x="3.6" y="13.4" width="16.8" height="6" rx="2"/><circle cx="7.4" cy="7.6" r="1"/><circle cx="7.4" cy="16.4" r="1"/>',
  globe: '<circle cx="12" cy="12" r="8.4"/><path d="M3.6 12h16.8M12 3.6c2.6 2.6 3.8 5.4 3.8 8.4s-1.2 5.8-3.8 8.4c-2.6-2.6-3.8-5.4-3.8-8.4S9.4 6.2 12 3.6z"/>',
  send: '<path d="M20.4 3.6 3.6 10.4l6.6 2.8 2.8 6.6z" stroke-linejoin="round"/><path d="m10.2 13.2 4.2-4.2"/>',
  lightbulb: '<path d="M9.2 17.4a5.8 5.8 0 1 1 5.6 0v1.8H9.2z" stroke-linejoin="round"/><path d="M10.2 21.2h3.6"/>',
  history: '<path d="M4.6 12a7.4 7.4 0 1 0 2.4-5.4"/><path d="M4.4 4.6v4.2h4.2"/><path d="M12 8.4V12l2.8 1.8"/>',
  grid: '<rect x="4" y="4" width="7" height="7" rx="1.6"/><rect x="13" y="4" width="7" height="7" rx="1.6"/><rect x="4" y="13" width="7" height="7" rx="1.6"/><rect x="13" y="13" width="7" height="7" rx="1.6"/>',
};

/**
 * @param {string} name icon key
 * @param {number} size px
 * @returns {SVGElement}
 */
export function icon(name, size = 24, extraClass = '') {
  const body = P[name] || P.info;
  const node = svgEl('svg', {
    viewBox: '0 0 24 24',
    width: size,
    height: size,
    fill: 'none',
    stroke: 'currentColor',
    'stroke-width': 1.7,
    'stroke-linecap': 'round',
    class: extraClass || null,
    'aria-hidden': 'true',
    focusable: 'false',
  });
  node.innerHTML = body;
  return node;
}

export function hasIcon(name) {
  return Object.prototype.hasOwnProperty.call(P, name);
}

export const ICON_NAMES = Object.keys(P);
