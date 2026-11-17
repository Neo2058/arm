const { withAndroidManifest } = require('expo/config-plugins');

function stripNotificationComponents(config) {
  return withAndroidManifest(config, (result) => {
    const app = result.modResults.manifest.application?.[0];
    if (!app) {
      return result;
    }
    app.service = (app.service || []).filter(
      (item) => !String(item.$?.['android:name'] || '').includes('ArmNotificationService'),
    );
    app.receiver = (app.receiver || []).filter(
      (item) => !String(item.$?.['android:name'] || '').includes('ArmNotificationBootReceiver'),
    );
    return result;
  });
}

module.exports = function withArmNotifications(config) {
  if (process.env.EXPO_PUBLIC_MONITOR_APP === '1') {
    return stripNotificationComponents(config);
  }
  return config;
};
