const { requireOptionalNativeModule } = require('expo-modules-core');

const Native = requireOptionalNativeModule('ArmNotificationPoller');

async function start(token, apiUrl) {
  if (!Native) return false;
  await Native.start(String(token || ''), String(apiUrl || ''));
  return true;
}

async function stop() {
  if (!Native) return false;
  await Native.stop();
  return true;
}

async function setForeground(value) {
  if (!Native) return false;
  await Native.setForeground(value === true);
  return true;
}

module.exports = { start, stop, setForeground };
