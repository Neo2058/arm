import { registerRootComponent } from 'expo';

import MainApp from './App';
import MonitorApp from './src/monitor/MonitorApp';
const App = process.env.EXPO_PUBLIC_MONITOR_APP === '1' ? MonitorApp : MainApp;

// registerRootComponent calls AppRegistry.registerComponent('main', () => App);
// It also ensures that whether you load the app in Expo Go or in a native build,
// the environment is set up appropriately
registerRootComponent(App);
