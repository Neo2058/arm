const POLL_MS = 15000;
const BACKOFF_429_MS = 30000;

function shouldPoll(featuresChat, appState) {
  return featuresChat === true && appState === 'active';
}

function unreadTotal(rooms) {
  return (rooms || []).reduce((sum, room) => sum + (Number(room.unread) || 0), 0);
}

function mergeMessages(existing, incoming) {
  const map = new Map();
  for (const msg of existing || []) {
    if (msg && msg.id != null) map.set(msg.id, msg);
  }
  for (const msg of incoming || []) {
    if (msg && msg.id != null) map.set(msg.id, msg);
  }
  return [...map.values()].sort((a, b) => {
    const left = typeof a.id === 'number' ? a.id : 0;
    const right = typeof b.id === 'number' ? b.id : 0;
    return left - right;
  });
}

function maxAvailableId(messages) {
  let max = 0;
  for (const msg of messages || []) {
    if (typeof msg.id === 'number' && msg.status !== 'rejected' && msg.id > max) max = msg.id;
  }
  return max;
}

function createChatPoller(options) {
  const featuresChat = options.featuresChat === true;
  const pollMs = options.pollMs || POLL_MS;
  const backoffMs = options.backoffMs || BACKOFF_429_MS;
  const now = options.now || Date.now;
  const setIntervalFn = options.setIntervalFn || setInterval;
  const clearIntervalFn = options.clearIntervalFn || clearInterval;
  const syncInbox = options.syncInbox;
  const fetchThread = options.fetchThread;
  const flushOutbox = options.flushOutbox;
  const markRead = options.markRead;
  const onChange = options.onChange;
  const userId = options.userId;

  let appState = null;
  let intervalId = null;
  let stopped = false;
  let inFlight = false;
  let again = false;
  let backoffUntil = 0;
  let focusedRoomId = null;
  const listeners = new Set();
  const state = {
    rooms: [],
    unread: 0,
    offline: false,
    messagesByRoom: {},
  };

  function getState() {
    return {
      rooms: state.rooms.slice(),
      unread: state.unread,
      offline: state.offline,
      messagesByRoom: { ...state.messagesByRoom },
      focusedRoomId,
      userId,
    };
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
    if (stopped || !shouldPoll(featuresChat, appState)) {
      disarm();
      return;
    }
    if (intervalId == null) {
      intervalId = setIntervalFn(() => { tick(); }, pollMs);
      tick();
    }
  }

  async function tick() {
    if (stopped || !shouldPoll(featuresChat, appState)) return;
    if (now() < backoffUntil) return;
    if (inFlight) {
      again = true;
      return;
    }
    inFlight = true;
    try {
      try {
        const data = await syncInbox();
        state.offline = false;
        state.rooms = (data && data.rooms) || [];
        state.unread = unreadTotal(state.rooms);
        emit();
      } catch (error) {
        const status = error && error.response && error.response.status;
        if (status === 429) backoffUntil = now() + backoffMs;
        state.offline = true;
        emit();
        try { if (flushOutbox) await flushOutbox(); } catch { /* inbox already marked offline */ }
        return;
      }

      const roomId = focusedRoomId;
      if (roomId != null && fetchThread) {
        try {
          const page = await fetchThread(roomId);
          const incoming = (page && page.messages) || page || [];
          state.messagesByRoom = {
            ...state.messagesByRoom,
            [roomId]: mergeMessages(state.messagesByRoom[roomId] || [], incoming),
          };
          const last = maxAvailableId(state.messagesByRoom[roomId]);
          if (last && markRead && focusedRoomId === roomId) {
            try {
              await markRead(roomId, last);
              state.rooms = state.rooms.map((room) => (room.id === roomId ? { ...room, unread: 0 } : room));
              state.unread = unreadTotal(state.rooms);
            } catch { /* next tick retries read */ }
          }
          emit();
        } catch (error) {
          const status = error && error.response && error.response.status;
          if (status === 429) backoffUntil = now() + backoffMs;
          if (!status) {
            state.offline = true;
            emit();
          }
        }
      }

      try { if (flushOutbox) await flushOutbox(); } catch { /* pending stays in outbox */ }
    } finally {
      inFlight = false;
      if (again && !stopped) {
        again = false;
        tick();
      }
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
    setFocusedRoomId(id) {
      const next = id == null || id === '' ? null : id;
      focusedRoomId = next;
      if (next != null) tick();
      else emit();
    },
    mergeThread(roomId, incoming) {
      state.messagesByRoom = {
        ...state.messagesByRoom,
        [roomId]: mergeMessages(state.messagesByRoom[roomId] || [], incoming),
      };
      emit();
    },
    syncNow() { return tick(); },
    isRunning() { return intervalId != null; },
    stop() {
      stopped = true;
      disarm();
    },
  };
}

let session = null;
const waiters = new Set();

function attachChatSession(next) {
  session = next || null;
  for (const fn of waiters) fn(session);
}

function stopMobileChat() {
  session?.poller?.stop();
  session = null;
  for (const fn of waiters) fn(null);
}

function getChatSession() {
  return session;
}

function getChatPoller() {
  return session?.poller || null;
}

function getChatOutbox() {
  return session?.outbox || null;
}

function whenChatSession(fn) {
  if (session) fn(session);
  waiters.add(fn);
  return () => waiters.delete(fn);
}

async function clearChatData(storage) {
  try {
    if (session?.outbox) await session.outbox.clear();
    else if (storage) await storage.clear();
  } finally {
    stopMobileChat();
    attachChatSession(null);
  }
}

module.exports = {
  POLL_MS,
  BACKOFF_429_MS,
  shouldPoll,
  unreadTotal,
  mergeMessages,
  createChatPoller,
  attachChatSession,
  stopMobileChat,
  getChatSession,
  getChatPoller,
  getChatOutbox,
  whenChatSession,
  clearChatData,
};
