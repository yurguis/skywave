'use strict';

const POLL_INTERVAL_MS = 2000;
const HOSTS_STORAGE_KEY = 'hdhomerun.manualHosts';
const TAB_STORAGE_KEY = 'hdhomerun.tab';
const LOG_SOURCE_STORAGE_KEY = 'skywave.log';
const CAPTIONS_STORAGE_KEY = 'hdhomerun.captions';
const CAPTION_TRACK_STORAGE_KEY = 'hdhomerun.captionTrack';
const AUDIO_STORAGE_KEY = 'hdhomerun.audio';
/** Enough of ISO 639-2 for the languages a broadcast here carries. */
const LANGUAGE_NAMES = {
  eng: 'English', spa: 'Spanish', fra: 'French', fre: 'French', por: 'Portuguese',
  deu: 'German', ger: 'German', ita: 'Italian', hat: 'Haitian Creole', zxx: 'No dialogue',
  // Broadcasts use this for a track carrying more than one language, and "und" when the
  // broadcaster never said. Both are claims about the audio, not descriptions of it: a
  // track labelled Portuguese here turns out to carry Spanish.
  mul: 'Multiple languages', und: 'Undeclared',
};
const QUALITY_STORAGE_KEY = 'hdhomerun.quality';
const GUIDE_WINDOW_HOURS = 4;

// Identifies this tab to the server while it watches a stream. crypto.randomUUID()
// needs HTTPS, which a LAN install usually does not have.
const VIEWER_ID = Array.from(crypto.getRandomValues(new Uint8Array(12)), (byte) => byte.toString(16).padStart(2, '0')).join('');

const state = {
  devices: [],
  selectedHost: null,
  manualHosts: loadManualHosts(),
  channelMaps: new Map(),   // name -> Promise<[{number, frequency}]>
  tunerCards: [],
  pollTimer: null,
  player: null,
  guideView: null,
  recordingsView: null,
};

const TAB_LABELS = { tuners: 'Tuners', guide: 'Guide', recordings: 'Recordings', logs: 'Logs' };
const FORMAT_LABELS = { ts: 'Original', mp4: 'Browser-ready', both: 'Both' };
/** Roughly ten hours of recording; below this the Recordings tab says so. */
const LOW_SPACE_BYTES = 20e9;

// ---------------------------------------------------------------------------
// Helpers

/** Build a DOM element; strings become text nodes, so broadcast data is never parsed as HTML. */
function h(tag, attributes = {}, ...children) {
  const element = document.createElement(tag);

  for (const [name, value] of Object.entries(attributes)) {
    if (value === null || value === undefined || value === false) continue;
    if (name === 'class') element.className = value;
    else if (name.startsWith('on')) element.addEventListener(name.slice(2), value);
    else element.setAttribute(name, value === true ? '' : value);
  }

  for (const child of children.flat()) {
    if (child === null || child === undefined || child === false) continue;
    element.append(child instanceof Node ? child : String(child));
  }

  return element;
}

async function api(path, options = {}) {
  const response = await fetch(path, {
    ...options,
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
  });

  let body = null;
  try { body = await response.json(); } catch { /* not JSON */ }

  if (!response.ok) {
    throw new Error(body?.error ?? `${response.status} ${response.statusText}`);
  }

  return body;
}

function showError(error) {
  const toast = document.getElementById('toast');
  toast.textContent = error instanceof Error ? error.message : String(error);
  toast.hidden = false;
  clearTimeout(showError.timer);
  showError.timer = setTimeout(() => { toast.hidden = true; }, 6000);
}

function loadManualHosts() {
  try {
    const hosts = JSON.parse(localStorage.getItem(HOSTS_STORAGE_KEY) || '[]');
    return Array.isArray(hosts) ? hosts : [];
  } catch {
    return [];
  }
}

function saveManualHosts() {
  try { localStorage.setItem(HOSTS_STORAGE_KEY, JSON.stringify(state.manualHosts)); } catch { /* storage unavailable */ }
}

function loadSetting(key) {
  try { return localStorage.getItem(key); } catch { return null; }
}

function saveSetting(key, value) {
  try { localStorage.setItem(key, value); } catch { /* storage unavailable */ }
}

function timeAgo(unixSeconds) {
  const minutes = Math.round((Date.now() / 1000 - unixSeconds) / 60);
  if (minutes < 1) return 'just now';
  if (minutes < 60) return `${minutes} min ago`;
  if (minutes < 48 * 60) return `${Math.round(minutes / 60)} h ago`;
  return dateTimeFormat.format(unixSeconds * 1000);
}

const hex = (value, digits = 4) => '0x' + Number(value).toString(16).toUpperCase().padStart(digits, '0');
const mbps = (bitsPerSecond) => `${(bitsPerSecond / 1e6).toFixed(2)} Mbps`;
const mhz = (hertz) => `${(hertz / 1e6).toFixed(hertz % 1e6 === 0 ? 0 : 3)} MHz`;

const timeFormat = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' });
const dayFormat = new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
const dateTimeFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** 75 -> "1:15", 3725 -> "1:02:05" */
function formatClock(seconds) {
  const total = Math.max(0, Math.round(seconds));
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const secs = String(total % 60).padStart(2, '0');
  return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${secs}` : `${minutes}:${secs}`;
}

function formatDuration(seconds) {
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `${minutes}m`;
  return minutes % 60 === 0 ? `${minutes / 60}h` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

/**
 * A station's logo, fetched once by the server. Channels without one just show their
 * name, which is why the image removes itself instead of leaving a broken picture.
 */
function programmeArtwork(title, exists) {
  // The server says whether there is one. Asking anyway meant five hundred 404s a
  // sitting, since most of what a broadcast lists has no picture anywhere.
  if (!title || !exists) return null;

  const art = h('img', {
    class: 'programme-art',
    src: `/artwork?title=${encodeURIComponent(title)}`,
    alt: '',
    loading: 'lazy',
  });

  art.addEventListener('error', () => art.remove());

  return art;
}

function channelLogo(virtual, exists = true) {
  // Six channels here have no logo and never will; without this they were asked for
  // hundreds of times a day, every one a 404.
  if (!exists) return null;

  const logo = h('img', {
    class: 'channel-logo',
    src: `/logos/${encodeURIComponent(virtual)}.png`,
    alt: '',
    loading: 'lazy',
  });

  logo.addEventListener('error', () => logo.remove());

  return logo;
}

function formatBytes(bytes) {
  if (bytes >= 1e12) return `${(bytes / 1e12).toFixed(1)} TB`;
  if (bytes >= 1e9) return `${(bytes / 1e9).toFixed(1)} GB`;
  if (bytes >= 1e6) return `${Math.round(bytes / 1e6)} MB`;
  return `${Math.round(bytes / 1e3)} kB`;
}

function channelMapChannels(name) {
  if (!state.channelMaps.has(name)) {
    const request = api(`/api/channelmaps/${encodeURIComponent(name)}`).then((body) => body.channels);
    request.catch(() => state.channelMaps.delete(name));
    state.channelMaps.set(name, request);
  }
  return state.channelMaps.get(name);
}

// ---------------------------------------------------------------------------
// Devices

async function loadDevices() {
  const list = document.getElementById('device-list');
  list.replaceChildren(h('li', { class: 'muted' }, 'Searching…'));

  await rememberManualHosts();

  try {
    const query = state.manualHosts.length ? `?hosts=${encodeURIComponent(state.manualHosts.join(','))}` : '';
    const body = await api(`/api/devices${query}`);
    state.devices = body.devices;
  } catch (error) {
    state.devices = [];
    showError(error);
  }

  renderDeviceList();

  const selected = state.devices.find((device) => device.host === state.selectedHost && !device.error);
  const firstUsable = state.devices.find((device) => !device.error);

  if (selected) selectDevice(selected.host);
  else if (firstUsable) selectDevice(firstUsable.host);
  else renderEmptyDetail();
}

// Devices typed into this browser used to live here alone, which left a phone seeing
// nothing. Hand them to the server, and only keep the ones it will not take.
async function rememberManualHosts() {
  if (state.manualHosts.length === 0) return;

  const unsaved = [];

  for (const host of state.manualHosts) {
    try {
      await api('/api/devices', { method: 'POST', body: JSON.stringify({ host }) });
    } catch {
      unsaved.push(host);
    }
  }

  state.manualHosts = unsaved;
  saveManualHosts();
}

function renderDeviceList() {
  const list = document.getElementById('device-list');

  if (state.devices.length === 0) {
    list.replaceChildren(h('li', { class: 'muted' }, 'No devices found. Add one by IP address.'));
    return;
  }

  list.replaceChildren(...state.devices.map((device) => h('li', { class: 'device-item' },
    h('button', {
      type: 'button',
      'aria-current': device.host === state.selectedHost ? 'true' : 'false',
      disabled: Boolean(device.error),
      onclick: () => selectDevice(device.host),
    },
      h('span', { class: 'name' }, device.error ? device.host : (device.hardwareModel || device.model)),
      device.error
        ? h('span', { class: 'error' }, device.error)
        : h('span', { class: 'meta' }, `${device.host} · ${device.tunerCount} tuners${device.deviceId ? ` · ${device.deviceId}` : ''}`),
    ),
    // A device the server is configured with comes back on the next refresh however
    // often it is removed, so it is not offered a button that cannot work.
    device.source !== 'configured' && (device.source === 'manual' || state.manualHosts.includes(device.host)) && h('button', {
      type: 'button',
      class: 'remove',
      title: `Remove ${device.host}`,
      'aria-label': `Remove ${device.host}`,
      onclick: () => removeManualHost(device.host),
    }, '×'),
  )));
}

async function removeManualHost(host) {
  state.manualHosts = state.manualHosts.filter((candidate) => candidate !== host);
  saveManualHosts();

  try {
    await api(`/api/devices/${encodeURIComponent(host)}`, { method: 'DELETE' });
  } catch { /* the server never knew it; this browser did */ }

  if (state.selectedHost === host) state.selectedHost = null;

  loadDevices();
}

function renderEmptyDetail() {
  stopPolling();
  state.player?.stop();
  state.player = null;
  state.guideView?.destroy();
  state.guideView = null;
  state.recordingsView?.destroy();
  state.recordingsView = null;
  document.getElementById('device-detail').replaceChildren(h('p', { class: 'muted' }, 'Select a device.'));
}

function selectDevice(host) {
  const device = state.devices.find((candidate) => candidate.host === host);
  if (!device || device.error) return;

  state.selectedHost = host;
  renderDeviceList();

  state.player?.stop();
  const playerPanel = h('section', { class: 'player card', hidden: true });
  state.player = createPlayer(playerPanel);

  const analysisPanel = h('section', { class: 'analysis card', hidden: true });
  state.tunerCards = Array.from({ length: device.tunerCount }, (_, index) => createTunerCard(device, index, analysisPanel, state.player));

  state.guideView?.destroy();
  state.guideView = createGuideView(device, state.player);

  state.recordingsView?.destroy();
  state.recordingsView = createRecordingsView(device, state.player);
  state.logsView = createLogsView();

  const views = {
    tuners: h('div', { class: 'view' }, h('div', { class: 'tuners' }, state.tunerCards.map((card) => card.root)), analysisPanel),
    guide: state.guideView.root,
    recordings: state.recordingsView.root,
    logs: state.logsView.root,
  };
  const tabButtons = Object.keys(views).map((name) => h('button', {
    type: 'button',
    role: 'tab',
    class: 'tab',
    'data-tab': name,
    onclick: () => showTab(name),
  }, TAB_LABELS[name]));

  // The tab carries a pulsing dot while something is recording, so it shows from any tab.
  // Loading now also lets the guide know which programs are already scheduled.
  const recordingsTab = tabButtons.find((button) => button.dataset.tab === 'recordings');
  state.recordingsView.onUpdate((summary) => {
    recordingsTab.replaceChildren(...[
      TAB_LABELS.recordings,
      summary.recording > 0 && h('span', { class: 'tab-dot', title: 'Recording now', 'aria-label': 'Recording now' }),
    ].filter(Boolean));

    // The record button answers to the same news: it pulses while the program on screen is
    // being recorded, and pressing it then stops that recording.
    state.player?.recordingsChanged();
  });
  state.recordingsView.load();

  function showTab(name) {
    for (const [key, view] of Object.entries(views)) view.hidden = key !== name;
    for (const button of tabButtons) button.setAttribute('aria-selected', String(button.dataset.tab === name));
    saveSetting(TAB_STORAGE_KEY, name);
    if (name === 'guide') state.guideView.load();
    if (name === 'recordings') state.recordingsView.load();
    if (name === 'logs') state.logsView.load();
  }

  document.getElementById('device-detail').replaceChildren(
    h('div', { class: 'device-header' },
      h('h3', {}, device.hardwareModel || device.model),
      h('div', { class: 'facts' },
        h('span', {}, 'IP ', h('b', {}, device.host)),
        device.deviceId && h('span', {}, 'ID ', h('b', {}, device.deviceId)),
        h('span', {}, 'Model ', h('b', {}, device.model)),
        h('span', {}, 'Firmware ', h('b', {}, device.firmware)),
        h('span', {}, device.source === 'manual' ? 'Added manually' : 'Discovered'),
      ),
    ),
    playerPanel,
    h('div', { class: 'tabs', role: 'tablist' }, tabButtons),
    // Every view, in the order they are declared: listing them by hand here meant a new
    // tab could be added everywhere else and still never reach the page.
    ...Object.values(views),
  );

  const savedTab = loadSetting(TAB_STORAGE_KEY);
  showTab(savedTab !== null && savedTab in views ? savedTab : 'tuners');
  startPolling();
}

// ---------------------------------------------------------------------------
// Tuners

function startPolling() {
  stopPolling();
  refreshTuners();
  state.pollTimer = setInterval(() => { if (!document.hidden) refreshTuners(); }, POLL_INTERVAL_MS);
}

function stopPolling() {
  clearInterval(state.pollTimer);
  state.pollTimer = null;
}

function refreshTuners() {
  for (const card of state.tunerCards) card.refresh();
}

function createTunerCard(device, index, analysisPanel, player) {
  const base = `/api/devices/${encodeURIComponent(device.host)}/tuners/${index}`;
  const host = device.host;
  let inFlight = false;
  let channelSelectDirty = false;
  let lastStatus = null;
  let programsKey = '';

  const badge = h('span', { class: 'badge' }, '…');
  const channelLabel = h('span', { class: 'muted' });
  const meters = {
    strength: meter('Signal strength'),
    quality: meter('Signal quality'),
    symbol: meter('Symbol quality'),
  };
  const facts = h('dl', { class: 'kv' });
  const programs = h('ul', { class: 'programs' });

  const mapSelect = h('select', { 'aria-label': 'Channel map', onchange: onMapChange },
    device.channelMaps.map((name) => h('option', { value: name }, name)));
  const channelSelect = h('select', { class: 'channel-select', 'aria-label': 'Channel', onchange: () => { channelSelectDirty = true; } });
  const tuneButton = h('button', { type: 'button', onclick: onTune }, 'Tune');
  const stopButton = h('button', { type: 'button', class: 'secondary', onclick: onStop }, 'Stop');
  const watchButton = h('button', { type: 'button', class: 'watch', onclick: onWatch, title: 'Watch the first program on this channel' }, '▶ Watch');
  const analyzeButton = h('button', { type: 'button', class: 'secondary', onclick: onAnalyze }, 'Analyze');
  const hint = h('p', { class: 'hint muted' });

  const root = h('article', { class: 'card tuner' },
    h('div', { class: 'tuner-head' }, h('h4', {}, `Tuner ${index}`), channelLabel, badge),
    h('div', { class: 'meters' }, meters.strength.root, meters.quality.root, meters.symbol.root),
    facts,
    programs,
    hint,
    h('div', { class: 'controls' }, mapSelect, channelSelect, tuneButton, watchButton, stopButton, analyzeButton),
  );

  async function refresh() {
    if (inFlight || state.selectedHost !== host) return;
    inFlight = true;
    try {
      render(await api(base));
    } catch (error) {
      badge.textContent = 'unreachable';
      badge.className = 'badge nosignal';
    } finally {
      inFlight = false;
    }
  }

  async function render(status) {
    lastStatus = status;

    badge.textContent = status.locked ? status.lock : status.channel === 'none' ? 'idle' : 'no signal';
    badge.className = `badge ${status.locked ? 'locked' : status.channel === 'none' ? '' : 'nosignal'}`;
    channelLabel.textContent = status.channel === 'none' ? '' : status.channel;

    const signal = status.signal;
    meters.strength.set(signal.strength, signal.strengthColor, signal.strengthDbm === null ? '' : `${signal.strengthDbm} dBm`);
    meters.quality.set(signal.quality, signal.qualityColor, signal.qualityDb === null ? '' : `${signal.qualityDb} dB`);
    meters.symbol.set(signal.symbolQuality, signal.symbolQualityColor, '');

    const rows = [
      ['Bitrate', status.locked ? mbps(status.bitsPerSecond) : '—'],
      ['TSID', status.streamInfo?.transportStreamId != null ? `${status.streamInfo.transportStreamId} (${hex(status.streamInfo.transportStreamId)})` : '—'],
      ['Target', status.target],
      ['Locked by', status.lockOwner ?? '—'],
    ];
    facts.replaceChildren(...rows.flatMap(([label, value]) => [h('dt', {}, label), h('dd', {}, value)]));

    // Rebuild only on change: replacing buttons on every poll can swallow a click.
    const programList = status.streamInfo?.programs ?? [];
    const key = JSON.stringify([status.channel, programList]);

    if (key !== programsKey) {
      programsKey = key;
      programs.replaceChildren(...programList.map((program) => {
        const watchable = program.type === 'normal';

        return h('li', {},
          h('button', {
            type: 'button',
            class: 'program',
            disabled: !watchable,
            title: watchable ? `Watch ${program.virtualChannel} ${program.name}` : `Cannot play: ${program.type.replace('_', ' ')}`,
            onclick: () => player.play({ base, host, tunerIndex: index, program }),
          },
            h('span', { class: 'play', 'aria-hidden': 'true' }, '▶'),
            h('span', { class: 'vch' }, program.virtualChannel || `#${program.number}`),
            program.name || h('span', { class: 'muted' }, '(unnamed)'),
            !watchable && h('span', { class: 'badge' }, program.type.replace('_', ' ')),
          ),
        );
      }));
      programs.hidden = programList.length === 0;
    }

    hint.hidden = programList.some((program) => program.type === 'normal');
    hint.textContent = status.channel === 'none'
      ? 'Tune a channel, then click a program to watch it.'
      : status.locked ? 'No playable programs on this channel.' : 'No signal on this channel.';

    if (mapSelect.value !== status.channelMap && document.activeElement !== mapSelect) {
      mapSelect.value = status.channelMap;
    }
    await fillChannelSelect(status.channelMap, status.physicalChannel);

    updateActionButtons();
  }

  function updateActionButtons() {
    analyzeButton.disabled = !lastStatus?.locked || analysisPanel.dataset.running === 'true';
    watchButton.disabled = firstWatchable(lastStatus) === null;
  }

  async function fillChannelSelect(mapName, physicalChannel) {
    if (channelSelect.dataset.map !== mapName) {
      let channels = [];
      try { channels = await channelMapChannels(mapName); } catch (error) { showError(error); }
      channelSelect.replaceChildren(...channels.map((channel) =>
        h('option', { value: channel.number }, `Ch ${channel.number} · ${mhz(channel.frequency)}`)));
      channelSelect.dataset.map = mapName;
      channelSelectDirty = false;
    }

    if (!channelSelectDirty && physicalChannel !== null && document.activeElement !== channelSelect) {
      channelSelect.value = String(physicalChannel);
    }
  }

  async function busy(button, action) {
    const buttons = [tuneButton, watchButton, stopButton, analyzeButton, mapSelect];
    buttons.forEach((control) => { control.disabled = true; });
    const label = button.textContent;
    button.textContent = `${label}…`;
    try {
      await action();
    } catch (error) {
      showError(error);
    } finally {
      button.textContent = label;
      buttons.forEach((control) => { control.disabled = false; });
      updateActionButtons();
    }
  }

  function onTune() {
    return busy(tuneButton, async () => {
      channelSelectDirty = false;
      render(await api(`${base}/channel`, { method: 'PUT', body: JSON.stringify({ channel: `auto:${channelSelect.value}` }) }));
    });
  }

  function onStop() {
    return busy(stopButton, async () => {
      render(await api(`${base}/channel`, { method: 'PUT', body: JSON.stringify({ channel: 'none' }) }));
    });
  }

  function onMapChange() {
    return busy(mapSelect, async () => {
      render(await api(`${base}/channelmap`, { method: 'PUT', body: JSON.stringify({ channelmap: mapSelect.value }) }));
    });
  }

  function onWatch() {
    const program = firstWatchable(lastStatus);
    if (program) player.play({ base, host, tunerIndex: index, program });
  }

  function onAnalyze() {
    return runAnalysis(analysisPanel, base, index, lastStatus);
  }

  return { root, refresh };
}

function firstWatchable(status) {
  return (status?.streamInfo?.programs ?? []).find((program) => program.type === 'normal') ?? null;
}

function meter(label) {
  const fill = h('div', { class: 'fill' });
  const value = h('span', { class: 'value' });
  const root = h('div', { class: 'meter' }, h('span', {}, label), h('div', { class: 'bar' }, fill), value);

  return {
    root,
    set(percent, color, detail) {
      fill.style.width = `${Math.max(0, Math.min(100, percent))}%`;
      fill.className = `fill ${color}`;
      value.textContent = detail ? `${percent}% · ${detail}` : `${percent}%`;
    },
  };
}

// ---------------------------------------------------------------------------
// Live playback

const ICON_PATHS = {
  play: 'M8 5v14l11-7z',
  pause: 'M6 5h4v14H6zM14 5h4v14h-4z',
  volume: 'M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 8v8a4.5 4.5 0 0 0 2.5-4zM14 3.2v2.1a7 7 0 0 1 0 13.4v2.1a9 9 0 0 0 0-17.6z',
  muted: 'M3 9v6h4l5 5V4L7 9H3zm15.1 3 2.7-2.7-1.4-1.4-2.7 2.7-2.7-2.7-1.4 1.4 2.7 2.7-2.7 2.7 1.4 1.4 2.7-2.7 2.7 2.7 1.4-1.4z',
  captions: 'M19 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm-8 7H9.5v-.5h-2v3h2V13H11v1a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-4a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1zm7 0h-1.5v-.5h-2v3h2V13H18v1a1 1 0 0 1-1 1h-3a1 1 0 0 1-1-1v-4a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1z',
  record: 'M12 6a6 6 0 1 0 0 12a6 6 0 1 0 0-12z',
  stop: 'M6 6h12v12H6z',
  fullscreen: 'M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z',
  exitFullscreen: 'M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z',
};

function icon(name) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  path.setAttribute('d', ICON_PATHS[name]);
  svg.append(path);
  return svg;
}

function iconButton(name, label, onclick, extraClass = '') {
  return h('button', { type: 'button', class: `overlay-icon ${extraClass}`.trim(), title: label, 'aria-label': label, onclick }, icon(name));
}

function setIcon(button, name, label) {
  button.replaceChildren(icon(name));
  button.title = label;
  button.setAttribute('aria-label', label);
}

function createPlayer(panel) {
  let session = null;
  // A recording being watched back: no tuner, no live edge, and a fixed length.
  let vod = null;
  let hls = null;
  let timer = null;
  let current = null;
  let infoTimer = null;
  let hideTimer = null;
  let liveTimer = null;
  let pointerOnControls = false;
  let controlsRevealedAt = 0;
  let seeking = false;
  let qualityChoice = -1;
  // What the channel is airing, kept so the record button knows what to schedule.
  let airing = null;
  let captionsOn = loadSetting(CAPTIONS_STORAGE_KEY) === 'on';
  // A broadcast can carry several caption channels: CC1 is usually the programme's own
  // language and CC3 a second one, so which to draw is the viewer's choice.
  let captionChoice = loadSetting(CAPTION_TRACK_STORAGE_KEY);
  let audioChoice = loadSetting(AUDIO_STORAGE_KEY);
  /** What the server says each audio track was before conversion, in the same order. */
  let audioSources = [];

  const status = h('div', { class: 'overlay-status', hidden: true });
  const stopButton = iconButton('stop', 'Stop playback', () => stop());
  const video = h('video', { autoplay: true, playsinline: true });
  // Browsers only autoplay muted video, and the muted attribute set from script does not
  // mute the element, so set the property. The viewer unmutes from the controls.
  video.muted = true;

  const channelLabel = h('div', { class: 'overlay-channel' });
  const programLabel = h('div', { class: 'overlay-program' });
  const programProgress = h('div', { class: 'overlay-progress', hidden: true }, h('div', {}));
  const liveButton = h('button', { type: 'button', class: 'overlay-live', title: 'Jump to live', onclick: goLive }, 'LIVE');
  const captionLayer = h('div', { class: 'overlay-captions', hidden: true });
  const listenedTracks = new WeakSet();
  const spinner = h('div', { class: 'overlay-spinner', hidden: true });
  const bigPlayButton = iconButton('play', 'Play', togglePlay, 'overlay-bigplay');
  const playButton = iconButton('play', 'Play', togglePlay);
  const muteButton = iconButton('muted', 'Unmute', toggleMute);
  const volumeSlider = h('input', { type: 'range', min: '0', max: '1', step: '0.05', value: '1', class: 'overlay-volume', 'aria-label': 'Volume', oninput: onVolumeInput });
  const behindLabel = h('span', { class: 'overlay-time' });
  const seekBar = h('input', {
    type: 'range',
    class: 'overlay-seekbar',
    min: '0',
    max: '0',
    step: '0.1',
    value: '0',
    disabled: true,
    'aria-label': 'Position in the live stream',
  });
  const qualityButton = h('button', {
    type: 'button',
    class: 'overlay-quality',
    hidden: true,
    'aria-haspopup': 'menu',
    'aria-expanded': 'false',
    onclick: toggleQualityMenu,
  }, 'Auto');
  const qualityMenu = h('div', { class: 'overlay-menu', role: 'menu', 'aria-label': 'Quality', hidden: true });
  const captionsButton = iconButton('captions', 'Captions', onCaptionsClick);
  const captionsMenu = h('div', { class: 'overlay-menu', role: 'menu', 'aria-label': 'Captions', hidden: true });
  const audioButton = h('button', {
    type: 'button',
    class: 'overlay-quality',
    hidden: true,
    'aria-haspopup': 'menu',
    'aria-expanded': 'false',
    onclick: toggleAudioMenu,
  }, 'Audio');
  const audioMenu = h('div', { class: 'overlay-menu', role: 'menu', 'aria-label': 'Audio', hidden: true });
  const recordButton = iconButton('record', 'Record this program', recordCurrentProgram, 'overlay-record');
  const fullscreenButton = iconButton('fullscreen', 'Full screen', toggleFullscreen);

  const wrap = h('div', { class: 'video-wrap', tabindex: '0' },
    video,
    captionLayer,
    spinner,
    status,
    bigPlayButton,
    h('div', { class: 'overlay-top' },
      h('div', { class: 'overlay-info' }, channelLabel, programLabel, programProgress),
      liveButton,
    ),
    h('div', { class: 'overlay-bottom' },
      h('div', { class: 'overlay-seek' }, seekBar),
      h('div', { class: 'overlay-controls' },
        playButton, stopButton, muteButton, volumeSlider, behindLabel,
        h('span', { class: 'overlay-spacer' }),
        h('span', { class: 'overlay-quality-wrap' }, qualityButton, qualityMenu),
        h('span', { class: 'overlay-quality-wrap' }, audioButton, audioMenu),
        h('span', { class: 'overlay-quality-wrap' }, captionsButton, captionsMenu),
        recordButton, fullscreenButton,
      ),
    ),
  );

  panel.replaceChildren(wrap);

  // Clicking the picture toggles playback; double-clicking toggles full screen.
  video.addEventListener('click', onVideoClick);
  video.addEventListener('dblclick', toggleFullscreen);
  video.addEventListener('play', updatePlayState);
  video.addEventListener('pause', updatePlayState);
  video.addEventListener('playing', () => { spinner.hidden = true; setStatus(vod ? 'Playing' : 'Live'); });
  video.addEventListener('waiting', () => { if (session || vod) { spinner.hidden = false; setStatus('Buffering…'); } });
  video.addEventListener('ended', () => { if (vod) setStatus('Ended'); });
  video.addEventListener('volumechange', updateVolumeState);
  video.addEventListener('timeupdate', updateLiveState);
  // hls.js adds a text track once it finds CEA-608 captions in the video.
  video.textTracks.addEventListener('addtrack', applyCaptions);
  document.addEventListener('fullscreenchange', updateFullscreenState);

  wrap.addEventListener('mousemove', showControls);
  // A touch no longer reveals the controls by itself: the tap that follows decides, so
  // that tapping while they are up puts them away instead of bringing them straight back.
  wrap.addEventListener('touchstart', (event) => {
    if (event.target !== video) showControls();
  }, { passive: true });
  wrap.addEventListener('focusin', showControls);
  wrap.addEventListener('keydown', onKey);
  document.addEventListener('click', (event) => {
    if (!qualityMenu.hidden && !qualityMenu.contains(event.target) && !qualityButton.contains(event.target)) closeQualityMenu();
    if (!captionsMenu.hidden && !captionsMenu.contains(event.target) && !captionsButton.contains(event.target)) closeCaptionsMenu();
    if (!audioMenu.hidden && !audioMenu.contains(event.target) && !audioButton.contains(event.target)) closeAudioMenu();
  });

  // While dragging, show where the drop would land; seek on release.
  seekBar.addEventListener('input', () => {
    seeking = true;
    updateSeekFill(Number(seekBar.value));
    const max = Number(seekBar.max);
    behindLabel.textContent = vod
      ? `${formatClock(Number(seekBar.value))} / ${formatClock(max)}`
      : (max - Number(seekBar.value) > 1 ? `-${formatClock(max - Number(seekBar.value))}` : 'Live');
  });
  seekBar.addEventListener('change', () => {
    seeking = false;
    video.currentTime = Number(seekBar.value);
    video.play().catch(() => {});
  });

  // Keep the controls up while the pointer rests on them.
  for (const bar of wrap.querySelectorAll('.overlay-top, .overlay-bottom')) {
    bar.addEventListener('mouseenter', () => { pointerOnControls = true; showControls(); });
    bar.addEventListener('mouseleave', () => { pointerOnControls = false; showControls(); });
  }

  updatePlayState();
  updateVolumeState();
  applyCaptions();

  /**
   * A tap and a click mean different things.
   *
   * With a mouse, clicking the picture plays or pauses, as it does everywhere else on a
   * desktop. On a touch screen there is no pointer to move, so a tap is the only way to
   * call the controls up again or send them away; play and pause are the buttons' job.
   */
  function onVideoClick(event) {
    if (event.pointerType === 'touch' || (event.pointerType === undefined && !matchMedia('(hover: hover)').matches)) {
      wrap.classList.contains('controls-hidden') ? showControls() : hideControls();

      return;
    }

    // The movement before a click has already revealed the controls, so a click right
    // after that reveal is the viewer asking for them, not for a pause.
    if (Date.now() - controlsRevealedAt < 400) return;

    togglePlay();
  }

  function togglePlay() {
    if (video.paused) video.play().catch(() => {});
    else video.pause();
  }

  function updatePlayState() {
    const paused = video.paused;
    setIcon(playButton, paused ? 'play' : 'pause', paused ? 'Play' : 'Pause');
    bigPlayButton.hidden = !paused || (!session && !vod);
    showControls();
  }

  function toggleMute() {
    video.muted = !video.muted;
    if (!video.muted && video.volume === 0) video.volume = 0.5;
  }

  function onVolumeInput() {
    video.volume = Number(volumeSlider.value);
    video.muted = video.volume === 0;
  }

  function updateVolumeState() {
    const silent = video.muted || video.volume === 0;
    setIcon(muteButton, silent ? 'muted' : 'volume', silent ? 'Unmute' : 'Mute');
    volumeSlider.value = String(silent ? 0 : video.volume);
    // The filled part of the track is drawn by us, so it has to be told where to stop.
    // Input events reach here too: setting the volume fires volumechange.
    volumeSlider.style.setProperty('--level', String(silent ? 0 : video.volume));
  }

  function liveEdge() {
    if (hls && Number.isFinite(hls.liveSyncPosition)) return hls.liveSyncPosition;
    return video.seekable.length ? video.seekable.end(video.seekable.length - 1) : null;
  }

  function updateLiveState() {
    // A recording has a beginning and an end: show where you are, not how far behind.
    if (vod) {
      updateSeekBar(null);
      if (!seeking) behindLabel.textContent = `${formatClock(video.currentTime)} / ${formatClock(Number(seekBar.max))}`;

      return;
    }

    const edge = liveEdge();
    const behind = edge === null ? 0 : Math.max(0, edge - video.currentTime);
    const isBehind = behind > 8;
    liveButton.classList.toggle('behind', isBehind);
    liveButton.title = isBehind ? 'Jump to live' : 'Watching live';
    updateSeekBar(edge);
    if (!seeking) behindLabel.textContent = isBehind ? `-${formatClock(behind)} behind live` : '';
  }

  // The seek bar spans what the server still keeps (HLS_DVR_MINUTES), live at the right end.
  function updateSeekBar(edge) {
    const seekableEnd = video.seekable.length ? video.seekable.end(video.seekable.length - 1) : null;
    // A recording still being converted grows: its end is whatever is ready so far.
    const start = vod ? 0 : (video.seekable.length ? video.seekable.start(0) : null);
    const end = vod
      ? (Number.isFinite(video.duration) && video.duration > 0 ? video.duration : seekableEnd)
      : (edge ?? seekableEnd);
    const usable = start !== null && end !== null && end - start > 1;

    seekBar.disabled = !usable;
    if (!usable || seeking) return;

    seekBar.min = String(start);
    seekBar.max = String(end);
    seekBar.value = String(Math.min(end, Math.max(start, video.currentTime)));
    seekBar.title = vod ? 'Position in the recording' : `Rewind up to ${formatClock(end - start)}`;
    updateSeekFill(video.currentTime);
  }

  function updateSeekFill(position) {
    const min = Number(seekBar.min);
    const span = Number(seekBar.max) - min;
    const played = span > 0 ? ((position - min) / span) * 100 : 100;
    seekBar.style.setProperty('--played', `${Math.max(0, Math.min(100, played))}%`);
  }

  function seekBy(seconds) {
    if (seekBar.disabled) return;
    const target = Math.min(Number(seekBar.max), Math.max(Number(seekBar.min), video.currentTime + seconds));
    video.currentTime = target;
    updateLiveState();
  }

  function goLive() {
    if (vod) return;

    const edge = liveEdge();
    if (edge !== null) video.currentTime = edge;
    video.play().catch(() => {});
  }

  function captionTracks() {
    return Array.from(video.textTracks).filter((track) => track.kind === 'captions' || track.kind === 'subtitles');
  }

  /** The caption channel being drawn: the chosen one while it exists, else the first. */
  function selectedTrack() {
    const tracks = captionTracks();

    return tracks.find((track) => track.label === captionChoice) ?? tracks[0] ?? null;
  }

  // One click is enough when a programme carries a single caption channel; with more than
  // one, the button offers them instead of guessing.
  function onCaptionsClick() {
    if (captionTracks().length < 2) {
      toggleCaptions();

      return;
    }

    if (!captionsMenu.hidden) {
      closeCaptionsMenu();

      return;
    }

    renderCaptionsMenu();
    captionsMenu.hidden = false;
    captionsButton.setAttribute('aria-expanded', 'true');
    captionsMenu.querySelector('[aria-checked="true"]')?.focus();
    showControls();
  }

  function closeCaptionsMenu() {
    captionsMenu.hidden = true;
    captionsButton.setAttribute('aria-expanded', 'false');
    showControls();
  }

  function renderCaptionsMenu() {
    const chosen = selectedTrack();
    const options = [
      { label: 'Off', detail: 'no captions', track: null },
      ...captionTracks().map((track) => ({
        label: track.label || 'Captions',
        detail: track.language ? track.language.toUpperCase() : '',
        track,
      })),
    ];

    captionsMenu.replaceChildren(...options.map((option) => h('button', {
      type: 'button',
      role: 'menuitemradio',
      'aria-checked': String(option.track === null ? !captionsOn : captionsOn && option.track === chosen),
      onclick: () => chooseCaptions(option.track),
    }, h('span', {}, option.label), h('span', { class: 'overlay-menu-detail' }, option.detail))));
  }

  function chooseCaptions(track) {
    captionsOn = track !== null;

    if (track !== null) {
      captionChoice = track.label;
      saveSetting(CAPTION_TRACK_STORAGE_KEY, captionChoice);
    }

    saveSetting(CAPTIONS_STORAGE_KEY, captionsOn ? 'on' : 'off');
    closeCaptionsMenu();
    applyCaptions();
    wrap.focus();
  }

  function applyCaptions() {
    const tracks = captionTracks();
    const chosen = selectedTrack();
    captionsButton.disabled = tracks.length === 0;
    captionsButton.title = tracks.length === 0
      ? 'No captions in this program'
      : (captionsOn ? `Captions: ${chosen?.label ?? 'on'}` : 'Show captions');
    captionsButton.setAttribute('aria-pressed', String(captionsOn && tracks.length > 0));

    if (!captionsMenu.hidden) renderCaptionsMenu();

    // The browser keeps the cues but does not draw them ("hidden"): the overlay draws them
    // itself, so they can move above the controls and scale with the player.
    for (const track of tracks) {
      if (track.mode !== 'hidden') track.mode = 'hidden';
      if (!listenedTracks.has(track)) {
        track.addEventListener('cuechange', renderCaptions);
        listenedTracks.add(track);
      }
    }

    renderCaptions();
  }

  function renderCaptions() {
    const track = selectedTrack();
    const cues = captionsOn && track?.activeCues ? Array.from(track.activeCues) : [];

    // Caption rows, top to bottom (cue.line is the row number when the broadcast sets it).
    cues.sort((a, b) => (typeof a.line === 'number' && typeof b.line === 'number' ? a.line - b.line : a.startTime - b.startTime));

    const lines = cues.flatMap((cue) => cue.text.split('\n')).filter((line) => line.trim() !== '');
    captionLayer.replaceChildren(...lines.map((line) => h('span', {}, line)));
    captionLayer.hidden = lines.length === 0;
  }

  function toggleCaptions() {
    captionsOn = !captionsOn;
    saveSetting(CAPTIONS_STORAGE_KEY, captionsOn ? 'on' : 'off');
    applyCaptions();
  }

  // The server encodes several renditions (HLS_RENDITIONS); hls.js plays the best one the
  // connection keeps up with, unless the viewer picks one.
  function levelLabel(level) {
    return level?.height ? `${level.height}p` : '';
  }

  function updateQuality() {
    const levels = hls?.levels ?? [];
    qualityButton.hidden = levels.length < 2;
    if (qualityButton.hidden) {
      closeQualityMenu();
      return;
    }

    const playing = levelLabel(levels[hls.currentLevel]);
    qualityButton.textContent = qualityChoice === -1 ? `Auto${playing ? ` ${playing}` : ''}` : levelLabel(levels[qualityChoice]);
    qualityButton.title = qualityChoice === -1 ? 'Quality follows your connection' : 'Quality';
    if (!qualityMenu.hidden) renderQualityMenu();
  }

  function renderQualityMenu() {
    const levels = hls.levels
      .map((level, index) => ({ index, label: levelLabel(level), detail: `up to ${(level.bitrate / 1e6).toFixed(1)} Mbps`, height: level.height }))
      .sort((a, b) => b.height - a.height);
    const options = [{ index: -1, label: 'Auto', detail: 'follows your connection' }, ...levels];

    qualityMenu.replaceChildren(...options.map((option) => h('button', {
      type: 'button',
      role: 'menuitemradio',
      'aria-checked': String(option.index === qualityChoice),
      onclick: () => chooseQuality(option.index),
    }, h('span', {}, option.label), h('span', { class: 'overlay-menu-detail' }, option.detail))));
  }

  // A broadcast may carry a second language on its own audio track; hls.js offers them as
  // audio tracks once the master playlist names them.
  function audioLabel(track, index = 0) {
    if (LANGUAGE_NAMES[track?.lang]) return LANGUAGE_NAMES[track.lang];
    if (track?.lang) return track.lang.toUpperCase();

    // No language at all: say which track it is rather than the name ffmpeg gave it.
    return `Track ${index + 1}`;
  }

  function updateAudio() {
    const tracks = hls?.audioTracks ?? [];
    audioButton.hidden = tracks.length < 2;

    if (audioButton.hidden) {
      closeAudioMenu();

      return;
    }

    const index = tracks[hls.audioTrack] ? hls.audioTrack : tracks.findIndex((track) => track.default);
    audioButton.textContent = audioLabel(tracks[index], index);
    audioButton.title = `Audio: ${audioLabel(tracks[index], index)}`;

    if (!audioMenu.hidden) renderAudioMenu();
  }

  /** Follow the language chosen last time, when this programme carries it. */
  function applyPreferredAudio() {
    const tracks = hls?.audioTracks ?? [];
    const preferred = tracks.findIndex((track) => track.lang === audioChoice);

    if (preferred !== -1 && preferred !== hls.audioTrack) {
      hls.audioTrack = preferred;
    }

    updateAudio();
  }

  function renderAudioMenu() {
    audioMenu.replaceChildren(...hls.audioTracks.map((track, index) => h('button', {
      type: 'button',
      role: 'menuitemradio',
      'aria-checked': String(index === hls.audioTrack),
      onclick: () => chooseAudio(index),
    }, h('span', {}, audioLabel(track, index)), h('span', { class: 'overlay-menu-detail' }, audioDetail(track, index)))));
  }

  /**
   * What the track was before the server converted it, which the playlist cannot say:
   * everything is sent as stereo, so its own channel count is the same for all of them.
   */
  function audioDetail(track, index) {
    const described = (audioSources[index] ?? {}).channels ?? null;

    if (described !== null) return described === '2.0' ? 'Stereo' : described;

    // Nothing known about it: better to say nothing than to show a track number.
    return track.lang ? '' : (track.name ?? '');
  }

  function toggleAudioMenu() {
    if (!audioMenu.hidden) {
      closeAudioMenu();

      return;
    }

    renderAudioMenu();
    audioMenu.hidden = false;
    audioButton.setAttribute('aria-expanded', 'true');
    audioMenu.querySelector('[aria-checked="true"]')?.focus();
    showControls();
  }

  function closeAudioMenu() {
    audioMenu.hidden = true;
    audioButton.setAttribute('aria-expanded', 'false');
    showControls();
  }

  function chooseAudio(index) {
    const track = hls.audioTracks[index];

    // Remembered by language, not by position: the second track is not the same language
    // on every channel.
    audioChoice = track?.lang ?? null;

    if (audioChoice !== null) saveSetting(AUDIO_STORAGE_KEY, audioChoice);

    hls.audioTrack = index;
    closeAudioMenu();
    updateAudio();
    wrap.focus();
  }

  function toggleQualityMenu() {
    if (!qualityMenu.hidden) {
      closeQualityMenu();
      return;
    }

    renderQualityMenu();
    qualityMenu.hidden = false;
    qualityButton.setAttribute('aria-expanded', 'true');
    qualityMenu.querySelector('[aria-checked="true"]')?.focus();
    showControls();
  }

  function closeQualityMenu() {
    qualityMenu.hidden = true;
    qualityButton.setAttribute('aria-expanded', 'false');
    showControls();
  }

  function chooseQuality(index) {
    qualityChoice = index;
    saveSetting(QUALITY_STORAGE_KEY, index === -1 ? 'auto' : String(hls.levels[index].height));
    // Switches from the next segment on, so what is already buffered keeps playing.
    hls.nextLevel = index;
    closeQualityMenu();
    updateQuality();
    wrap.focus();
  }

  function toggleFullscreen() {
    if (document.fullscreenElement) {
      document.exitFullscreen().catch(() => {});
    } else if (wrap.requestFullscreen) {
      wrap.requestFullscreen().catch(() => {});
    } else if (video.webkitEnterFullscreen) {
      video.webkitEnterFullscreen(); // iPhone: native full screen only
    }
  }

  function updateFullscreenState() {
    const isFullscreen = document.fullscreenElement === wrap;
    setIcon(fullscreenButton, isFullscreen ? 'exitFullscreen' : 'fullscreen', isFullscreen ? 'Exit full screen' : 'Full screen');
  }

  // Controls fade out while playing and come back on any interaction.
  function showControls() {
    if (wrap.classList.contains('controls-hidden')) controlsRevealedAt = Date.now();
    wrap.classList.remove('controls-hidden');
    clearTimeout(hideTimer);
    if (!video.paused && !pointerOnControls && qualityMenu.hidden) hideTimer = setTimeout(() => wrap.classList.add('controls-hidden'), 3000);
  }

  /** Send the controls away now, rather than waiting for them to fade. */
  function hideControls() {
    if (!qualityMenu.hidden || !captionsMenu.hidden || !audioMenu.hidden) return;

    clearTimeout(hideTimer);
    wrap.classList.add('controls-hidden');
  }

  function onKey(event) {
    if (event.target instanceof HTMLInputElement) return;
    if (!qualityMenu.hidden) {
      if (event.key === 'Escape') {
        closeQualityMenu();
        qualityButton.focus();
      }
      // Leave Enter, space and Tab to the menu's buttons.
      if (qualityMenu.contains(event.target)) return;
    }

    if (!captionsMenu.hidden) {
      if (event.key === 'Escape') {
        closeCaptionsMenu();
        captionsButton.focus();
      }

      if (captionsMenu.contains(event.target)) return;
    }

    if (!audioMenu.hidden) {
      if (event.key === 'Escape') {
        closeAudioMenu();
        audioButton.focus();
      }

      if (audioMenu.contains(event.target)) return;
    }
    const actions = {
      ' ': togglePlay,
      k: togglePlay,
      m: toggleMute,
      c: toggleCaptions,
      f: toggleFullscreen,
      arrowleft: () => seekBy(-10),
      arrowright: () => seekBy(10),
    };
    const action = actions[event.key.toLowerCase()];
    if (!action) return;
    event.preventDefault();
    if (event.key.toLowerCase() !== 'c' || !captionsButton.disabled) action();
    showControls();
  }

  // What the channel is airing now, from the stored guide; refreshed as programs change.
  async function loadProgramInfo() {
    clearTimeout(infoTimer);
    if (!current) return;

    const { host, program } = current;
    const now = Math.floor(Date.now() / 1000);

    try {
      const guide = await api(`/api/guide?${new URLSearchParams({ device: host, from: String(now), hours: '2' })}`);
      if (current?.program !== program) return;

      const channel = guide.channels.find((candidate) => candidate.program === program.number
        && (!program.virtualChannel || candidate.virtual === program.virtualChannel));
      const event = channel?.events.find((candidate) => candidate.start <= now && now < candidate.start + candidate.duration);
      const next = channel?.events.find((candidate) => candidate.start >= now && candidate !== event);

      airing = channel && event ? { channel, event } : null;
      updateRecordButton();

      if (event) {
        const end = event.start + event.duration;
        programLabel.replaceChildren(...[
          h('span', { class: 'overlay-title' }, event.title),
          h('span', {}, `${timeFormat.format(event.start * 1000)}–${timeFormat.format(end * 1000)}`),
          event.rating && h('span', { class: 'overlay-rating' }, event.rating),
          next && h('span', { class: 'overlay-next' }, `Next: ${next.title}`),
        ].filter(Boolean));
        programProgress.hidden = false;
        programProgress.firstChild.style.width = `${Math.min(100, ((now - event.start) / event.duration) * 100)}%`;
        infoTimer = setTimeout(loadProgramInfo, 30000);
        return;
      }

      programLabel.textContent = 'No program information';
    } catch {
      programLabel.textContent = '';
      airing = null;
      updateRecordButton();
    }

    programProgress.hidden = true;
    infoTimer = setTimeout(loadProgramInfo, 300000);
  }

  /**
   * Say what the player is doing, under the spinner. Nothing is said once it is simply
   * playing: the LIVE indicator already covers that, and words over the picture are in
   * the way.
   */
  function setStatus(text, isError = false) {
    const worthSaying = ['Live', 'Playing'].includes(text) ? '' : text;

    status.textContent = worthSaying;
    status.className = `overlay-status${isError ? ' error' : ''}`;
    status.hidden = worthSaying === '';
  }

  /**
   * The recording of the program on screen, when there is one. The Recordings tab keeps
   * that list current; this only asks it what it already knows.
   */
  function airingRecording() {
    if (vod || airing === null) return null;

    const scheduled = state.recordingsView?.scheduleFor(airing.channel, airing.event) ?? null;

    return scheduled === null ? null : state.recordingsView?.recordingFor(scheduled.id) ?? null;
  }

  /**
   * Make the button say what pressing it will do. It pulses while the program on screen is
   * being recorded, which is also the only place to stop that recording from the player.
   */
  function updateRecordButton() {
    const recording = airingRecording();
    const stopping = recording !== null && recording.stopRequested !== null;

    recordButton.classList.toggle('is-recording', recording !== null);
    recordButton.disabled = stopping;
    setIcon(recordButton, 'record', recording === null ? 'Record this program'
      : stopping ? 'Stopping…' : `Stop recording ${recording.title}`);
  }

  /**
   * Record what this channel is showing now, or stop it when it is already being recorded.
   * The recorder takes a tuner of its own, so watching carries on either way; a channel
   * with no guide data has nothing to schedule.
   */
  async function recordCurrentProgram() {
    if (vod) return;

    if (current === null || airing === null) {
      showError('No program information for this channel yet.');

      return;
    }

    const recording = airingRecording();
    const { channel, event } = airing;
    recordButton.disabled = true;

    try {
      if (recording !== null) {
        await api(`/api/recordings/${recording.id}/stop`, { method: 'POST' });
        setStatus(`Stopped recording ${event.title}`);
      } else {
        await api('/api/recordings', {
          method: 'POST',
          body: JSON.stringify({
            device: current.host,
            physical: channel.physical,
            program: channel.program,
            virtual: channel.virtual,
            channelName: channel.name,
            eventId: event.eventId,
            start: event.start,
            duration: event.duration,
            title: event.title,
            description: event.description ?? null,
          }),
        });

        setStatus(`Recording ${event.title}`);
      }

      setTimeout(() => setStatus(vod ? 'Playing' : 'Live'), 4000);
      await state.recordingsView?.load();
    } catch (error) {
      showError(error);
    } finally {
      updateRecordButton();
    }
  }

  async function play({ base, host, tunerIndex, program }) {
    await stop();

    current = { host, tunerIndex, program };
    liveButton.hidden = false;
    recordButton.hidden = false;
    panel.hidden = false;
    channelLabel.replaceChildren(...[
      program.virtualChannel ? channelLogo(program.virtualChannel) : null,
      h('span', {}, `${program.virtualChannel || `#${program.number}`} ${program.name} · Tuner ${tunerIndex}`),
    ].filter(Boolean));
    programLabel.textContent = '';
    spinner.hidden = false;
    setStatus('Starting the transcoder…');
    loadProgramInfo();
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    try {
      session = await api(`${base}/stream`, {
        method: 'POST',
        body: JSON.stringify({ program: program.number, viewer: VIEWER_ID }),
      });
    } catch (error) {
      spinner.hidden = true;
      setStatus(error.message, true);
      return;
    }

    refreshTuners();
    poll();
  }

  // Polling the session doubles as the heartbeat that keeps the transcoder running.
  async function poll() {
    const current = session;
    if (!current) return;

    try {
      const state = await api(`/api/streams/${current.id}?viewer=${VIEWER_ID}`);
      if (session !== current) return;

      if (!state.alive) {
        detach();
        session = null;
        spinner.hidden = true;
        setStatus(`Playback stopped: ${state.error}`, true);
        return;
      }

      audioSources = state.audio ?? [];

      if (state.ready && !hls && !video.getAttribute('src')) {
        attach(state.playlist);
      } else if (!state.ready) {
        setStatus('Starting the transcoder…');
      }

      applyCaptions();
    } catch (error) {
      if (session !== current) return;
      detach();
      session = null;
      spinner.hidden = true;
      setStatus(error.message, true);
      return;
    }

    timer = setTimeout(poll, hls || video.getAttribute('src') ? 5000 : 1000);
  }

  /**
   * Watch a recording. An mp4 plays as it is; a ts recording is converted on the server,
   * and playback starts as soon as the first segments are ready.
   */
  async function playFile({ recordingId, kind, playlist, url, captions, title, subtitle, virtual }) {
    await stop();

    vod = { recordingId, kind };
    panel.hidden = false;
    // A recording has no live edge, no "what is on now", and nothing to record.
    liveButton.hidden = true;
    recordButton.hidden = true;
    programProgress.hidden = true;
    channelLabel.replaceChildren(...[
      virtual ? channelLogo(virtual) : null,
      h('span', {}, subtitle ?? ''),
    ].filter(Boolean));
    programLabel.replaceChildren(h('span', { class: 'overlay-title' }, title ?? 'Recording'));
    spinner.hidden = false;
    setStatus(kind === 'file' ? 'Buffering…' : 'Converting…');
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    if (kind === 'file') {
      attach(url, 'file');

      // A browser reads captions out of a playlist by itself, but not out of a plain file:
      // the converter writes them alongside, and they go on as a track.
      if (captions) {
        video.append(h('track', { kind: 'captions', src: captions, srclang: 'en', label: 'Captions', default: '' }));
      }

      return;
    }

    // Wait for the converter to write its first segments: attaching to a playlist that
    // does not exist yet leaves the player stuck on a 404 it never retries.
    pollRecording();
  }

  // Polling keeps the converter alive the same way the live heartbeat does; the server
  // stops it a minute after the last viewer stops asking.
  async function pollRecording() {
    const watching = vod;
    if (!watching || watching.kind !== 'hls') return;

    try {
      const state = await api(`/api/recordings/${watching.recordingId}/play?viewer=${VIEWER_ID}`);
      if (vod !== watching) return;

      if (state.error) {
        detach();
        vod = null;
        spinner.hidden = true;
        setStatus(state.error, true);

        return;
      }

      audioSources = state.audio ?? [];

      if (state.ready && !hls && !video.getAttribute('src')) {
        attach(state.playlist);
      } else if (!state.ready) {
        setStatus('Converting…');
      }

      applyCaptions();
    } catch (error) {
      if (vod !== watching) return;
      detach();
      vod = null;
      spinner.hidden = true;
      setStatus(error.message, true);

      return;
    }

    timer = setTimeout(pollRecording, hls || video.getAttribute('src') ? 10000 : 1000);
  }

  function attach(source, kind = 'hls') {
    setStatus('Buffering…');

    if (kind === 'file') {
      // An mp4 the browser decodes itself. hls.js would attach a MediaSource and try to
      // read the file as a playlist, which fails silently: no media, no error, no end to
      // the spinner. Quality and audio menus belong to hls.js, so they stay hidden.
      video.src = source;
      qualityButton.hidden = true;
      audioButton.hidden = true;
      video.play().catch(() => { /* autoplay blocked; the controls still work */ });

      clearInterval(liveTimer);
      liveTimer = setInterval(updateLiveState, 1000);

      return;
    }

    if (window.Hls && Hls.isSupported()) {
      hls = new Hls({
        liveSyncDurationCount: 3,
        // Keep what was watched so rewinding does not always download it again.
        backBufferLength: 180,
        // Broadcast captions (CEA-608) ride inside the H.264 video; show them as a text track.
        enableCEA708Captions: true,
        // A broadcast may caption in more than one language: CC1 carries the programme's
        // own and CC3 a second, usually Spanish here. Naming all four lets the viewer pick
        // rather than being given whichever channel happened to arrive first.
        captionsTextTrack1Label: 'CC1',
        captionsTextTrack1LanguageCode: 'en',
        captionsTextTrack2Label: 'CC2',
        captionsTextTrack2LanguageCode: 'en',
        captionsTextTrack3Label: 'CC3',
        captionsTextTrack3LanguageCode: 'es',
        captionsTextTrack4Label: 'CC4',
        captionsTextTrack4LanguageCode: 'es',
        // No point downloading more pixels than the player shows (full screen lifts the cap).
        capLevelToPlayerSize: true,
        // Start at the rendition a quick bandwidth test allows rather than the tallest, which
        // stalls a slow mobile connection before it can switch down.
        startLevel: -1,
      });
      hls.on(Hls.Events.MANIFEST_PARSED, () => {
        const saved = loadSetting(QUALITY_STORAGE_KEY);
        qualityChoice = hls.levels.findIndex((level) => String(level.height) === saved);
        if (qualityChoice !== -1) hls.currentLevel = qualityChoice;
        updateQuality();
      });
      hls.on(Hls.Events.LEVEL_SWITCHED, updateQuality);
      hls.on(Hls.Events.AUDIO_TRACKS_UPDATED, applyPreferredAudio);
      hls.on(Hls.Events.AUDIO_TRACK_SWITCHED, updateAudio);
      hls.on(Hls.Events.ERROR, (_event, data) => {
        if (!data.fatal) return;
        if (data.type === Hls.ErrorTypes.NETWORK_ERROR) hls.startLoad();
        else if (data.type === Hls.ErrorTypes.MEDIA_ERROR) hls.recoverMediaError();
        else setStatus(`Player error: ${data.details}`, true);
      });
      hls.loadSource(source);
      hls.attachMedia(video);
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
      video.src = source;
    } else {
      setStatus('This browser cannot play HLS video', true);
      return;
    }

    video.play().catch(() => { /* autoplay blocked; the controls still work */ });

    // timeupdate stops while paused, but the live edge keeps moving away.
    clearInterval(liveTimer);
    liveTimer = setInterval(updateLiveState, 1000);
  }

  function detach() {
    clearInterval(liveTimer);
    captionLayer.replaceChildren();
    captionLayer.hidden = true;
    if (hls) {
      hls.destroy();
      hls = null;
    }
    qualityChoice = -1;
    updateQuality();
    audioSources = [];
    audioButton.hidden = true;
    closeAudioMenu();
    // A track left behind would follow the next recording onto the screen.
    for (const track of [...video.querySelectorAll('track')]) {
      track.remove();
    }

    if (video.getAttribute('src')) {
      video.removeAttribute('src');
      video.load();
    }
  }

  async function stop() {
    clearTimeout(timer);
    clearTimeout(infoTimer);
    detach();

    if (document.fullscreenElement === wrap) document.exitFullscreen().catch(() => {});

    const ending = session;
    const endingRecording = vod;
    session = null;
    vod = null;
    current = null;
    airing = null;
    liveButton.hidden = false;
    recordButton.hidden = false;
    panel.hidden = true;
    spinner.hidden = true;
    bigPlayButton.hidden = true;

    if (ending) {
      try {
        await api(`/api/streams/${ending.id}?viewer=${VIEWER_ID}`, { method: 'DELETE' });
      } catch { /* the server reaps abandoned streams anyway */ }
      refreshTuners();
    }

    if (endingRecording?.kind === 'hls') {
      try {
        await api(`/api/recordings/${endingRecording.recordingId}/play?viewer=${VIEWER_ID}`, { method: 'DELETE' });
      } catch { /* the converter stops on its own once nobody asks for it */ }
    }
  }

  function leaveOnUnload() {
    // Recordings need no beacon: their converter is reaped once the polling stops.
    if (session) navigator.sendBeacon(`/api/streams/${session.id}/leave?viewer=${VIEWER_ID}`);
  }

  return { play, playFile, stop, leaveOnUnload, recordingsChanged: updateRecordButton };
}

// ---------------------------------------------------------------------------
// Program guide

function createGuideView(device, player) {
  const host = device.host;
  let followNow = true;
  let from = null;
  let data = null;
  let selected = null;
  let timer = null;
  let loading = false;
  let pendingUntil = 0;

  const rangeLabel = h('span', { class: 'guide-range' });
  const status = h('span', { class: 'guide-status muted' });
  const scanButton = h('button', { type: 'button', class: 'secondary', onclick: () => startJob('scan') }, 'Scan channels');
  const updateButton = h('button', { type: 'button', onclick: () => startJob('collect') }, 'Update guide');
  const notice = h('div', { class: 'guide-notice', hidden: true });
  const grid = h('div', { class: 'guide-grid' });
  // A modal keeps the details in view: with 20+ channels they would otherwise open below
  // the grid, out of sight until the page is scrolled all the way down.
  const details = h('dialog', {
    class: 'guide-details card',
    onclose: () => { if (selected) { selected = null; render(); } },
    // A click on the dialog element itself landed on the backdrop, not on its contents.
    onclick: (event) => { if (event.target === details) details.close(); },
  });

  const root = h('div', { class: 'view guide', hidden: true },
    h('div', { class: 'guide-toolbar' },
      h('div', { class: 'guide-nav' },
        h('button', { type: 'button', class: 'secondary', 'aria-label': 'Earlier', onclick: () => shift(-GUIDE_WINDOW_HOURS / 2) }, '◀'),
        h('button', { type: 'button', class: 'secondary', onclick: () => { followNow = true; from = null; load(); } }, 'Now'),
        h('button', { type: 'button', class: 'secondary', 'aria-label': 'Later', onclick: () => shift(GUIDE_WINDOW_HOURS / 2) }, '▶'),
        rangeLabel,
      ),
      h('div', { class: 'guide-actions' }, status, scanButton, updateButton),
    ),
    notice,
    h('div', { class: 'guide-scroll card' }, grid),
  );

  function shift(hours) {
    followNow = false;
    from = (data?.from ?? Math.floor(Date.now() / 1000)) + hours * 3600;
    load();
  }

  async function load() {
    if (loading) return;
    loading = true;

    try {
      const query = new URLSearchParams({ device: host, hours: String(GUIDE_WINDOW_HOURS) });
      if (!followNow && from !== null) query.set('from', String(from));
      data = await api(`/api/guide?${query}`);
      render();
    } catch (error) {
      notice.hidden = false;
      notice.replaceChildren(error.message);
    } finally {
      loading = false;
      schedule();
    }
  }

  // Refresh often while a scan or update runs, otherwise once a minute to move "now".
  function schedule() {
    clearTimeout(timer);
    if (root.hidden || !root.isConnected) return;
    timer = setTimeout(load, data?.running || Date.now() < pendingUntil ? 2000 : 60000);
  }

  async function startJob(command) {
    scanButton.disabled = true;
    updateButton.disabled = true;
    try {
      await api(`/api/guide/${command}`, { method: 'POST', body: JSON.stringify({ device: host }) });
      // Poll quickly for a while so the job's progress shows up even if it starts slowly.
      pendingUntil = Date.now() + 15000;
    } catch (error) {
      showError(error);
    }
    load();
  }

  function render() {
    const { from: start, to: end, now, channels, dataRange, running, runs } = data;
    const span = end - start;

    rangeLabel.textContent = `${dayFormat.format(start * 1000)}, ${timeFormat.format(start * 1000)} – ${timeFormat.format(end * 1000)}`;
    scanButton.disabled = running;
    updateButton.disabled = running;

    const lastRun = runs[0];
    const lastCollect = runs.find((run) => run.kind === 'collect' && run.finishedAt !== null);
    const gaveNothing = (run) => (run.channels ?? 0) === 0 && (run.events ?? 0) === 0;

    status.title = '';

    if (running) {
      status.textContent = lastRun?.kind === 'scan' ? 'Scanning channels…' : 'Updating the guide…';
    } else if (lastRun?.error && lastRun.finishedAt !== null && gaveNothing(lastRun)) {
      status.textContent = `Last ${lastRun.kind} failed: ${lastRun.error}`;
      status.title = lastRun.error;
    } else if (lastCollect) {
      // A run that read most channels is not a failure: weak channels come and go, and
      // saying so in one line beats a wall of stream errors.
      const missed = (lastCollect.error ?? '').split(';').filter((part) => part.trim() !== '').length;

      status.textContent = `Updated ${timeAgo(lastCollect.finishedAt)} · ${lastCollect.events} events`
        + (missed === 0 ? '' : ` · ${missed} channel${missed === 1 ? '' : 's'} could not be read`);
      status.title = lastCollect.error ?? '';
    } else {
      status.textContent = '';
    }

    const eventsInWindow = channels.some((channel) => channel.events.length > 0);
    notice.hidden = true;

    if (channels.length === 0) {
      notice.hidden = false;
      notice.replaceChildren(running
        ? 'Scanning for channels…'
        : 'No channels yet. Scan to find the channels this device receives, then update the guide.');
    } else if (dataRange.first === null) {
      notice.hidden = false;
      notice.replaceChildren(running
        ? 'Reading the guide from the broadcast (about 10 seconds per channel)…'
        : 'No guide data yet. Update the guide to read it from the broadcast (about 10 seconds per channel).');
    } else if (!eventsInWindow) {
      notice.hidden = false;
      notice.replaceChildren(
        `No guide data for this time. Stored guide data covers ${dateTimeFormat.format(dataRange.first * 1000)} – ${dateTimeFormat.format(dataRange.last * 1000)}.`,
        h('button', { type: 'button', class: 'secondary', onclick: () => {
          followNow = false;
          from = Math.floor(dataRange.first / 1800) * 1800;
          load();
        } }, 'Show that time'),
      );
    }

    const ticks = [];
    for (let tick = Math.ceil(start / 1800) * 1800; tick < end; tick += 1800) {
      ticks.push(h('div', { class: 'guide-tick', style: `left:${((tick - start) / span) * 100}%` }, timeFormat.format(tick * 1000)));
    }

    const nowMarker = () => now >= start && now < end
      ? h('div', { class: 'guide-now', style: `left:${((now - start) / span) * 100}%` })
      : null;

    grid.replaceChildren(
      h('div', { class: 'guide-row guide-header' }, h('div', { class: 'guide-channel' }), h('div', { class: 'guide-track' }, ticks, nowMarker())),
      ...channels.map((channel) => h('div', { class: 'guide-row' },
        h('div', { class: 'guide-channel', title: `${channel.virtual} ${channel.name}` },
          channelLogo(channel.virtual, channel.logo),
          h('span', { class: 'guide-channel-name' },
            h('b', {}, channel.virtual), ' ', channel.name,
            channel.hd && h('span', { class: 'badge hd' }, 'HD')),
        ),
        h('div', { class: 'guide-track' },
          channel.events.length === 0 && h('div', { class: 'guide-empty' }, 'No guide data'),
          channel.events.map((event) => {
            const left = Math.max(0, (event.start - start) / span) * 100;
            const right = Math.min(1, (event.start + event.duration - start) / span) * 100;
            const onNow = now >= event.start && now < event.start + event.duration;
            const isSelected = selected && selected.channel.id === channel.id && selected.event.eventId === event.eventId && selected.event.start === event.start;

            return h('button', {
              type: 'button',
              class: `guide-event${onNow ? ' now' : ''}${isSelected ? ' selected' : ''}`,
              style: `left:${left}%;width:${Math.max(0, right - left)}%`,
              title: `${event.title} · ${timeFormat.format(event.start * 1000)}–${timeFormat.format((event.start + event.duration) * 1000)}`,
              onclick: () => { selected = { channel, event }; render(); },
            },
              h('span', { class: 'title' }, event.title),
              h('span', { class: 'time' }, `${timeFormat.format(event.start * 1000)} · ${formatDuration(event.duration)}`),
            );
          }),
          nowMarker(),
        ),
      )),
    );

    renderDetails();
  }

  function renderDetails() {
    if (!selected) {
      details.close();
      return;
    }

    const { channel, event } = selected;
    const start = event.start * 1000;
    const end = (event.start + event.duration) * 1000;
    const onNow = data.now * 1000 >= start && data.now * 1000 < end;

    const scheduled = state.recordingsView?.scheduleFor(channel, event) ?? null;
    const recording = scheduled === null ? null : state.recordingsView?.recordingFor(scheduled.id) ?? null;

    // Filtered because replaceChildren writes a null out as the word "null", where h()
    // quietly drops it: the progress line below is only there while it is recording.
    details.replaceChildren(...[
      h('div', { class: 'guide-details-head' },
        channelLogo(channel.virtual, channel.logo),
        h('h3', {}, event.title),
        event.rating && h('span', { class: 'badge' }, event.rating),
        h('button', { type: 'button', class: 'secondary', 'aria-label': 'Close details', onclick: () => details.close() }, '×'),
      ),
      h('p', { class: 'muted' },
        `${channel.virtual} ${channel.name} · ${dayFormat.format(start)}, ${timeFormat.format(start)}–${timeFormat.format(end)} · ${formatDuration(event.duration)} `,
        channel.hd && h('span', { class: 'badge hd' }, 'HD'),
        onNow && h('span', { class: 'badge locked' }, 'on now')),
      // The picture and what the programme is about, side by side. h() drops a falsy
      // child, so with no picture the description simply has the row to itself.
      h('div', { class: 'programme-detail' },
        programmeArtwork(event.title, event.art),
        event.description ? h('p', {}, event.description) : h('p', { class: 'muted' }, 'No description.'),
      ),
      h('div', { class: 'controls' },
        h('button', {
          type: 'button',
          class: 'watch',
          disabled: channel.encrypted,
          onclick: (clickEvent) => watch(channel, clickEvent.currentTarget),
        }, `▶ Watch ${channel.virtual} ${channel.name}`),
        recordButton(channel, event, onNow, scheduled, recording),
        !onNow && h('span', { class: 'muted' }, 'Watching shows what the channel is broadcasting right now.'),
      ),
      recording && h('p', { class: 'muted' }, recordingProgressText(recording)),
    ].filter(Boolean));

    if (!details.open) {
      // Outside the view's own tree: a hidden tab would otherwise leave an open modal
      // invisible, with the rest of the page inert behind it.
      document.body.append(details);
      details.showModal();
    }
  }

  // Stop what is recording, cancel what is only scheduled, otherwise offer to record.
  function recordButton(channel, event, onNow, scheduled, recording) {
    if (recording !== null) {
      return h('button', {
        type: 'button',
        class: 'secondary record is-recording',
        disabled: recording.stopRequested !== null,
        onclick: (clickEvent) => recordingAction(`/api/recordings/${recording.id}/stop`, { method: 'POST' }, clickEvent.currentTarget, 'Stopping…'),
      }, recording.stopRequested === null ? '■ Stop recording' : 'Stopping…');
    }

    if (scheduled !== null) {
      return h('button', {
        type: 'button',
        class: 'secondary record',
        onclick: (clickEvent) => recordingAction(`/api/recordings/schedules/${scheduled.id}`, { method: 'DELETE' }, clickEvent.currentTarget, 'Cancelling…'),
      }, '✓ Scheduled · cancel');
    }

    // A standing rule for this title covers every showing, so it is offered instead of a
    // second one, and cancelling it is what the button then does.
    const rule = state.recordingsView?.ruleFor(channel, event.title) ?? null;

    if (rule !== null) {
      return h('button', {
        type: 'button',
        class: 'secondary record is-recording',
        onclick: (clickEvent) => recordingAction(`/api/recordings/rules/${rule.id}`, { method: 'DELETE' }, clickEvent.currentTarget, 'Cancelling…'),
      }, '✓ Recording every episode · stop');
    }

    const formats = state.recordingsView?.formats() ?? [];
    const chosen = h('select', { class: 'record-format', 'aria-label': 'What to keep' },
      formats.map((format) => h('option', {
        value: format,
        selected: format === (state.recordingsView?.defaultFormat() ?? 'ts'),
      }, FORMAT_LABELS[format] ?? format)));

    return h('span', { class: 'record-choice' },
      h('button', {
        type: 'button',
        class: 'secondary record',
        disabled: channel.encrypted,
        onclick: (clickEvent) => record(channel, event, clickEvent.currentTarget, chosen.value),
      }, onNow ? '● Record the rest' : '● Record'),
      h('button', {
        type: 'button',
        class: 'secondary record',
        disabled: channel.encrypted,
        title: 'Record this whenever it is on this channel',
        onclick: (clickEvent) => recordSeries(channel, event, clickEvent.currentTarget, chosen.value),
      }, '● All episodes'),
      formats.length > 1 && chosen,
    );
  }

  /**
   * Keep recording a title on a channel. The broadcast guide only reaches half a day
   * ahead, so the rule is kept and applied to each guide update rather than scheduling
   * anything far in advance.
   */
  async function recordSeries(channel, event, button, format) {
    await recordingAction('/api/recordings/rules', {
      method: 'POST',
      body: JSON.stringify({
        device: host,
        physical: channel.physical,
        program: channel.program,
        virtual: channel.virtual,
        channelName: channel.name,
        title: event.title,
        format,
        // The hours in a rule mean the hours where the person setting it lives, and keep
        // meaning that after the clocks change.
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
      }),
    }, button, 'Scheduling…');
  }

  function recordingProgressText(recording) {
    const total = Math.max(1, recording.stopsAt - recording.startedAt);
    const elapsed = Math.max(0, Math.min(total, Math.floor(Date.now() / 1000) - recording.startedAt));

    return `Recording · ${formatClock(elapsed)} of ${formatClock(total)} · ${formatBytes(recording.bytes)} · until ${timeFormat.format(recording.stopsAt * 1000)}`;
  }

  // The recorder picks and reserves a tuner when the program starts, so scheduling one
  // that is on now works the same as scheduling tomorrow's.
  async function record(channel, event, button, format) {
    await recordingAction('/api/recordings', {
      method: 'POST',
      body: JSON.stringify({
        device: host,
        physical: channel.physical,
        program: channel.program,
        virtual: channel.virtual,
        channelName: channel.name,
        eventId: event.eventId,
        start: event.start,
        duration: event.duration,
        title: event.title,
        description: event.description ?? null,
        format,
      }),
    }, button, 'Scheduling…');
  }

  // Every recording action ends the same way: reload the recordings, then redraw so the
  // button reflects what is now true.
  async function recordingAction(path, options, button, busyLabel) {
    const label = button.textContent;
    button.disabled = true;
    button.textContent = busyLabel;

    try {
      await api(path, options);
      await state.recordingsView?.load();
      render();
    } catch (error) {
      showError(error);
      button.disabled = false;
      button.textContent = label;
    }
  }

  // Use a tuner already on the channel, or else an idle one.
  async function watch(channel, button) {
    const label = button.textContent;
    button.disabled = true;
    button.textContent = 'Finding a tuner…';

    try {
      const tuners = (await Promise.all(Array.from({ length: device.tunerCount }, (_, index) =>
        api(`/api/devices/${encodeURIComponent(host)}/tuners/${index}`).then((status) => ({ index, status })).catch(() => null))))
        .filter(Boolean);

      // A tuner a recording holds is off limits; another one can watch the same channel.
      // A tuner merely left on a channel is free: watching retunes it anyway.
      const free = tuners.filter(({ status }) => !status.reservedBy);
      const pick = free.find(({ status }) => status.locked && status.physicalChannel === channel.physical)
        ?? free.find(({ status }) => status.target === 'none' && (status.lockOwner ?? 'none') === 'none');

      if (!pick) {
        const busy = tuners.find(({ status }) => status.reservedBy);

        throw new Error(busy
          ? `No free tuner: ${busy.status.reservedBy} is using tuner ${busy.index}.`
          : 'Every tuner is busy. Stop a tuner or the current playback first.');
      }

      const base = `/api/devices/${encodeURIComponent(host)}/tuners/${pick.index}`;

      if (!(pick.status.locked && pick.status.physicalChannel === channel.physical)) {
        const tuned = await api(`${base}/channel`, { method: 'PUT', body: JSON.stringify({ channel: `auto:${channel.physical}` }) });
        if (!tuned.locked) throw new Error(`No signal on channel ${channel.physical} right now.`);
      }

      refreshTuners();
      // The player sits behind the modal, which also makes it inert.
      details.close();
      await player.play({ base, host, tunerIndex: pick.index, program: { number: channel.program, virtualChannel: channel.virtual, name: channel.name } });
    } catch (error) {
      showError(error);
    } finally {
      button.disabled = channel.encrypted;
      button.textContent = label;
    }
  }

  return {
    root,
    load,
    destroy() {
      clearTimeout(timer);
      details.close();
      details.remove();
    },
  };
}

// ---------------------------------------------------------------------------
// Recordings

function createRecordingsView(device, player) {
  const host = device.host;
  let data = null;
  let timer = null;
  let loading = false;
  let updated = null;

  const folder = h('span', { class: 'guide-status muted' });
  const notice = h('div', { class: 'guide-notice', hidden: true });
  const scheduled = h('div', { class: 'recordings' });
  const recorded = h('div', { class: 'recordings' });
  const series = h('div', { class: 'recordings' });
  const seriesCard = h('section', { class: 'card', hidden: true },
    h('h3', {}, 'Series'),
    series,
  );

  const root = h('div', { class: 'view', hidden: true },
    h('section', { class: 'card' },
      h('div', { class: 'guide-toolbar' }, h('h3', {}, 'Scheduled'), folder),
      notice,
      scheduled,
    ),
    seriesCard,
    h('section', { class: 'card' },
      h('h3', {}, 'Recorded'),
      recorded,
    ),
  );

  async function load() {
    if (loading) return;
    loading = true;

    try {
      data = await api(`/api/recordings?device=${encodeURIComponent(host)}`);
      render();
      updated?.({ recording: running().length });
    } catch (error) {
      notice.hidden = false;
      notice.replaceChildren(error.message);
    } finally {
      loading = false;
      schedule();
    }
  }

  // Follow a recording closely while it runs, so its size and outcome stay current. This
  // keeps going from another tab, more slowly, so the tab's recording dot stays honest.
  function schedule() {
    clearTimeout(timer);
    if (!root.isConnected) return;
    const busy = running().length > 0;
    const visible = !root.hidden;
    timer = setTimeout(load, busy ? (visible ? 5000 : 15000) : (visible ? 30000 : 60000));
  }

  function running() {
    return (data?.recordings ?? []).filter((recording) => recording.status === 'recording');
  }

  function render() {
    folder.textContent = data.directory
      ? `Saving to ${data.directory}${data.freeBytes ? ` · ${formatBytes(data.freeBytes)} free` : ''}`
      : '';

    // Recordings are large and nothing is ever deleted for you: say so before the drive
    // fills, since a recording that cannot start is only noticed afterwards.
    const low = data.freeBytes !== null && data.freeBytes < LOW_SPACE_BYTES;

    notice.hidden = !low;

    if (low) {
      notice.replaceChildren(`Only ${formatBytes(data.freeBytes)} left where recordings are written. `
        + 'An hour of a channel takes about 1 to 4 GB, and recording stops working below 2 GB. '
        + 'Nothing is deleted automatically, so make room yourself.');
    }

    // What has already been recorded belongs in the list below, not here.
    const pending = data.schedules.filter((schedule) => !['done', 'cancelled'].includes(schedule.status));

    scheduled.replaceChildren(...(pending.length === 0
      ? [h('p', { class: 'muted' }, 'Nothing scheduled. Pick a program in the Guide and choose Record.')]
      : pending.map((schedule) => row({
        when: schedule.start,
        title: schedule.title,
        subtitle: `${schedule.virtual} ${schedule.channelName} · ${formatDuration(schedule.duration)}`,
        error: schedule.error,
        status: schedule.status,
        actions: [schedule.status === 'recording'
          ? h('button', {
            type: 'button',
            class: 'secondary',
            onclick: (clickEvent) => stopRecordingFor(schedule, clickEvent.currentTarget),
          }, 'Stop')
          : h('button', {
            type: 'button',
            class: 'secondary',
            onclick: (clickEvent) => act(`/api/recordings/schedules/${schedule.id}`, { method: 'DELETE' }, clickEvent.currentTarget, 'Cancelling…'),
          }, 'Cancel')],
      }))));

    // A rule has no time of its own: it stands until cancelled, so it gets a plainer row
    // than a schedule or a recording.
    const rules = data.rules ?? [];
    seriesCard.hidden = rules.length === 0;

    series.replaceChildren(...rules.map((rule) => h('div', { class: 'recording' },
      h('span', { class: 'what' },
        h('span', { class: 'title' }, rule.title),
        h('span', { class: 'muted series-when' },
          `${rule.virtual} ${rule.channelName} · every showing`
          + (rule.days ? ` · ${describeDays(rule.days)}` : '')
          + (rule.earliest !== null && rule.latest !== null ? ` · ${clockOf(rule.earliest)}–${clockOf(rule.latest)}` : '')),
      ),
      h('span', { class: 'actions' },
        h('button', {
          type: 'button',
          class: 'secondary',
          onclick: (clickEvent) => act(`/api/recordings/rules/${rule.id}`, { method: 'DELETE' }, clickEvent.currentTarget, 'Cancelling…'),
        }, 'Cancel'),
      ),
    )));

    recorded.replaceChildren(...(data.recordings.length === 0
      ? [h('p', { class: 'muted' }, 'Nothing recorded yet.')]
      : data.recordings.map((recording) => row({
        when: recording.startedAt,
        art: recordingArt(recording),
        title: [recording.title, ...recordingBadges(recording)],
        subtitle: `${recording.virtual} ${recording.channelName} · ${formatBytes(recording.bytes)}${describeCopy(recording)}`,
        progress: recording.status === 'recording' ? progressFor(recording) : null,
        error: recording.error,
        // The recorder finishes a stop on its next pass, a few seconds later.
        status: recording.status === 'recording' && recording.stopRequested ? 'stopping' : recording.status,
        actions: recording.status === 'recording'
          ? [h('button', {
            type: 'button',
            class: 'secondary',
            onclick: (clickEvent) => act(`/api/recordings/${recording.id}/stop`, { method: 'POST' }, clickEvent.currentTarget, 'Stopping…'),
          }, 'Stop')]
          : [
            recording.bytes > 0 && h('button', {
              type: 'button',
              class: 'watch',
              onclick: (clickEvent) => playRecording(recording, clickEvent.currentTarget),
            }, '▶ Play'),
            recording.bytes > 0 && h('button', {
              type: 'button',
              class: 'secondary',
              title: 'Save it to this device',
              // Content-Disposition makes the browser save it, so the page stays put.
              onclick: () => { window.location.href = `/recordings/${recording.id}/file?download=1`; },
            }, '⤓ Download'),
            convertControl(recording),
            h('button', {
              type: 'button',
              class: 'secondary',
              onclick: (clickEvent) => remove(recording, clickEvent.currentTarget),
            }, 'Delete'),
          ],
      }))));
  }

  /**
   * Offer to convert a recording kept as broadcast. An hour of it takes about 2.8 GB;
   * converted it is roughly 1.5 GB at the same picture size, or 0.6 GB at 720p. Nothing is
   * deleted: the broadcast stays until it is removed by hand.
   */
  function convertControl(recording) {
    if (recording.format === 'mp4' || recording.convertedPath) return null;

    if (recording.convertPid) {
      return h('span', { class: 'muted' }, 'Converting…');
    }

    if (recording.convertError) {
      return h('span', { class: 'record-choice' },
        h('span', { class: 'muted', title: recording.convertError }, 'Conversion failed'),
        h('button', {
          type: 'button',
          class: 'secondary',
          onclick: (clickEvent) => convert(recording, null, clickEvent.currentTarget),
        }, 'Try again'),
      );
    }

    if (recording.convertRequested) {
      return h('span', { class: 'muted' }, 'Queued to convert');
    }

    // Choosing is the action: one control rather than a menu and a button beside it.
    const choice = h('select', {
      class: 'record-format',
      'aria-label': 'Convert this recording',
      title: 'Make a smaller copy that browsers can play',
      onchange: () => convert(recording, choice.value === '720' ? 720 : null, choice),
    },
      h('option', { value: '' }, 'Convert…'),
      h('option', { value: 'original' }, 'Original size'),
      h('option', { value: '720' }, '720p'),
    );

    return choice;
  }

  async function convert(recording, height, control) {
    if (control.value === '') return;

    control.disabled = true;

    try {
      await api(`/api/recordings/${recording.id}/convert`, {
        method: 'POST',
        body: JSON.stringify(height === null ? {} : { height }),
      });
      await load();
    } catch (error) {
      showError(error);
      control.disabled = false;
      control.value = '';
    }
  }

  /**
   * What a recording is, at a glance: the picture it came from and what can play it.
   */
  function recordingBadges(recording) {
    const badges = [];

    if (recording.hd) badges.push(h('span', { class: 'badge hd tag-hd' }, 'HD'));
    if (recording.format !== 'mp4') badges.push(h('span', { class: 'badge hd tag-ts' }, 'TS'));
    if (recording.format === 'mp4' || recording.convertedPath) badges.push(h('span', { class: 'badge hd tag-mp4' }, 'MP4'));

    return badges;
  }

  function clockOf(minutes) {
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
  }

  function describeDays(days) {
    const names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    return String(days).split(',').map((day) => names[Number(day) - 1] ?? day).join(' ');
  }

  // The list keeps a column for pictures whether or not a given recording has one, so the
  // titles stay in a straight line. A picture that fails to load hides for the same reason,
  // rather than removing itself and dragging the row across.
  function recordingArt(recording) {
    if (!recording.title || !recording.art) return h('span', { class: 'art' });

    const art = h('img', {
      class: 'art',
      src: `/artwork?title=${encodeURIComponent(recording.title)}`,
      alt: '',
      loading: 'lazy',
    });

    art.addEventListener('error', () => { art.style.visibility = 'hidden'; });

    return art;
  }

  function row({ when, art, title, subtitle, progress, error, status, actions }) {
    return h('div', { class: art ? 'recording with-art' : 'recording' },
      h('span', { class: 'when muted' }, `${dayFormat.format(when * 1000)}, ${timeFormat.format(when * 1000)}`),
      art,
      h('span', { class: 'what' },
        h('span', { class: 'title' }, title),
        h('span', { class: 'muted' }, subtitle),
        progress,
        error && h('span', { class: 'muted' }, error),
      ),
      h('span', { class: 'actions' },
        // Only while something is happening: a finished recording says so by offering to
        // play, download or delete it.
        status && status !== 'done' ? h('span', { class: `badge${status === 'recording' ? ' locked' : ''}` }, status) : null,
        ...actions,
      ),
    );
  }

  // A ts recording holds the broadcast as it was sent, which no browser can decode, so the
  // server converts it while you watch; an mp4 plays straight from the file.
  async function playRecording(recording, button) {
    const label = button.textContent;
    button.disabled = true;
    button.textContent = 'Starting…';

    try {
      const playback = await api(`/api/recordings/${recording.id}/play`, {
        method: 'POST',
        body: JSON.stringify({ viewer: VIEWER_ID }),
      });

      await player.playFile(playback);
    } catch (error) {
      showError(error);
    } finally {
      button.disabled = false;
      button.textContent = label;
    }
  }

  // What exists besides the broadcast itself: a browser-ready copy, one being made, or
  // the reason there is none.
  function describeCopy(recording) {
    if (recording.format === 'mp4') return ' · MP4';
    if (recording.convertedBytes) return ` · MP4 ${formatBytes(recording.convertedBytes)}`;
    if (recording.convertPid) return ' · making an MP4 copy…';
    if (recording.convertError) return ` · no MP4 copy: ${recording.convertError}`;

    return '';
  }

  // How far through its window a recording is, which is what "how much longer" means
  // here: the file grows until the program's end plus its padding.
  function progressFor(recording) {
    const total = Math.max(1, recording.stopsAt - recording.startedAt);
    const elapsed = Math.max(0, Math.min(total, Math.floor(Date.now() / 1000) - recording.startedAt));

    return h('span', { class: 'recording-progress' },
      h('span', { class: 'bar' }, h('span', { style: `width:${Math.round((elapsed / total) * 100)}%` })),
      h('span', { class: 'muted' }, `${formatClock(elapsed)} of ${formatClock(total)} · until ${timeFormat.format(recording.stopsAt * 1000)}`),
    );
  }

  // Stopping keeps what has been recorded so far; cancelling is for one that never
  // started. Both used to be called "Stop", which lost people a recording they meant
  // to keep.
  function stopRecordingFor(schedule, button) {
    const running = (data?.recordings ?? []).find((recording) => recording.scheduleId === schedule.id
      && recording.status === 'recording');

    if (running === undefined) {
      act(`/api/recordings/schedules/${schedule.id}`, { method: 'DELETE' }, button, 'Cancelling…');

      return;
    }

    act(`/api/recordings/${running.id}/stop`, { method: 'POST' }, button, 'Stopping…');
  }

  function remove(recording, button) {
    if (!confirm(`Delete "${recording.title}" and its file?`)) {
      return;
    }

    act(`/api/recordings/${recording.id}`, { method: 'DELETE' }, button, 'Deleting…');
  }

  async function act(path, options, button, busyLabel) {
    const label = button.textContent;
    button.disabled = true;
    button.textContent = busyLabel;

    try {
      await api(path, options);
      await load();
    } catch (error) {
      showError(error);
      button.disabled = false;
      button.textContent = label;
    }
  }

  return {
    root,
    load,

    /** Called after every refresh with what is going on, for the tab's recording dot. */
    onUpdate(callback) {
      updated = callback;
    },

    /** What the server can write, and what it writes unless told otherwise. */
    formats() {
      return data?.formats ?? [];
    },

    defaultFormat() {
      return data?.defaultFormat ?? 'ts';
    },

    /** The standing rule covering a title on a channel, if there is one. */
    ruleFor(channel, title) {
      return (data?.rules ?? []).find((rule) => rule.physical === channel.physical
        && rule.program === channel.program
        && rule.title.toLowerCase() === String(title).toLowerCase()) ?? null;
    },

    /** The schedule covering one guide showing, so the guide can offer to cancel or stop it. */
    scheduleFor(channel, event) {
      return (data?.schedules ?? []).find((schedule) => schedule.physical === channel.physical
        && schedule.program === channel.program
        && schedule.start === event.start) ?? null;
    },

    recordingFor(scheduleId) {
      return running().find((recording) => recording.scheduleId === scheduleId) ?? null;
    },

    destroy() {
      clearTimeout(timer);
    },
  };
}

// ---------------------------------------------------------------------------
// Logs

/**
 * What the services wrote down. Only logs the server offers can be asked for, by id, so
 * the page never names a path.
 */
function createLogsView() {
  let sources = [];
  let chosen = loadSetting(LOG_SOURCE_STORAGE_KEY);
  let timer = null;
  let loading = false;

  const picker = h('select', { class: 'log-source', 'aria-label': 'Which log', onchange: () => {
    chosen = picker.value;
    saveSetting(LOG_SOURCE_STORAGE_KEY, chosen);
    load();
  } });

  const follow = h('input', { type: 'checkbox', checked: true, onchange: () => { if (follow.checked) toBottom(); } });
  const detail = h('span', { class: 'muted log-detail' });
  const pane = h('pre', { class: 'log-pane', tabindex: '0' });

  const root = h('div', { class: 'view logs', hidden: true },
    h('div', { class: 'log-toolbar' },
      picker,
      h('button', { type: 'button', class: 'secondary', onclick: () => load() }, 'Refresh'),
      h('label', { class: 'log-follow' }, follow, 'Follow'),
      detail,
    ),
    h('div', { class: 'card log-card' }, pane),
  );

  function toBottom() {
    pane.scrollTop = pane.scrollHeight;
  }

  async function load() {
    if (loading) return;
    loading = true;

    try {
      const listing = await api('/api/logs');
      sources = listing.logs ?? [];

      if (sources.length === 0) {
        picker.replaceChildren();
        pane.textContent = 'Nothing has written a log yet.';
        detail.textContent = '';

        return;
      }

      // Rebuilding the list would lose the open dropdown, so only do it when it changed.
      const wanted = sources.map((source) => `${source.id}:${source.name}`).join('|');

      if (picker.dataset.signature !== wanted) {
        picker.dataset.signature = wanted;
        picker.replaceChildren(...groups(sources).map(([group, rows]) =>
          h('optgroup', { label: group }, rows.map((source) =>
            h('option', { value: source.id }, source.name)))));
      }

      if (!sources.some((source) => source.id === chosen)) chosen = sources[0].id;
      picker.value = chosen;

      const log = await api(`/api/logs/${encodeURIComponent(chosen)}?lines=500`);
      const atBottom = follow.checked;

      pane.textContent = log.lines.length === 0 ? '(empty)' : log.lines.join('\n');
      detail.textContent = `${formatBytes(log.bytes)} · written ${timeAgo(log.modifiedAt)}`;

      if (atBottom) toBottom();
    } catch (error) {
      pane.textContent = error.message;
      detail.textContent = '';
    } finally {
      loading = false;
      schedule();
    }
  }

  function groups(rows) {
    const byGroup = new Map();

    for (const row of rows) {
      if (!byGroup.has(row.group)) byGroup.set(row.group, []);
      byGroup.get(row.group).push(row);
    }

    return [...byGroup.entries()];
  }

  // Only while the tab is open: a log nobody is looking at is not worth fetching.
  function schedule() {
    clearTimeout(timer);
    if (!root.isConnected || root.hidden) return;
    timer = setTimeout(load, 5000);
  }

  return { root, load };
}

// ---------------------------------------------------------------------------
// Analysis

async function runAnalysis(panel, base, tunerIndex, status) {
  if (panel.dataset.running === 'true') return;

  panel.dataset.running = 'true';
  panel.hidden = false;
  refreshTuners();

  const started = Date.now();
  const elapsed = h('span', { class: 'muted' }, '0s');
  const timer = setInterval(() => { elapsed.textContent = `${Math.round((Date.now() - started) / 1000)}s`; }, 500);

  panel.replaceChildren(
    h('div', { class: 'analysis-head' }, h('h3', {}, `Analysis · Tuner ${tunerIndex} · ${status?.channel ?? ''}`)),
    h('p', {}, h('span', { class: 'spinner' }),
      'Reading the live stream until the program and guide tables are complete (up to 15 seconds)… ', elapsed),
  );
  panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

  try {
    renderAnalysis(panel, tunerIndex, await api(`${base}/analysis`));
  } catch (error) {
    panel.replaceChildren(
      h('div', { class: 'analysis-head' }, h('h3', {}, `Analysis · Tuner ${tunerIndex}`)),
      h('p', { class: 'notice' }, error.message),
    );
  } finally {
    clearInterval(timer);
    panel.dataset.running = 'false';
    refreshTuners();
  }
}

function renderAnalysis(panel, tunerIndex, result) {
  const report = result.report;

  const facts = [
    ['Channel', result.channel],
    ['TSID', report.transportStreamId === null ? '—' : `${report.transportStreamId} (${hex(report.transportStreamId)})`],
    ['Broadcast time', report.systemTime ? dateTimeFormat.format(new Date(report.systemTime.utc)) : '—'],
    ['Read', `${(result.bytes / 1e6).toFixed(1)} MB in ${result.elapsedSeconds}s`],
  ];

  panel.replaceChildren(...[
    h('div', { class: 'analysis-head' },
      h('h3', {}, `Analysis · Tuner ${tunerIndex}`),
      h('div', { class: 'facts' }, facts.map(([label, value]) => h('span', {}, `${label} `, h('b', {}, value)))),
    ),
    !result.complete && h('p', { class: 'notice' },
      'Time ran out before every table arrived; some guide data or stream details may be missing.'),
    report.singleProgram && h('p', { class: 'notice' },
      'This stream carries a single program without PSIP tables, so there is no channel or guide data.'),
    renderChannelsTable(report),
    renderStreamsTable(report),
    renderGuide(report),
    renderGuideTables(report),
  ].filter(Boolean));
}

function renderChannelsTable(report) {
  if (report.channels.length === 0) return null;

  return h('div', {},
    h('h4', {}, `Channels (${report.channels.length})`),
    h('div', { class: 'table-wrap' }, h('table', {},
      h('thead', {}, h('tr', {}, ['Channel', 'Name', 'Service', 'Program', 'Source', 'Streams'].map((label) => h('th', {}, label)))),
      h('tbody', {}, report.channels.map((channel) => h('tr', {},
        h('td', {}, h('b', {}, channel.channel), channel.hidden && h('span', { class: 'badge' }, 'hidden')),
        h('td', {}, channel.longName && channel.longName !== channel.name ? `${channel.name} (${channel.longName})` : channel.name),
        h('td', {}, channel.serviceType),
        h('td', {}, channel.programNumber),
        h('td', { class: 'mono' }, hex(channel.sourceId)),
        h('td', {}, channel.streams.map((stream) => h('div', { class: 'mono' },
          `${hex(stream.pid)} ${stream.typeName}${stream.language ? ` (${stream.language})` : ''}`))),
      ))),
    )),
  );
}

function renderStreamsTable(report) {
  if (report.programs.length === 0) return null;

  const rows = report.programs.flatMap((program) => [
    h('tr', { class: 'program-row' },
      h('td', { colspan: 4 }, `Program ${program.number}`,
        h('span', { class: 'muted mono' }, `  PMT ${hex(program.pmtPid)}${program.pcrPid !== null ? ` · PCR ${hex(program.pcrPid)}` : ''}`),
        !program.captured && h('span', { class: 'badge' }, 'PMT not captured'))),
    ...program.streams.map((stream) => h('tr', {},
      h('td', { class: 'mono' }, hex(stream.pid)),
      h('td', {}, stream.typeName),
      h('td', {}, stream.bitstream ?? h('span', { class: 'muted' }, 'not sampled')),
      h('td', { class: 'muted' }, [stream.descriptor, stream.captions && `Captions: ${stream.captions}`].filter(Boolean).join(' · ')),
    )),
  ]);

  return h('div', {},
    h('h4', {}, 'Programs and streams'),
    h('div', { class: 'table-wrap' }, h('table', {},
      h('thead', {}, h('tr', {}, ['PID', 'Type', 'Bitstream', 'Signaled'].map((label) => h('th', {}, label)))),
      h('tbody', {}, rows),
    )),
  );
}

function renderGuide(report) {
  const channels = report.channels.filter((channel) => channel.events.length > 0);
  if (channels.length === 0) return null;

  const now = Date.now();
  const lastEnd = Math.max(...channels.flatMap((channel) => channel.events.map((event) =>
    Date.parse(event.start) + event.durationSeconds * 1000)));

  return h('div', {},
    h('h4', {}, 'Guide'),
    lastEnd < now && h('p', { class: 'notice' },
      `This schedule ended ${dateTimeFormat.format(new Date(lastEnd))}; the stream is probably a recording.`),
    channels.map((channel, position) => h('details', { open: position === 0 },
      h('summary', {}, `${channel.channel} ${channel.name} `, h('span', { class: 'muted' }, `(${channel.events.length} events)`)),
      h('ul', { class: 'events' }, channel.events.map((event) => {
        const start = new Date(event.start);
        const end = new Date(start.getTime() + event.durationSeconds * 1000);
        const onNow = start.getTime() <= now && now < end.getTime();

        return h('li', { class: `event${onNow ? ' now' : ''}` },
          h('div', { class: 'when' }, dayFormat.format(start), h('br'),
            `${timeFormat.format(start)}–${timeFormat.format(end)}`),
          h('div', {},
            h('div', { class: 'title' }, event.title, ' ',
              h('span', { class: 'muted' }, formatDuration(event.durationSeconds)),
              event.rating && h('span', { class: 'badge' }, event.rating)),
            event.description && h('div', { class: 'desc' }, event.description)),
        );
      })),
    )),
  );
}

function renderGuideTables(report) {
  if (report.guideTables.length === 0) return null;

  return h('details', {},
    h('summary', {}, `Master Guide Table (${report.guideTables.length} entries)`),
    h('div', { class: 'table-wrap' }, h('table', {},
      h('thead', {}, h('tr', {}, ['Table', 'PID', 'Version', 'Bytes'].map((label) => h('th', {}, label)))),
      h('tbody', {}, report.guideTables.map((table) => h('tr', {},
        h('td', {}, table.type),
        h('td', { class: 'mono' }, hex(table.pid)),
        h('td', {}, table.version),
        h('td', {}, table.bytes.toLocaleString()),
      ))),
    )),
  );
}

// ---------------------------------------------------------------------------
// Startup

// Rescan asks the server to search the network, then reloads what it knows. On Docker
// Desktop broadcast discovery never leaves the container, so the server asks each address.
document.getElementById('rescan').addEventListener('click', async (event) => {
  const button = event.currentTarget;
  const label = button.textContent;

  button.disabled = true;
  button.textContent = 'Scanning…';

  try {
    await api('/api/devices/scan', { method: 'POST' });
  } catch (error) {
    showError(error);
  }

  button.disabled = false;
  button.textContent = label;
  loadDevices();
});
window.addEventListener('pagehide', () => state.player?.leaveOnUnload());

document.getElementById('add-device').addEventListener('submit', async (event) => {
  event.preventDefault();
  const input = document.getElementById('add-device-ip');
  const ip = input.value.trim();

  try {
    await api('/api/devices', { method: 'POST', body: JSON.stringify({ host: ip }) });
  } catch (error) {
    // The server could not keep it (no guide database, or it refused the address); this
    // browser still can, which is how it worked before.
    showError(error);

    if (!state.manualHosts.includes(ip)) {
      state.manualHosts.push(ip);
      saveManualHosts();
    }
  }

  state.selectedHost = ip;
  input.value = '';
  loadDevices();
});

loadDevices();
