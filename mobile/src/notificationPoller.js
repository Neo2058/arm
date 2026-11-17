const POLL_MS = 30000;
const BACKOFF_429_MS = 30000;

function shouldPoll(featuresOn, appState) {
  return featuresOn === true && appState === 'active';
}

function createNotificationPoller(options) {
  const featuresOn = options.featuresOn === true;
  const pollMs = options.pollMs || POLL_MS;
  const backoffMs = options.backoffMs || BACKOFF_429_MS;
  const now = options.now || Date.now;
  const setIntervalFn = options.setIntervalFn || setInterval;
  const clearIntervalFn = options.clearIntervalFn || clearInterval;
  const fetchInbox = options.fetchInbox;
  const onChange = options.onChange;

  let appState = null;
  let intervalId = null;
  let stopped = false;
  let inFlight = false;
  let backoffUntil = 0;
  const listeners = new Set();
  const state = { unread: 0, items: [], offline: false };

  function getState() {
    return { unread: state.unread, items: state.items.slice(), offline: state.offline };
  }

  function emit() {
    const snap = getState();
    if (onChange) onChange(snap);
    for (const fn of listeners) fn(snap);
  }

  function disarm() {
    if (intervalId != null) {
      clearIntervalFn(intervalId);
      intervalId = null;
    }
  }

  function arm() {
    if (stopped || !shouldPoll(featuresOn, appState)) {
      disarm();
      return;
    }
    if (intervalId == null) {
      intervalId = setIntervalFn(() => { tick(); }, pollMs);
      tick();
    }
  }

  async function tick() {
    if (stopped || !shouldPoll(featuresOn, appState)) return;
    if (now() < backoffUntil) return;
    if (inFlight) return;
    inFlight = true;
    try {
      const data = await fetchInbox();
      state.offline = false;
      state.items = (data && data.items) || [];
      state.unread = Number(data && data.unread) || 0;
      emit();
    } catch (error) {
      const status = error && error.response && error.response.status;
      if (status === 429) backoffUntil = now() + backoffMs;
      state.offline = true;
      emit();
    } finally {
      inFlight = false;
    }
  }

  return {
    getState,
    subscribe(fn) {
      listeners.add(fn);
      fn(getState());
      return () => listeners.delete(fn);
    },
    setAppState(next) {
      appState = next;
      arm();
    },
    syncNow() { return tick(); },
    isRunning() { return intervalId != null; },
    stop() {
      stopped = true;
      disarm();
    },
  };
}

module.exports = {
  POLL_MS,
  BACKOFF_429_MS,
  shouldPoll,
  createNotificationPoller,
};
