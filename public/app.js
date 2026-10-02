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
const RADIO_STATIONS_STORAGE_KEY = 'skywave.radioStations';
const RADIO_FREQUENCY_STORAGE_KEY = 'skywave.radioFrequency';
/** The radio's place in the device list. Not an address, so it can never be mistaken for one. */
const RADIO_HOST = 'radio';
const GUIDE_WINDOW_HOURS = 4;

// Identifies this tab to the server while it watches a stream. crypto.randomUUID()
// needs HTTPS, which a LAN install usually does not have.
const VIEWER_ID = Array.from(crypto.getRandomValues(new Uint8Array(12)), (byte) => byte.toString(16).padStart(2, '0')).join('');

const state = {
  devices: [],
  // What /api/radio said: whether there is a dongle, what it is, and what it is playing.
  radio: null,
  selectedHost: null,
  manualHosts: loadManualHosts(),
  channelMaps: new Map(),   // name -> Promise<[{number, frequency}]>
  tunerCards: [],
  pollTimer: null,
  player: null,
  guideView: null,
  recordingsView: null,
  radioView: null,
};

const TAB_LABELS = { tuners: 'Tuners', guide: 'Guide', recordings: 'Recordings', logs: 'Logs' };
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

/**
 * An empty box the size of a logo. In a list the box has to be there whether or not the
 * station has a picture, or the rows without one start 40px left of the rest.
 */
function logoPlaceholder() {
  return h('span', { class: 'channel-logo channel-logo-none', 'aria-hidden': 'true' });
}

function channelLogo(virtual, exists = true, reserveSpace = false) {
  // Six channels here have no logo and never will; without this they were asked for
  // hundreds of times a day, every one a 404.
  if (!exists) return reserveSpace ? logoPlaceholder() : null;

  const logo = h('img', {
    class: 'channel-logo',
    src: `/logos/${encodeURIComponent(virtual)}.png`,
    alt: '',
    loading: 'lazy',
  });

  // A logo that is on disk but will not decode would otherwise take the row's alignment
  // with it, so in a list it leaves the box behind.
  logo.addEventListener('error', () => {
    if (reserveSpace) {
      logo.replaceWith(logoPlaceholder());
    } else {
      logo.remove();
    }
  });

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

  // The radio is asked about on its own: it is not an HDHomeRun, and a server without one
  // simply says so. Failing to ask must not cost the page its tuners.
  try {
    state.radio = await api('/api/radio');
  } catch {
    state.radio = null;
  }

  renderDeviceList();

  const selected = state.devices.find((device) => device.host === state.selectedHost && !device.error);
  const firstUsable = state.devices.find((device) => !device.error);
  const radio = Boolean(state.radio?.enabled);

  if (selected) selectDevice(selected.host);
  else if (radio && state.selectedHost === RADIO_HOST) selectRadio();
  else if (firstUsable) selectDevice(firstUsable.host);
  else if (radio) selectRadio();
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

  const radio = state.radio?.enabled ? state.radio : null;

  if (state.devices.length === 0 && radio === null) {
    list.replaceChildren(h('li', { class: 'muted' }, 'No devices found. Add one by IP address.'));
    return;
  }

  // Last in the list, and never removable from the page: where the dongle is belongs to
  // the server's settings, not to a browser.
  const radioItem = radio && h('li', { class: 'device-item' },
    h('button', {
      type: 'button',
      'aria-current': state.selectedHost === RADIO_HOST ? 'true' : 'false',
      onclick: () => selectRadio(),
    },
      h('span', { class: 'name' }, 'HD Radio'),
      radio.error
        ? h('span', { class: 'error' }, radio.error)
        : h('span', { class: 'meta' }, [radio.label, radio.device?.tuner].filter(Boolean).join(' · ')),
    ),
  );

  list.replaceChildren(...[...state.devices.map((device) => h('li', { class: 'device-item' },
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
  )), radioItem].filter(Boolean));
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
  state.radioView?.destroy();
  state.radioView = null;
  document.getElementById('device-detail').replaceChildren(h('p', { class: 'muted' }, 'Select a device.'));
}

function selectDevice(host) {
  const device = state.devices.find((candidate) => candidate.host === host);
  if (!device || device.error) return;

  state.selectedHost = host;
  renderDeviceList();

  state.radioView?.destroy();
  state.radioView = null;

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
  play: 'M7 5v14l11-7z',
  pause: 'M6 5h4v14H6zM14 5h4v14h-4z',
  volume: 'M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0 0 14 8v8a4.5 4.5 0 0 0 2.5-4zM14 3.2v2.1a7 7 0 0 1 0 13.4v2.1a9 9 0 0 0 0-17.6z',
  muted: 'M3 9v6h4l5 5V4L7 9H3zm15.1 3 2.7-2.7-1.4-1.4-2.7 2.7-2.7-2.7-1.4 1.4 2.7 2.7-2.7 2.7 1.4 1.4 2.7-2.7 2.7 2.7 1.4-1.4z',
  captions: 'M19 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm-8 7H9.5v-.5h-2v3h2V13H11v1a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-4a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1zm7 0h-1.5v-.5h-2v3h2V13H18v1a1 1 0 0 1-1 1h-3a1 1 0 0 1-1-1v-4a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1z',
  record: 'M12 6a6 6 0 1 0 0 12a6 6 0 1 0 0-12z',
  stop: 'M6 6h12v12H6z',
  back: 'M11.75 18V6l-8.5 6 8.5 6zm.5-6 8.5 6V6l-8.5 6z',
  forward: 'M12.25 6v12l8.5-6L12.25 6zM3.25 18l8.5-6L3.25 6v12z',
  settings: 'M19.4 13a7.8 7.8 0 0 0 0-2l2.1-1.6a.5.5 0 0 0 .1-.6l-2-3.5a.5.5 0 0 0-.6-.2l-2.5 1a7.3 7.3 0 0 0-1.7-1l-.4-2.6a.5.5 0 0 0-.5-.4h-4a.5.5 0 0 0-.5.4l-.4 2.6a7.3 7.3 0 0 0-1.7 1l-2.5-1a.5.5 0 0 0-.6.2l-2 3.5a.5.5 0 0 0 .1.6L4.6 11a7.8 7.8 0 0 0 0 2l-2.1 1.6a.5.5 0 0 0-.1.6l2 3.5a.5.5 0 0 0 .6.2l2.5-1a7.3 7.3 0 0 0 1.7 1l.4 2.6a.5.5 0 0 0 .5.4h4a.5.5 0 0 0 .5-.4l.4-2.6a7.3 7.3 0 0 0 1.7-1l2.5 1a.5.5 0 0 0 .6-.2l2-3.5a.5.5 0 0 0-.1-.6zM12 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7z',
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

/**
 * A jump of a fixed number of seconds, with the number on it.
 *
 * Two arrows on their own read as variable-speed rewind, and on a touch screen there is no
 * hovering to find out, so the seconds are drawn next to them rather than only announced.
 */
function skipButton(name, seconds, onclick) {
  const label = seconds < 0 ? `Back ${-seconds} seconds` : `Forward ${seconds} seconds`;

  return h('button', {
    type: 'button',
    class: 'overlay-icon overlay-skip',
    title: label,
    'aria-label': label,
    onclick,
  }, icon(name), h('span', { 'aria-hidden': 'true' }, String(Math.abs(seconds))));
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
  // A radio station being listened to: sound only, and the station describes itself.
  let radio = null;
  // A view that draws its own transport registers here; see onPlaybackChange.
  let playbackListener = null;
  let hls = null;
  let timer = null;
  let current = null;
  let infoTimer = null;
  let hideTimer = null;
  let liveTimer = null;
  let pointerOnControls = false;
  let lastTouchAt = 0;
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
  // Radio has no picture of its own, so what the station sent stands in for one: the cover
  // of what is playing, or the station's logo.
  const artLayer = h('img', { class: 'overlay-art', alt: '', hidden: true });
  artLayer.addEventListener('error', () => { artLayer.hidden = true; });
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
  // Play and the two jumps are in the middle of the picture rather than the row: that is
  // where a thumb lands, and the row has only so much space on a phone.
  const backButton = skipButton('back', -10, () => seekBy(-10));
  const forwardButton = skipButton('forward', 30, () => seekBy(30));
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
  // On a phone the row cannot hold three named controls beside the buttons, so they move
  // behind a cog. The markup is the same at every width: a wide screen shows the panel inline
  // and hides the cog, a narrow one hides the panel until the cog is pressed.
  const settingsPanel = h('div', { class: 'overlay-settings-panel' }, qualityButton, audioButton, captionsButton);
  const settingsButton = iconButton('settings', 'Playback settings', toggleSettings, 'overlay-cog');
  settingsButton.setAttribute('aria-haspopup', 'true');
  settingsButton.setAttribute('aria-expanded', 'false');
  // The menus hang off this box rather than off their own buttons: the buttons sit together
  // at the right, so one anchor serves all three, and it keeps them out of the panel, which
  // on a narrow screen is itself positioned and pushed them up past the top of the player.
  const settings = h('span', { class: 'overlay-settings' },
    settingsPanel, qualityMenu, audioMenu, captionsMenu, settingsButton);

  const recordButton = iconButton('record', 'Record this program', recordCurrentProgram, 'overlay-record');
  const fullscreenButton = iconButton('fullscreen', 'Full screen', toggleFullscreen);

  // The centre of the picture is where a thumb lands. The same three controls as the row,
  // big enough to hit without looking.
  const centre = h('div', { class: 'overlay-centre' }, backButton, bigPlayButton, forwardButton);

  const wrap = h('div', { class: 'video-wrap', tabindex: '0' },
    video,
    artLayer,
    captionLayer,
    spinner,
    status,
    centre,
    h('div', { class: 'overlay-top' },
      h('div', { class: 'overlay-info' }, channelLabel, programLabel, programProgress),
      liveButton,
    ),
    h('div', { class: 'overlay-bottom' },
      h('div', { class: 'overlay-seek' }, seekBar),
      h('div', { class: 'overlay-controls' },
        stopButton, muteButton, volumeSlider, behindLabel,
        h('span', { class: 'overlay-spacer' }),
        settings,
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

  // A touch reveals nothing by itself; the tap that follows decides, so tapping while the
  // controls are up puts them away instead of bringing them straight back.
  //
  // A phone invents mouse events for a tap -- mouseover and mousemove arrive before the
  // click, on Android as on iOS.
  // Those were revealing the controls in the middle of the tap, which made the click land on
  // a button that had been invisible when the finger went down: a tap over stop ended
  // playback, and a tap on the picture revealed and then hid again, looking like nothing.
  // So a mouse event just after a touch is ignored.
  wrap.addEventListener('touchstart', () => { lastTouchAt = Date.now(); }, { passive: true });
  wrap.addEventListener('mousemove', () => {
    if (Date.now() - lastTouchAt > 700) showControls();
  });
  // Belt and braces for anything that still reveals mid-tap: a press that brought the
  // controls up does not also work the control it landed on, because there was nothing
  // there to press when the finger went down.
  wrap.addEventListener('click', (event) => {
    if (event.target !== video && Date.now() - controlsRevealedAt < 500) {
      event.stopPropagation();
      event.preventDefault();
    }
  }, true);
  wrap.addEventListener('focusin', showControls);
  wrap.addEventListener('keydown', onKey);
  document.addEventListener('click', (event) => {
    if (!qualityMenu.hidden && !qualityMenu.contains(event.target) && !qualityButton.contains(event.target)) closeQualityMenu();
    if (!captionsMenu.hidden && !captionsMenu.contains(event.target) && !captionsButton.contains(event.target)) closeCaptionsMenu();
    if (!audioMenu.hidden && !audioMenu.contains(event.target) && !audioButton.contains(event.target)) closeAudioMenu();
    if (!settings.contains(event.target)) closeSettings();
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

  /**
   * Lets a view draw its own transport without owning the media element.
   *
   * The radio view puts play, pause and volume beside what is playing rather than over a
   * picture that is not there, so it needs to hear what this element is doing. One listener
   * is enough: only one view is on screen at a time.
   */
  function onPlaybackChange(listener) {
    playbackListener = listener;
  }

  function playbackState() {
    return {
      paused: video.paused,
      volume: video.muted ? 0 : video.volume,
      waiting: !spinner.hidden,
      status: status.hidden ? null : status.textContent,
      failed: status.classList.contains('error'),
    };
  }

  function setVolume(level) {
    video.volume = Math.min(1, Math.max(0, level));
    video.muted = video.volume === 0;
  }

  function updatePlayState() {
    const paused = video.paused;
    setIcon(bigPlayButton, paused ? 'play' : 'pause', paused ? 'Play' : 'Pause');
    // Nothing to play yet means nothing to press.
    centre.hidden = !session && !vod;
    showControls();
    playbackListener?.();
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
    playbackListener?.();
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
    backButton.disabled = !usable;
    forwardButton.disabled = !usable;
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
    // An audio description is a second track in the same language, so without saying which
    // is which the menu just offers "English" twice.
    const described = (audioSources[index] ?? {}).described === true ? ' (described)' : '';

    if (LANGUAGE_NAMES[track?.lang]) return LANGUAGE_NAMES[track.lang] + described;
    if (track?.lang) return track.lang.toUpperCase() + described;

    // No language at all: say which track it is rather than the name ffmpeg gave it.
    return `Track ${index + 1}${described}`;
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
   *
   * Said as "from 5.1" rather than "5.1", because what is playing is stereo either way and
   * the bare number reads as a claim about what you are hearing. The broadcast's layout is
   * still worth showing: it is often the only thing telling two tracks in the same language
   * apart.
   */
  function audioDetail(track, index) {
    const described = (audioSources[index] ?? {}).channels ?? null;

    if (described !== null) return described === '2.0' ? 'from stereo' : `from ${described}`;

    // Nothing known about it: better to say nothing than to show a track number.
    return track.lang ? '' : (track.name ?? '');
  }

  /**
   * Show or hide the settings panel. It only hides on a narrow screen, where the cog is the
   * way in; on a wide one the three controls sit in the row and the cog is not shown at all.
   */
  function toggleSettings() {
    const open = settings.classList.toggle('open');
    settingsButton.setAttribute('aria-expanded', String(open));

    if (!open) {
      closeQualityMenu();
      closeAudioMenu();
      closeCaptionsMenu();
    }
  }

  function closeSettings() {
    if (!settings.classList.contains('open')) return;

    settings.classList.remove('open');
    settingsButton.setAttribute('aria-expanded', 'false');
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
    // Radio has nothing but the picture its station sends, so the controls get out of the
    // way once there is one and stay up when there is not: fading them over a black square
    // would leave the viewer with nothing at all to look at, and no hint there is anything
    // to press. A tap or the mouse brings them back either way.
    const worthClearing = !radio || !artLayer.hidden;

    if (worthClearing && !video.paused && !pointerOnControls && qualityMenu.hidden) hideTimer = setTimeout(() => wrap.classList.add('controls-hidden'), 3000);
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
        radio?.onStation?.(null);
        return;
      }

      audioSources = state.audio ?? [];

      if (state.radio && radio) showStation(state.radio);

      if (state.ready && !hls && !video.getAttribute('src')) {
        attach(state.playlist);
      } else if (!state.ready) {
        // Nothing is written until the station is found, and it may never be: a
        // frequency with no HD Radio on it looks exactly like one still being searched.
        setStatus(!radio ? 'Starting the transcoder…'
          : state.radio?.synchronized ? 'Starting…' : 'Looking for the station…');
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

    // Radio asks more often once it is playing: the answer carries the song, and a title
    // that changed a few seconds ago is worth more than one that changed a while back.
    timer = setTimeout(poll, hls || video.getAttribute('src') ? (radio ? 2000 : 5000) : 1000);
  }

  /**
   * Listen to an HD Radio program. There is no tuner to find and no guide to consult: the
   * station says who it is and what it is playing, and that arrives with each poll.
   */
  async function playRadio({ frequency, program, onStation }) {
    await stop();

    radio = { frequency, program, onStation };
    // Live, like a channel: the playlist rolls and can be rewound. Nothing here records
    // it, there is one size of it, and there is no picture to fill a screen with.
    liveButton.hidden = false;
    recordButton.hidden = true;
    settings.hidden = true;
    fullscreenButton.hidden = true;
    wrap.classList.add('is-radio');
    // The radio view draws the artwork and the transport itself, beside what is playing.
    panel.classList.add('is-radio-panel');
    // Television starts muted because that is the only way a browser will start it
    // unasked. Nobody presses Listen to hear nothing, and the press is the asking.
    video.muted = false;
    panel.hidden = false;
    showStation({ frequency, program });
    spinner.hidden = false;
    setStatus('Tuning…');
    showControls();
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    try {
      session = await api('/api/radio/stream', {
        method: 'POST',
        body: JSON.stringify({ frequency, program, viewer: VIEWER_ID }),
      });
    } catch (error) {
      spinner.hidden = true;
      setStatus(error.message, true);

      return;
    }

    poll();
  }

  /** Put what the station says about itself where a channel's name and programme would go. */
  function showStation(station) {
    const dial = `${Number(station.frequency).toFixed(1)} FM · HD${station.program + 1}`;

    channelLabel.replaceChildren(h('span', {}, station.station ? `${station.station} · ${dial}` : dial));
    programLabel.replaceChildren(...[
      station.title && h('span', { class: 'overlay-title' }, station.title),
      station.artist && h('span', {}, station.artist),
      !station.title && !station.artist && station.slogan && h('span', {}, station.slogan),
    ].filter(Boolean));

    const picture = station.art ?? station.logo ?? null;

    if (picture === null) {
      artLayer.hidden = true;
    } else {
      // Only when it changes: setting the same address again makes the picture blink.
      if (artLayer.getAttribute('src') !== picture) artLayer.src = picture;

      const appeared = artLayer.hidden;
      artLayer.hidden = false;

      // The picture arrives a minute into a station, long after the controls last had a
      // reason to count down. Start them going now there is something behind them.
      if (appeared) showControls();
    }

    radio?.onStation?.(station);
  }

  /**
   * Watch a recording. An mp4 plays as it is; a ts recording is converted on the server,
   * and playback starts as soon as the first segments are ready.
   */
  /**
   * An ATSC 3.0 station whose media is served over the internet. No tuner is involved, so
   * none is found or freed. Its audio is AC-4, which only a purpose-built ffmpeg decodes:
   * the server says whether it has one, and the picture plays silently when it does not.
   * Polling is the same as any other session once it has started.
   *
   * It carries no listings of its own and borrows those of the channel a hundred below,
   * which the guide has already worked out; its logo is filed under that number too. Once
   * the player is told which channel to ask about, the rest of the overlay behaves as it
   * does for a tuner.
   */
  async function playAtsc3({ device, virtual, name, sound = false, logo = false, logoFor = null }) {
    await stop();

    // No tuner, and no program number either: the guide row for one of these carries none,
    // so it is found by its virtual channel alone.
    current = { host: device, tunerIndex: null, program: { number: null, virtualChannel: virtual } };
    // These are live: the manifest is dynamic and the playlist omits an end list, so the
    // live badge and its jump-to-live behave as they do for a tuner. Recording does not:
    // nothing here can capture a stream that never came through the device.
    liveButton.hidden = false;
    recordButton.hidden = true;
    panel.hidden = false;
    channelLabel.replaceChildren(...[
      channelLogo(logoFor ?? virtual, logo),
      h('span', {}, sound ? `${virtual} ${name}` : `${virtual} ${name} · no sound`),
    ].filter(Boolean));
    programLabel.textContent = '';
    spinner.hidden = false;
    setStatus('Starting the transcoder…');
    loadProgramInfo();
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    try {
      session = await api('/api/streams/atsc3', {
        method: 'POST',
        body: JSON.stringify({ device, virtual, viewer: VIEWER_ID }),
      });
    } catch (error) {
      spinner.hidden = true;
      setStatus(error.message, true);

      return;
    }

    poll();
  }

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

    // A copy converted ahead of time keeps nothing alive on the server and will never say
    // anything different, so there is no reason to ask a second time.
    if (state.stored) return;

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

    video.play().catch(() => {
      // Autoplay blocked; the controls still work. Radio asked to start with its sound on,
      // which a browser may refuse where it would have allowed silence, so it settles for
      // silence rather than for nothing.
      if (radio && !video.muted) {
        video.muted = true;
        video.play().catch(() => {});
      }
    });

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
    const endingRadio = radio;
    session = null;
    vod = null;
    radio = null;
    current = null;
    airing = null;
    liveButton.hidden = false;
    recordButton.hidden = false;
    settings.hidden = false;
    fullscreenButton.hidden = false;
    wrap.classList.remove('is-radio');
    panel.classList.remove('is-radio-panel');
    artLayer.hidden = true;
    artLayer.removeAttribute('src');
    endingRadio?.onStation?.(null);
    panel.hidden = true;
    spinner.hidden = true;
    centre.hidden = true;

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

  return {
    play, playFile, playAtsc3, playRadio, stop, leaveOnUnload,
    recordingsChanged: updateRecordButton,
    togglePlay, setVolume, onPlaybackChange, playbackState,
  };
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
      ...channels.map((channel) => h('div', { class: channel.atsc3 ? 'guide-row is-unsupported' : 'guide-row' },
        h('div', { class: 'guide-channel', title: `${channel.virtual} ${channel.name}` },
          channelLogo(channel.logoFor ?? channel.virtual, channel.logo, true),
          // The badges are siblings of the name, not inside it: the name is what truncates,
          // and a badge within it was cut off along with the text it followed.
          h('span', { class: 'guide-channel-name' },
            h('span', { class: 'guide-channel-headline' },
              h('b', { class: 'guide-channel-number' }, channel.virtual),
              appLink(channel)),
            h('span', { class: 'guide-channel-call' }, channel.name)),
          h('span', { class: 'guide-channel-badges' },
            channel.hd && h('span', { class: 'badge hd' }, 'HD'),
            channel.atsc3 && h('span', { class: 'badge tag-atsc3' }, '3.0'),
            channel.broadband && h('span', { class: 'badge tag-ott' }, 'OTT'),
            channel.drm && h('span', { class: 'badge tag-drm' }, 'DRM'),
          ),
        ),
        h('div', { class: 'guide-track' },
          // A row with no programmes never opens the details panel, so its watch button
          // is out of reach. Anything that can be played gets one here instead: an
          // internet-delivered station, an ordinary channel from its tuner.
          channel.events.length === 0 && h('div', { class: 'guide-empty' }, ...emptyTrack(channel)),
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
        channelLogo(channel.logoFor ?? channel.virtual, channel.logo),
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
        // Where the words came from, when they did not come from this channel. Some
        // stations describe their programmes only on their ATSC 3.0 number, so the guide
        // fills the silence from there and says as much rather than implying the channel
        // broadcast a synopsis it never sent.
        event.description
          ? h('p', {}, event.description,
            event.descriptionFrom && h('br'),
            event.descriptionFrom && h('span', { class: 'muted' }, `Description announced on ${event.descriptionFrom}.`))
          : h('p', { class: 'muted' }, 'No description.'),
      ),
      h('div', { class: 'controls' },
        h('button', {
          type: 'button',
          class: 'watch',
          disabled: channel.atsc3 ? !(channel.streamUrl && !channel.drm) : channel.encrypted,
          onclick: (clickEvent) => (channel.atsc3 ? watchAtsc3 : watch)(channel, clickEvent.currentTarget),
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
  // What was last chosen for a programme, so a refresh mid-decision does not undo it.
  let lateChoice = { key: null, value: '' };

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

    // Sport runs over and the broadcast guide never says so: the listing keeps its planned
    // length, and the programmes after it keep their planned times, however late it ends.
    // So how much longer to keep recording is a judgement only the person watching can make.
    //
    // The guide reloads every couple of seconds while anything is running, and this panel is
    // rebuilt each time, so what was chosen has to be remembered or it is gone before the
    // button beside it can be pressed. Remembered against this programme rather than kept as
    // a setting: an hour of margin makes sense for a ball game and no sense at all for the
    // sitcom recorded next week, and a sticky one would quietly follow it there.
    const key = `${channel.virtual}|${event.start}|${event.title}`;
    const late = h('select', {
      class: 'record-format',
      'aria-label': 'Keep recording after it is due to end',
      title: 'Keep recording after it is due to end, for a programme that runs over',
      onchange: () => { lateChoice = { key, value: late.value }; },
    },
      h('option', { value: '' }, 'Stop on time'),
      ...[15, 30, 60].map((minutes) => h('option', { value: String(minutes * 60) }, `+${minutes} min`)));

    if (lateChoice.key === key) late.value = lateChoice.value;

    return h('span', { class: 'record-choice' },
      h('button', {
        type: 'button',
        class: 'secondary record',
        disabled: channel.atsc3 || channel.encrypted,
        onclick: (clickEvent) => record(channel, event, clickEvent.currentTarget, late.value),
      }, onNow ? '● Record the rest' : '● Record'),
      h('button', {
        type: 'button',
        class: 'secondary record',
        disabled: channel.atsc3 || channel.encrypted,
        title: 'Record this whenever it is on this channel',
        onclick: (clickEvent) => recordSeries(channel, event, clickEvent.currentTarget, late.value),
      }, '● All episodes'),
      late,
    );
  }

  /**
   * Keep recording a title on a channel. The broadcast guide only reaches half a day
   * ahead, so the rule is kept and applied to each guide update rather than scheduling
   * anything far in advance.
   */
  async function recordSeries(channel, event, button, padEnd = '') {
    await recordingAction('/api/recordings/rules', {
      method: 'POST',
      body: JSON.stringify({
        ...(padEnd === '' ? {} : { padEnd: Number(padEnd) }),
        device: host,
        physical: channel.physical,
        program: channel.program,
        virtual: channel.virtual,
        channelName: channel.name,
        title: event.title,
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
  async function record(channel, event, button, padEnd = '') {
    await recordingAction('/api/recordings', {
      method: 'POST',
      body: JSON.stringify({
        ...(padEnd === '' ? {} : { padEnd: Number(padEnd) }),
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

  /**
   * A link to the station's own application, where its address is known.
   *
   * Offered for encrypted stations as well: those cannot be tuned here at all, but the
   * application serves the same programming from the broadcaster's broadband service, so
   * it is the only way they can be watched.
   *
   * It sits in the channel cell rather than on an empty row. Most of these stations do
   * carry listings -- the guide fills them from the channel they simulcast -- so an icon
   * that only appeared where there were none was invisible on nearly every one of them.
   *
   * A new tab. The application is a separate experience, and navigating away would throw
   * away whatever is playing.
   */
  function appLink(channel) {
    if (!channel.appUrl) return null;

    const label = `Open the ${channel.virtual} ${channel.name} app`;

    return h('a', {
      class: 'channel-app',
      href: channel.appUrl,
      target: '_blank',
      rel: 'noopener',
      title: label,
      'aria-label': label,
    }, '⧉');
  }

  // Use a tuner already on the channel, or else an idle one.
  /**
   * What an empty row offers: a way to watch it where there is one, and a reason where
   * there is not. An encrypted ATSC 3.0 station cannot be tuned here at all; one delivered
   * over the internet can, with sound only where the server has an AC-4 decoder; an
   * ordinary channel simply has no listings.
   */
  function emptyTrack(channel) {
    const atsc3     = Boolean(channel.atsc3);
    const playable  = atsc3 ? Boolean(channel.streamUrl) && !channel.drm : !channel.encrypted;
    // Only worth saying when it is true. Every other channel has sound and never mentions it.
    const soundless = atsc3 && !channel.sound;

    // Every empty row offers the same control in the same place, so the ones that cannot be
    // played read as refused rather than as forgotten. The reason is on the button.
    const reason = playable
      ? `Watch ${channel.virtual} ${channel.name}${soundless ? ' (no sound)' : ''}`
      : (atsc3
        ? `${channel.virtual} ${channel.name} is encrypted and cannot be played here`
        : `${channel.virtual} ${channel.name} is encrypted`);

    return [
      h('button', {
        type: 'button',
        class: 'guide-empty-play',
        disabled: !playable,
        title: reason,
        'aria-label': reason,
        onclick: (clickEvent) => (atsc3 ? watchAtsc3 : watch)(channel, clickEvent.currentTarget),
      }, '▶'),
      h('span', {}, atsc3
        ? (playable ? 'ATSC 3.0 · no programme data' : 'ATSC 3.0 — cannot be tuned here')
        : 'No guide data'),
    ];
  }

  async function watchAtsc3(channel, button) {
    const label = button.textContent;
    button.disabled = true;
    button.textContent = '…';

    try {
      // Reachable from the details modal as well as the row. The player sits behind
      // the modal, which would otherwise stay up and keep the page inert.
      details.close();
      await player.playAtsc3({
        device: host,
        virtual: channel.virtual,
        name: channel.name,
        sound: Boolean(channel.sound),
        logo: Boolean(channel.logo),
        logoFor: channel.logoFor ?? null,
      });
    } catch (error) {
      showError(error);
    } finally {
      button.disabled = false;
      button.textContent = label;
    }
  }

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
  const series = h('div', { class: 'recordings' });
  const seriesCard = h('section', { class: 'card', hidden: true },
    h('h3', {}, 'Series'),
    series,
  );

  // Held out here because the list reloads every few seconds while something is recording:
  // a filter that cleared itself under you, or a sort that jumped back, would be useless.
  const filters = { scheduled: '', recorded: '' };
  const sorts = {
    scheduled: { key: 'when', direction: 'asc' },
    recorded: { key: 'when', direction: 'desc' },
  };

  // Built once for the same reason: recreating the input on every refresh would take the
  // cursor out of it mid-word.
  function filterBox(which, label) {
    const input = h('input', {
      type: 'search',
      class: 'list-filter',
      id: `filter-${which}`,
      placeholder: label,
      'aria-label': label,
      oninput: () => { filters[which] = input.value; render(); },
    });

    return input;
  }

  const scheduledFilter = filterBox('scheduled', 'Filter by show or channel');
  const recordedFilter  = filterBox('recorded', 'Filter by show or channel');
  const scheduledHead   = h('tr', {});
  const scheduledBody   = h('tbody', {});
  const recordedHead    = h('tr', {});
  const recordedBody    = h('tbody', {});

  const root = h('div', { class: 'view', hidden: true },
    h('section', { class: 'card' },
      // The heading and where recordings are written are one thing, so they stack together
      // and leave the filter as the only other child: the toolbar spaces them apart, which
      // puts the filter on the right here exactly as it is above Recorded.
      h('div', { class: 'guide-toolbar' },
        h('div', { class: 'list-heading' }, h('h3', {}, 'Scheduled'), folder),
        scheduledFilter),
      notice,
      h('div', { class: 'table-wrap' },
        h('table', { class: 'list-table' }, h('thead', {}, scheduledHead), scheduledBody)),
    ),
    seriesCard,
    h('section', { class: 'card' },
      h('div', { class: 'guide-toolbar' }, h('h3', {}, 'Recorded'), recordedFilter),
      h('div', { class: 'table-wrap' },
        h('table', { class: 'list-table' }, h('thead', {}, recordedHead), recordedBody)),
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

    fillTable({
      which: 'scheduled',
      head: scheduledHead,
      body: scheduledBody,
      items: pending,
      empty: 'Nothing scheduled. Pick a program in the Guide and choose Record.',
      columns: [
        {
          key: 'when',
          label: 'Date',
          class: 'cell-when',
          sort: (schedule) => schedule.start,
          cell: (schedule) => `${dayFormat.format(schedule.start * 1000)}, ${timeFormat.format(schedule.start * 1000)}`,
        },
        {
          key: 'title',
          label: 'Show',
          sort: (schedule) => schedule.title.toLowerCase(),
          search: (schedule) => schedule.title,
          cell: (schedule) => h('span', { class: 'cell-show' },
            h('span', { class: 'name' },
              h('span', { class: 'title' }, schedule.title),
              schedule.error && h('span', { class: 'muted' }, schedule.error))),
        },
        {
          key: 'channel',
          label: 'Channel',
          class: 'drop-narrow',
          sort: (schedule) => virtualKey(schedule.virtual),
          search: (schedule) => `${schedule.virtual} ${schedule.channelName}`,
          cell: (schedule) => `${schedule.virtual} ${schedule.channelName}`,
        },
        {
          key: 'length',
          label: 'Length',
          class: 'cell-length drop-narrow',
          sort: (schedule) => schedule.duration,
          cell: (schedule) => formatDuration(schedule.duration),
        },
        {
          key: 'status',
          label: 'Status',
          sort: (schedule) => schedule.status,
          cell: (schedule) => h('span', { class: `badge${schedule.status === 'recording' ? ' locked' : ''}` }, schedule.status),
        },
        {
          key: 'actions',
          label: '',
          class: 'cell-actions',
          cell: (schedule) => h('span', { class: 'actions' }, schedule.status === 'recording'
            ? h('button', {
              type: 'button',
              class: 'secondary',
              onclick: (clickEvent) => stopRecordingFor(schedule, clickEvent.currentTarget),
            }, 'Stop')
            : h('button', {
              type: 'button',
              class: 'secondary',
              onclick: (clickEvent) => act(`/api/recordings/schedules/${schedule.id}`, { method: 'DELETE' }, clickEvent.currentTarget, 'Cancelling…'),
            }, 'Cancel')),
        },
      ],
    });

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

    fillTable({
      which: 'recorded',
      head: recordedHead,
      body: recordedBody,
      items: data.recordings,
      empty: 'Nothing recorded yet.',
      columns: [
        {
          key: 'when',
          label: 'Date',
          class: 'cell-when',
          sort: (recording) => recording.startedAt,
          cell: (recording) => `${dayFormat.format(recording.startedAt * 1000)}, ${timeFormat.format(recording.startedAt * 1000)}`,
        },
        {
          key: 'title',
          label: 'Show',
          sort: (recording) => recording.title.toLowerCase(),
          search: (recording) => recording.title,
          cell: (recording) => h('span', { class: 'cell-show' },
            recordingArt(recording),
            h('span', { class: 'name' },
              h('span', { class: 'title' }, recording.title, ...recordingBadges(recording)),
              recording.status === 'recording' ? progressFor(recording) : null,
              recording.error && h('span', { class: 'muted' }, recording.error),
              describeCopy(recording) && h('span', { class: 'muted' }, describeCopy(recording).replace(/^ · /, '')))),
        },
        {
          key: 'channel',
          label: 'Channel',
          class: 'drop-narrow',
          sort: (recording) => virtualKey(recording.virtual),
          search: (recording) => `${recording.virtual} ${recording.channelName}`,
          cell: (recording) => `${recording.virtual} ${recording.channelName}`,
        },
        {
          key: 'length',
          label: 'Length',
          class: 'cell-length drop-narrow',
          sort: (recording) => lengthOf(recording),
          cell: (recording) => lengthOf(recording) === 0 ? '—' : formatDuration(lengthOf(recording)),
        },
        {
          key: 'size',
          label: 'Size',
          class: 'cell-size',
          sort: (recording) => recording.bytes,
          cell: (recording) => formatBytes(recording.bytes),
        },
        {
          key: 'status',
          label: 'Status',
          sort: (recording) => recording.status,
          // The recorder finishes a stop on its next pass, a few seconds later.
          cell: (recording) => {
            const status = recording.status === 'recording' && recording.stopRequested ? 'stopping' : recording.status;

            return status === 'done'
              ? h('span', { class: 'muted' }, 'done')
              : h('span', { class: `badge${status === 'recording' ? ' locked' : ''}` }, status);
          },
        },
        {
          key: 'actions',
          label: '',
          class: 'cell-actions',
          cell: (recording) => h('span', { class: 'actions' }, recording.status === 'recording'
            ? h('button', {
              type: 'button',
              class: 'secondary',
              onclick: (clickEvent) => act(`/api/recordings/${recording.id}/stop`, { method: 'POST' }, clickEvent.currentTarget, 'Stopping…'),
            }, 'Stop')
            : [
              recording.bytes > 0 && h('button', {
                type: 'button',
                class: 'watch',
                onclick: (clickEvent) => playRecording(recording, clickEvent.currentTarget),
              }, '▶ Play'),
              recording.bytes > 0 && downloadControl(recording),
              convertControl(recording),
              h('button', {
                type: 'button',
                class: 'secondary',
                onclick: (clickEvent) => remove(recording, clickEvent.currentTarget),
              }, 'Delete'),
            ]),
        },
      ],
    });
  }

  /** How long a recording ran. A running one is still growing, so it is measured to now. */
  function lengthOf(recording) {
    return Math.max(0, (recording.endedAt ?? Math.floor(Date.now() / 1000)) - recording.startedAt);
  }

  /** Sort key for "4.10" after "4.9", the same way the guide orders channels. */
  function virtualKey(virtual) {
    const [major, minor] = String(virtual).split('.');

    return Number(major) * 1000 + Number(minor ?? 0);
  }

  /**
   * One list as a table: the same fields on every row, so sorting and filtering are worth
   * more than a card each. The filter matches anything a column offers to search, which is
   * the show and the channel rather than every field on the row.
   */
  function fillTable({ which, head, body, items, columns, empty }) {
    const sort = sorts[which];
    const needle = filters[which].trim().toLowerCase();

    const matching = needle === ''
      ? items
      : items.filter((item) => columns.some((column) => column.search
        && String(column.search(item)).toLowerCase().includes(needle)));

    const column = columns.find((candidate) => candidate.key === sort.key) ?? columns[0];
    const sorted = column.sort
      ? [...matching].sort((first, second) => {
        const left = column.sort(first);
        const right = column.sort(second);
        const order = left < right ? -1 : left > right ? 1 : 0;

        return sort.direction === 'asc' ? order : -order;
      })
      : matching;

    head.replaceChildren(...columns.map((candidate) => {
      if (!candidate.sort) return h('th', { class: candidate.class }, candidate.label);

      const active = candidate.key === sort.key;

      return h('th', { class: [candidate.class, active ? 'sorted' : null].filter(Boolean).join(' ') || null },
        h('button', {
          type: 'button',
          class: 'sort',
          // Clicking the column already sorted reverses it; a new one starts the way that
          // column is most useful, which for a date is newest first.
          onclick: () => {
            sorts[which] = active
              ? { key: candidate.key, direction: sort.direction === 'asc' ? 'desc' : 'asc' }
              : { key: candidate.key, direction: candidate.key === 'when' ? 'desc' : 'asc' };
            render();
          },
        }, candidate.label, active && h('span', { class: 'arrow' }, sort.direction === 'asc' ? '▲' : '▼')),
      );
    }));

    body.replaceChildren(...(sorted.length === 0
      ? [h('tr', {}, h('td', { colspan: String(columns.length), class: 'muted' },
        items.length === 0 ? empty : 'Nothing matches that filter.'))]
      : sorted.map((item) => h('tr', {}, columns.map((candidate) => h('td', { class: candidate.class }, candidate.cell(item)))))));
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

  /**
   * Save a recording, at a size.
   *
   * A converted copy holds each size as a single file, so there is usually more than the
   * broadcast to offer: the smaller ones are H.264 and a fraction of the size, and play on
   * a phone where the broadcast's MPEG-2 and AC-3 will not. Which sizes exist depends on
   * the recording, so they are asked for rather than assumed, and only when the button is
   * pressed -- reading them for every row on every poll would mean touching the drive
   * constantly for something almost nobody clicks.
   */
  function downloadControl(recording) {
    const button = h('button', {
      type: 'button',
      class: 'secondary',
      title: 'Save it to this device',
      onclick: () => offer(),
    }, '⤓ Download');

    async function offer() {
      button.disabled = true;

      let downloads;

      try {
        ({ downloads } = await api(`/api/recordings/${recording.id}/downloads`));
      } catch (error) {
        button.disabled = false;
        showError(error);

        return;
      }

      // Nothing to choose between: behave exactly as the button always did.
      if (downloads.length < 2) {
        button.disabled = false;
        // Content-Disposition makes the browser save it, so the page stays put.
        window.location.href = downloads[0]?.url ?? `/recordings/${recording.id}/file?download=1`;

        return;
      }

      const choice = h('select', {
        class: 'record-format',
        'aria-label': `Download ${recording.title}`,
        onchange: () => {
          if (!choice.value) return;

          window.location.href = choice.value;
          choice.value = '';
        },
      },
        h('option', { value: '' }, '⤓ Download…'),
        ...downloads.map((one) => h('option', {
          value: one.url,
          title: one.detail,
        }, `${one.name} · ${formatBytes(one.bytes)}`)));

      button.replaceWith(choice);
      choice.focus();
    }

    return button;
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
    if (recording.format === 'mp4' || recording.convertedPath) badges.push(h('span', { class: 'badge hd tag-mp4' }, copyKind(recording)));

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

    // Ask for the recording rather than the title: a finished one kept a copy of the
    // picture it was made with, and the title's picture is shared by every recording of
    // the show and changes whenever it is fetched again. The server falls back to the
    // title for recordings made before copies were kept.
    const art = h('img', {
      class: 'art',
      src: recording.id
        ? `/artwork?recording=${encodeURIComponent(recording.id)}`
        : `/artwork?title=${encodeURIComponent(recording.title)}`,
      alt: '',
      loading: 'lazy',
    });

    art.addEventListener('error', () => { art.style.visibility = 'hidden'; });

    return art;
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

  // Which shape the browser-ready copy took. The server decides it, and a copy still being
  // made has not said yet, so only a finished one can be named.
  function copyKind(recording) {
    return recording.convertedPath?.endsWith('.hls') ? 'HLS' : 'MP4';
  }

  // What exists besides the broadcast itself: a browser-ready copy, one being made, or
  // the reason there is none.
  function describeCopy(recording) {
    if (recording.format === 'mp4') return ' · MP4';
    if (recording.convertedBytes) return ` · ${copyKind(recording)} ${formatBytes(recording.convertedBytes)}`;
    if (recording.convertPid) return ' · making a copy for browsers…';
    if (recording.convertError) return ` · no browser copy: ${recording.convertError}`;

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
// HD Radio

/**
 * The radio takes the place a device's tabs would: it has no tuners to list, no guide and
 * nothing to record, only a dial and whatever the station on it says about itself.
 */
function selectRadio() {
  const radio = state.radio;
  if (!radio?.enabled) return;

  state.selectedHost = RADIO_HOST;
  renderDeviceList();

  stopPolling();
  state.tunerCards = [];
  state.guideView?.destroy();
  state.guideView = null;
  state.recordingsView?.destroy();
  state.recordingsView = null;
  state.radioView?.destroy();

  state.player?.stop();
  const playerPanel = h('section', { class: 'player card', hidden: true });
  state.player = createPlayer(playerPanel);
  state.radioView = createRadioView(radio, state.player);

  document.getElementById('device-detail').replaceChildren(...[
    h('div', { class: 'device-header' },
      h('h3', {}, 'HD Radio'),
      h('div', { class: 'facts' },
        h('span', {}, 'Dongle ', h('b', {}, radio.label)),
        radio.device && h('span', {}, 'Tuner ', h('b', {}, radio.device.tuner)),
      ),
    ),
    radio.error && h('p', { class: 'radio-error' }, radio.error),
    playerPanel,
    state.radioView.root,
  ].filter(Boolean));
}

function loadRadioStations() {
  try {
    const stations = JSON.parse(loadSetting(RADIO_STATIONS_STORAGE_KEY) || '[]');
    return Array.isArray(stations) ? stations.filter((station) => Number.isFinite(station?.frequency)) : [];
  } catch {
    return [];
  }
}

function createRadioView(radio, player) {
  // What was asked for, and the token that says a station's news is still about it: a
  // station that was switched away from keeps reporting until it has stopped.
  let listening = null;
  let station = null;
  // Which traffic map is on show. The widest, because a station centres its maps on its
  // market rather than on the city: WFEZ draws from 25.90, -80.45, which puts the close view
  // in the Everglades and only reaches Miami at the widest extent.
  let trafficZoom = 2;
  let hintTimer = null;
  // Removing a station is kept behind this rather than offered on every row, where the
  // button sits under the thumb that meant to choose the station.
  let editing = false;
  let savedLogo = null;
  const loggedLogos = new Set();
  // The server keeps the stations when it has a database to keep them in, so every browser
  // sees the same ones. When it has not, this browser keeps its own, as it used to.
  const serverKeeps = Array.isArray(radio.stations);
  let stations = serverKeeps ? radio.stations : loadRadioStations();
  let scan = radio.scan ?? null;
  let scanTimer = null;
  let destroyed = false;

  const artwork = h('div', { class: 'radio-art' });
  const ident = h('p', { class: 'radio-ident' });
  const title = h('h3', { class: 'radio-title' });
  const artist = h('p', { class: 'radio-artist' });
  const extra = h('div', { class: 'radio-extra' });
  const programButtons = h('div', { class: 'radio-subs', role: 'group', 'aria-label': 'Subchannel' });

  const playButton = h('button', {
    type: 'button', class: 'radio-play', onclick: () => player.togglePlay(),
  });
  const stopButton = h('button', {
    type: 'button', class: 'radio-icon', 'aria-label': 'Stop', title: 'Stop',
    onclick: () => stopListening(),
  }, icon('stop'));
  const volumeSlider = h('input', {
    type: 'range', min: '0', max: '1', step: '0.05', value: '1',
    class: 'radio-volume-slider', 'aria-label': 'Volume',
    oninput: () => player.setVolume(Number(volumeSlider.value)),
  });
  const volumeIcon = h('span', { class: 'radio-volume-icon' }, icon('volume'));
  const signalBox = h('div', { class: 'radio-signal-box' });
  const transport = h('div', { class: 'radio-transport' },
    playButton,
    stopButton,
    h('div', { class: 'radio-volume' }, volumeIcon, volumeSlider),
    signalBox,
  );

  const diagnostics = h('details', { class: 'radio-diag' });

  const frequencyInput = h('input', {
    type: 'number',
    inputmode: 'decimal',
    min: String(radio.band.from),
    max: String(radio.band.to),
    step: '0.1',
    required: true,
    placeholder: '90.5',
    class: 'radio-freq',
    'aria-label': 'Frequency in MHz',
  });
  const downButton = h('button', {
    type: 'button', class: 'radio-step', 'aria-label': 'Down 0.2 MHz', onclick: () => nudge(-0.2),
  }, '−');
  const upButton = h('button', {
    type: 'button', class: 'radio-step', 'aria-label': 'Up 0.2 MHz', onclick: () => nudge(0.2),
  }, '+');
  const listenButton = h('button', { type: 'submit', class: 'radio-listen' }, 'Listen');

  const scanButton = h('button', { type: 'button', class: 'radio-linkish', onclick: onScan }, 'Scan');
  const editButton = h('button', {
    type: 'button', class: 'radio-linkish', 'aria-pressed': 'false',
    onclick: () => { editing = !editing; render(); },
  }, 'Edit');
  const stationCount = h('span', { class: 'radio-count' });
  const stationButtons = h('div', { class: 'radio-stations' });
  const scanStatus = h('div', { class: 'radio-scan', hidden: true });

  const trafficBox = h('div', { class: 'radio-traffic-box' });

  const root = h('section', { class: 'radio' },
    h('div', { class: 'radio-stage' },
      h('div', { class: 'radio-left' },
      h('div', { class: 'radio-main card' },
        h('div', { class: 'radio-now' },
          artwork,
          h('div', { class: 'radio-meta' }, ident, title, artist, extra, programButtons),
        ),
        transport,
        diagnostics,
      ),
      trafficBox,
      ),
      h('div', { class: 'radio-side' },
        h('div', { class: 'card radio-panel' },
          h('div', { class: 'radio-panel-head' }, h('h4', {}, 'Tune')),
          h('form', { class: 'radio-tune', onsubmit: onTune },
            downButton,
            h('div', { class: 'radio-freq-field' },
              frequencyInput,
              h('span', { class: 'muted' }, `MHz · ${radio.band.from.toFixed(1)}–${radio.band.to.toFixed(1)}`),
            ),
            upButton,
            listenButton,
          ),
        ),
        h('div', { class: 'card radio-panel' },
          h('div', { class: 'radio-panel-head' },
            h('h4', {}, 'Stations', stationCount),
            h('div', { class: 'radio-panel-actions' }, scanButton, editButton),
          ),
          scanStatus,
          stationButtons,
        ),
      ),
    ),
  );

  // Somebody may already be listening -- this browser before a reload, or another one.
  // Offer their station rather than the last one typed here: the dongle is already on it.
  let playing = radio.session?.radio ?? null;
  const remembered = Number(loadSetting(RADIO_FREQUENCY_STORAGE_KEY));

  if (playing) frequencyInput.value = Number(playing.frequency).toFixed(1);
  else if (remembered >= radio.band.from && remembered <= radio.band.to) frequencyInput.value = remembered.toFixed(1);

  // The transport is ours but the media element is the player's, so it tells us what it did.
  player.onPlaybackChange(() => renderTransport());

  render();
  if (scan?.running) scheduleScanPoll();

  function nudge(by) {
    const from = Number(frequencyInput.value) || radio.band.from;
    const next = Math.min(radio.band.to, Math.max(radio.band.from, Math.round((from + by) * 10) / 10));

    frequencyInput.value = next.toFixed(1);
  }

  function onTune(event) {
    event.preventDefault();

    const frequency = Math.round(Number(frequencyInput.value) * 10) / 10;
    if (!Number.isFinite(frequency)) return;

    listen(frequency, 0);
  }

  async function stopListening() {
    await player.stop();
    listening = null;
    station = null;
    render();
  }

  function listen(frequency, program) {
    const mine = { frequency, program, since: Date.now() };

    listening = mine;
    station = null;
    // The logo kept from an earlier listen, standing in until this one sends its own --
    // which takes about a minute, and used to be a minute of looking at a grey square.
    savedLogo = stations.find((saved) => saved.frequency === frequency)?.logo ?? null;
    // Whatever was playing when the page opened is not what is playing now.
    playing = null;
    frequencyInput.value = frequency.toFixed(1);
    saveSetting(RADIO_FREQUENCY_STORAGE_KEY, String(frequency));
    render();

    // If nothing has been found in a while, say that this may be all there is to find.
    clearTimeout(hintTimer);
    hintTimer = setTimeout(render, 20000);

    player.playRadio({
      frequency,
      program,
      onStation: (next) => {
        if (listening !== mine) return;

        if (next === null) listening = null;
        station = next;
        if (next?.station) remember(frequency, next.station);
        // The server keeps the logo the moment it can serve it, so this is when the saved
        // list gains one. Asked once: the station goes on sending it for as long as it is on.
        if (next?.logo) keepLogo(frequency);
        render();
      },
    });
  }

  /**
   * Pick up the logo the server has just filed, so the list and the next listen can use it.
   *
   * The station sends it over and over while it is on, and the list is only refetched for
   * this once: without the guard every poll for the rest of the listen would refetch it.
   */
  function keepLogo(frequency) {
    if (!serverKeeps || loggedLogos.has(frequency)) return;

    const saved = stations.find((candidate) => candidate.frequency === frequency);
    if (saved?.logo) return;

    loggedLogos.add(frequency);
    refreshStations();
  }

  /** Stations that have been heard, kept so they can be picked rather than typed. */
  function remember(frequency, name) {
    const known = stations.find((candidate) => candidate.frequency === frequency);
    if (known?.name === name) return;

    // The server noted it when it told us the name; all that is left is to ask for the list.
    if (serverKeeps) {
      refreshStations();

      return;
    }

    stations = [...stations.filter((candidate) => candidate.frequency !== frequency), { frequency, name }]
      .sort((a, b) => a.frequency - b.frequency);
    saveSetting(RADIO_STATIONS_STORAGE_KEY, JSON.stringify(stations));
  }

  async function forget(frequency, name) {
    const label = name ? `${frequency.toFixed(1)} ${name}` : frequency.toFixed(1);
    if (!confirm(`Remove ${label} from your stations?`)) return;

    if (serverKeeps) {
      try {
        applyStations(await api(`/api/radio/stations/${frequency.toFixed(1)}`, { method: 'DELETE' }));
      } catch (error) {
        showError(error);
      }

      return;
    }

    stations = stations.filter((candidate) => candidate.frequency !== frequency);
    saveSetting(RADIO_STATIONS_STORAGE_KEY, JSON.stringify(stations));
    render();
  }

  async function refreshStations() {
    try {
      applyStations(await api('/api/radio/scan'));
    } catch { /* the list on screen is still true, only older */ }
  }

  /** Take the stations and the scan's progress from any answer that carries them. */
  function applyStations(body) {
    if (serverKeeps && Array.isArray(body.stations)) stations = body.stations;
    if (listening) savedLogo = stations.find((s) => s.frequency === listening.frequency)?.logo ?? savedLogo;
    scan = body.scan ?? null;
    render();
  }

  /**
   * Look for stations up the whole dial, or stop looking. The scan needs the dongle to
   * itself, so the server refuses while a station is playing and says so.
   */
  async function onScan() {
    scanButton.disabled = true;

    try {
      // This browser's own station would only be refused for being in the way.
      if (!scan?.running && listening !== null) await player.stop();

      applyStations(await api('/api/radio/scan', { method: scan?.running ? 'DELETE' : 'POST' }));
      scheduleScanPoll();
    } catch (error) {
      showError(error);
    }

    scanButton.disabled = false;
  }

  function scheduleScanPoll() {
    clearTimeout(scanTimer);
    if (destroyed || !scan?.running) return;

    scanTimer = setTimeout(async () => {
      await refreshStations();
      scheduleScanPoll();
    }, 2000);
  }

  function describeScan() {
    if (scan === null) return null;

    const found = scan.found?.length ?? 0;
    const stationsFound = `${found} ${found === 1 ? 'station' : 'stations'} found`;

    if (scan.running) {
      return scan.frequency === null || scan.frequency === undefined
        ? 'Starting the scan…'
        : `Scanning ${Number(scan.frequency).toFixed(1)} FM · ${scan.done} of ${scan.total} tried · ${stationsFound}`;
    }

    if (scan.error) return `The scan stopped: ${scan.error}`;

    return `${scan.stopped ? 'Scan stopped' : 'Scan finished'} · ${scan.done} of ${scan.total} tried · ${stationsFound}`;
  }

  function render() {
    const scanning = Boolean(scan?.running);
    const scanText = describeScan();

    // A scan has the dongle, so nothing can be listened to until it ends or is stopped.
    listenButton.disabled = scanning;
    upButton.disabled = scanning;
    downButton.disabled = scanning;
    scanButton.textContent = scanning ? 'Stop scan' : 'Scan';
    scanButton.title = scanning ? 'Stop looking for stations' : 'Look for stations on every frequency; takes about ten minutes';
    // The scan is only offered where its results can be kept.
    scanButton.hidden = !serverKeeps;
    scanStatus.hidden = scanText === null;
    scanStatus.textContent = scanText ?? '';
    scanStatus.classList.toggle('radio-error', Boolean(scan?.error) && !scanning);

    editButton.hidden = stations.length === 0;
    editButton.textContent = editing ? 'Done' : 'Edit';
    editButton.setAttribute('aria-pressed', String(editing));
    stationCount.textContent = stations.length ? ` · ${stations.length}` : '';
    stationButtons.classList.toggle('is-editing', editing);

    stationButtons.replaceChildren(...stations.map((saved) => {
      const chosen = listening?.frequency === saved.frequency;

      return h('div', { class: 'radio-station', 'aria-current': String(chosen) },
        h('button', {
          type: 'button',
          class: 'radio-station-pick',
          disabled: scanning || editing,
          onclick: () => listen(saved.frequency, 0),
          // A station found before it gave its name is still a station; it is its frequency.
        },
          h('span', { class: `radio-station-logo${saved.logo ? '' : ' is-empty'}` },
            saved.logo && h('img', { src: saved.logo, alt: '', loading: 'lazy' })),
          h('b', {}, saved.frequency.toFixed(1)),
          h('span', { class: 'radio-station-name' }, saved.name ?? ''),
          chosen && h('span', { class: 'radio-bars', 'aria-hidden': 'true' },
            h('i', {}), h('i', {}), h('i', {})),
        ),
        editing && h('button', {
          type: 'button',
          class: 'radio-remove',
          title: `Forget ${saved.name ?? saved.frequency.toFixed(1)}`,
          'aria-label': `Forget ${saved.name ?? saved.frequency.toFixed(1)}`,
          onclick: () => forget(saved.frequency, saved.name),
        }, '×'),
      );
    }));
    stationButtons.hidden = stations.length === 0;

    // HD1 is always there; the rest are offered once the station has said it has them.
    const numbers = new Set([0, ...(station?.programs ?? []).map((program) => program.number)]);
    if (listening) numbers.add(listening.program);

    programButtons.replaceChildren(...[...numbers].sort((a, b) => a - b).map((number) => {
      const details = (station?.programs ?? []).find((program) => program.number === number);

      return h('button', {
        type: 'button',
        class: 'radio-sub',
        title: [details?.name, details?.type].filter(Boolean).join(' · ') || null,
        'aria-pressed': String(listening?.program === number),
        onclick: () => listen(listening.frequency, number),
      }, `HD${number + 1}`);
    }));
    programButtons.hidden = listening === null;

    renderNowPlaying();
    renderTransport();
    trafficBox.replaceChildren(...[trafficMap(station)].filter(Boolean));
  }

  /** The artwork, who is on and what they are playing -- the reason the view exists. */
  function renderNowPlaying() {
    const picture = station?.art ?? station?.logo ?? (listening ? savedLogo : null);

    artwork.replaceChildren(picture
      ? h('img', { src: picture, alt: station?.station ? `${station.station} artwork` : 'Station artwork' })
      : h('span', { class: 'radio-art-empty', 'aria-hidden': 'true' },
        listening ? listening.frequency.toFixed(1) : '●'));
    artwork.classList.toggle('is-empty', picture === null);

    if (listening === null) {
      ident.textContent = playing
        ? `${playing.station ? `${playing.station} · ` : ''}${Number(playing.frequency).toFixed(1)} FM is on`
        : 'Nothing playing';
      title.textContent = playing ? 'Pick a station, or tune one' : 'Pick a station';
      artist.textContent = '';
      extra.replaceChildren(h('p', { class: 'muted' },
        'HD Radio stations in North America sit on the odd tenths: 88.1, 90.5, 101.1.'));

      return;
    }

    const dial = `${listening.frequency.toFixed(1)} FM`;

    if (!station?.synchronized) {
      ident.textContent = dial;
      title.textContent = 'Looking for HD Radio…';
      artist.textContent = '';
      extra.replaceChildren(...[
        Date.now() - listening.since >= 20000 && h('p', { class: 'muted' },
          'Nothing digital yet. Not every station broadcasts HD Radio, and one that does needs a stronger signal than its analogue sound.'),
      ].filter(Boolean));

      return;
    }

    // Some stations put the subchannel in the slogan, which the pill below already says:
    // WFEZ sends "HD1", Ritmo 95 sends "HD-1" and WRTO sends "WRTO-HD1", which is its own
    // name with the same thing stuck on. None of the three tells you anything here.
    const subLabel = `HD${listening.program + 1}`;
    const saysNothing = [subLabel, station.station, `${station.station ?? ''}${subLabel}`]
      .some((said) => sameWords(station.slogan, said));
    const slogan = station.slogan && !saysNothing ? station.slogan : null;

    ident.replaceChildren(...[
      h('span', { class: 'radio-dial' }, listening.frequency.toFixed(1), h('i', {}, 'FM')),
      station.station && h('span', {}, station.station),
      slogan && h('span', { class: 'muted' }, slogan),
    ].filter(Boolean));

    title.textContent = station.title || station.station || dial;
    artist.textContent = station.artist ?? '';
    artist.hidden = !station.artist;

    // With no song on, a station fills title, album and message with its own branding, and
    // WFEZ sends all three: "EASY 93.1", "EASY HD1 93.1" and "EASY 93.1 80's, 90's, and
    // More!". They are not equal, so matching on equality let all three through. One line
    // that restates another is dropped, whichever of the two is longer.
    const said = [station.title, station.artist, station.station, station.slogan].filter(Boolean);
    const fresh = (text) => {
      if (!text) return false;
      if (said.some((seen) => echoes(seen, text))) return false;
      said.push(text);

      return true;
    };

    extra.replaceChildren(...[
      station.alert && h('p', { class: 'radio-error' }, station.alert),
      // An album with nobody playing it is the station's name in the album field.
      station.artist && fresh(station.album) && h('p', { class: 'muted' }, station.album),
      fresh(station.message) && h('p', { class: 'muted' }, station.message),
    ].filter(Boolean));

    renderDiagnostics();
  }

  /** Signal stays in sight because it says whether this will hold; the rest folds away. */
  function renderDiagnostics() {
    const codec = station.codecMode === null || station.codecMode === undefined
      ? null
      : `HDC mode ${station.codecMode}`;

    const rows = [
      codec && ['Codec', codec],
      station.bitrate && ['Bitrate', `${Math.round(station.bitrate)} kbps`],
      station.gain !== null && station.gain !== undefined && ['Gain', `${station.gain.toFixed(1)} dB`],
      station.genre && ['Genre', station.genre],
    ].filter(Boolean);

    diagnostics.hidden = rows.length === 0;
    if (rows.length === 0) return;

    diagnostics.replaceChildren(
      h('summary', {}, h('span', { class: 'radio-diag-mark', 'aria-hidden': 'true' }, '\u203a'), 'Reception detail'),
      h('dl', { class: 'radio-diag-grid' }, ...rows.map(([term, value]) =>
        h('div', {}, h('dt', {}, term), h('dd', {}, value)))),
    );
  }

  /** The same words, give or take the punctuation and capitals a station varies. */
  function sameWords(left, right) {
    return plain(left) === plain(right);
  }

  /** Whether one line says what another already said -- either way round. */
  function echoes(seen, candidate) {
    const a = plain(seen);
    const b = plain(candidate);
    if (a === '' || b === '') return false;

    return a.includes(b) || b.includes(a);
  }

  function plain(text) {
    return String(text).toLowerCase().replace(/[^a-z0-9]+/g, '');
  }

  function renderTransport() {
    const state = player.playbackState();
    const live = listening !== null;

    transport.hidden = !live;
    if (!live) {
      signalBox.replaceChildren();

      return;
    }

    playButton.replaceChildren(icon(state.paused ? 'play' : 'pause'));
    playButton.setAttribute('aria-label', state.paused ? 'Play' : 'Pause');
    playButton.title = state.paused ? 'Play' : 'Pause';

    volumeIcon.replaceChildren(icon(state.volume === 0 ? 'muted' : 'volume'));
    volumeSlider.value = String(state.volume);
    volumeSlider.style.setProperty('--level', String(state.volume));

    const hasSignal = station?.mer !== null && station?.mer !== undefined;

    signalBox.replaceChildren(...[
      hasSignal && signalMeter(station.mer),
      hasSignal && h('span', { class: 'radio-signal' }, `Signal ${station.mer.toFixed(1)} dB`),
      !hasSignal && state.status && h('span', { class: state.failed ? 'radio-error' : 'muted' }, state.status),
    ].filter(Boolean));
  }

  /**
   * The traffic map a station draws, when it draws one.
   *
   * Only some stations carry it, so this is nothing at all on most of them. The picture is
   * already a map -- streets, names and the roads coloured by how they are moving -- so it
   * is shown as it arrived. Three of them come, the same place at three extents; the buttons
   * choose between them and the choice sticks while the station is on.
   */
  function trafficMap(station) {
    const maps = station?.traffic ?? [];

    if (maps.length === 0) return null;

    const chosen = maps.find((m) => m.zoom === trafficZoom) ?? maps[maps.length - 1];
    const names = { 0: 'Close', 1: 'City', 2: 'Wide' };

    const picture = h('img', {
      class: 'traffic-map',
      src: chosen.url,
      alt: `Traffic around ${chosen.north.toFixed(2)}, ${chosen.west.toFixed(2)}`,
    });

    return h('div', { class: 'traffic card' },
      h('div', { class: 'traffic-head' },
        h('span', {}, 'Traffic'),
        h('span', { class: 'muted' }, `drawn ${clockFromEpoch(chosen.at)}`),
        h('span', { class: 'traffic-zooms' }, ...maps.map((m) => h('button', {
          type: 'button',
          class: m.zoom === chosen.zoom ? 'secondary is-on' : 'secondary',
          onclick: () => { trafficZoom = m.zoom; render(); },
        }, names[m.zoom] ?? String(m.zoom)))),
      ),
      picture);
  }

  function clockFromEpoch(seconds) {
    return timeFormat.format(seconds * 1000);
  }

  /**
   * How good that number is, for anyone who does not know what a good MER is.
   *
   * The bands come from listening here rather than from a standard: 98.3 at 10 dB never
   * faltered, 94.9 at 4.8 dB held but sat near the edge, 92.3 at 2.6 dB broke up, and a
   * station at 89.7 never locked at all. So four bars and up is comfortable, three is
   * workable, and below that expect it to drop out.
   */
  function signalMeter(mer) {
    const bars = mer >= 12 ? 5 : mer >= 9 ? 4 : mer >= 6 ? 3 : mer >= 4 ? 2 : 1;
    const tone = bars >= 4 ? 'green' : bars === 3 ? 'yellow' : 'red';
    const word = bars >= 4 ? 'strong' : bars === 3 ? 'workable' : bars === 2 ? 'weak' : 'barely there';

    return h('span', {
      class: `signal-meter ${tone}`,
      role: 'img',
      title: `Signal ${word}: ${mer.toFixed(1)} dB. Four bars and up plays without dropping out.`,
      'aria-label': `Signal ${word}, ${mer.toFixed(1)} decibels`,
    }, ...[1, 2, 3, 4, 5].map((step) => h('i', { class: step <= bars ? 'on' : '' })));
  }

  function destroy() {
    clearTimeout(hintTimer);
    clearTimeout(scanTimer);
    scanTimer = null;
    destroyed = true;
    listening = null;
    player.onPlaybackChange(null);
  }

  return { root, destroy };
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
