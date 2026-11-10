const { resolveApiUrl } = require('./api-config.cjs');
const apiUrl = resolveApiUrl(process.env.EXPO_PUBLIC_API_URL);

const monitor = process.env.EXPO_PUBLIC_MONITOR_APP === '1';

const isHttp = apiUrl.startsWith('http://');

export default {
  expo: {
    name: monitor ? 'ТЧ-15 · Мониторинг' : 'ТЧ-15',
    slug: monitor ? 'arm-monitor' : 'arm-mobile',
    version: monitor ? '1.0.0' : '1.1.4',
    orientation: 'portrait',
    icon: monitor ? './assets/monitor/icon.png' : './assets/icon.png',
    userInterfaceStyle: 'automatic',
    scheme: monitor ? 'tch15-monitor' : 'tch15',
    splash: {
      image: monitor ? './assets/monitor/icon.png' : './assets/icon.png',
      resizeMode: 'contain',
      backgroundColor: '#0b1018',
    },
    ios: {
      supportsTablet: true,
      bundleIdentifier: 'ru.tch15.arm',
      buildNumber: '20',
      infoPlist: {
        NSAppTransportSecurity: isHttp
          ? { NSAllowsLocalNetworking: true, NSAllowsArbitraryLoads: true }
          : { NSAllowsLocalNetworking: true },
        ITSAppUsesNonExemptEncryption: false,
      },
    },
    android: {
      package: monitor ? 'ru.tch15.arm.monitor' : 'ru.tch15.arm',
      allowBackup: false,
      versionCode: monitor ? 1 : 20,
      permissions: monitor ? [] : ['android.permission.REQUEST_INSTALL_PACKAGES'],
      blockedPermissions: monitor ? ['android.permission.READ_EXTERNAL_STORAGE', 'android.permission.WRITE_EXTERNAL_STORAGE', 'android.permission.READ_MEDIA_IMAGES', 'android.permission.READ_MEDIA_VIDEO', 'android.permission.SYSTEM_ALERT_WINDOW', 'android.permission.REQUEST_INSTALL_PACKAGES'] : [],
      usesCleartextTraffic: isHttp,
      adaptiveIcon: {
        backgroundColor: '#0b1018',
        foregroundImage: monitor ? './assets/monitor/android-icon-foreground.png' : './assets/android-icon-foreground.png',
        backgroundImage: monitor ? './assets/monitor/android-icon-background.png' : './assets/android-icon-background.png',
        monochromeImage: monitor ? './assets/monitor/android-icon-monochrome.png' : './assets/android-icon-monochrome.png',
      },
    },
    plugins: [
      './plugins/with-release-signing.cjs',
      'expo-secure-store',
      'expo-video',
      ['expo-audio', { microphonePermission: false, recordAudioAndroid: false }],
      [
        'expo-image-picker',
        {
          photosPermission: 'ТЧ-15 использует фото, чтобы отправить снимок в рабочий чат колонны.',
          cameraPermission: false,
          microphonePermission: false,
        },
      ],
      'expo-document-picker',
      'expo-file-system',
    ],
    extra: {
      apiUrl,
      ...(process.env.EAS_PROJECT_ID
        ? { eas: { projectId: process.env.EAS_PROJECT_ID } }
        : {}),
    },
  },
};
