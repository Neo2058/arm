import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { NavigationContainer, DefaultTheme } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { StatusBar } from 'expo-status-bar';
import LoginScreen from './src/screens/LoginScreen';
import DocumentsScreen from './src/screens/DocumentsScreen';
import DocumentViewScreen from './src/screens/DocumentViewScreen';
import TopicsScreen from './src/screens/TopicsScreen';
import MaterialsScreen from './src/screens/MaterialsScreen';
import MaterialScreen from './src/screens/MaterialScreen';
import * as SecureStore from 'expo-secure-store';
import { logout, me } from './src/api';

const Stack = createNativeStackNavigator();
const Tab = createBottomTabNavigator();

const navTheme = {
  ...DefaultTheme,
  colors: {
    ...DefaultTheme.colors,
    background: '#0b1018',
    card: '#0b1018',
    text: '#fff',
    border: '#1f2937',
    primary: '#fb923c',
  },
};

function DocumentsStack() {
  return (
    <Stack.Navigator screenOptions={{ headerTintColor: '#fb923c', headerStyle: { backgroundColor: '#0b1018' } }}>
      <Stack.Screen name="DocumentsList" component={DocumentsScreen} options={{ title: 'Документы' }} />
      <Stack.Screen name="DocumentView" component={DocumentViewScreen} options={({ route }) => ({ title: route.params?.title || 'Документ' })} />
    </Stack.Navigator>
  );
}

function TrainingStack() {
  return (
    <Stack.Navigator screenOptions={{ headerTintColor: '#fb923c', headerStyle: { backgroundColor: '#0b1018' } }}>
      <Stack.Screen name="Topics" component={TopicsScreen} options={{ title: 'Обучение' }} />
      <Stack.Screen name="Materials" component={MaterialsScreen} options={({ route }) => ({ title: route.params?.title || 'Материалы' })} />
      <Stack.Screen name="Material" component={MaterialScreen} options={({ route }) => ({ title: route.params?.title || 'Материал' })} />
    </Stack.Navigator>
  );
}

function MainTabs({ user, onLogout }) {
  return (
    <Tab.Navigator
      screenOptions={{
        headerShown: false,
        tabBarStyle: { backgroundColor: '#0b1018', borderTopColor: '#1f2937' },
        tabBarActiveTintColor: '#fb923c',
        tabBarInactiveTintColor: '#6b7280',
      }}
    >
      <Tab.Screen name="DocsTab" component={DocumentsStack} options={{ title: 'Документы' }} />
      <Tab.Screen name="TrainTab" component={TrainingStack} options={{ title: 'Обучение' }} />
      <Tab.Screen
        name="ProfileTab"
        options={{ title: 'Профиль', headerShown: true, headerTintColor: '#fb923c', headerStyle: { backgroundColor: '#0b1018' } }}
      >
        {() => (
          <View style={styles.profile}>
            <Text style={styles.name}>{user.name}</Text>
            <Text style={styles.meta}>{user.email}</Text>
            <Text style={styles.meta}>Роль: {user.role}</Text>
            <Pressable style={styles.logout} onPress={onLogout}>
              <Text style={styles.logoutText}>Выйти</Text>
            </Pressable>
          </View>
        )}
      </Tab.Screen>
    </Tab.Navigator>
  );
}

export default function App() {
  const [boot, setBoot] = useState(true);
  const [user, setUser] = useState(null);

  useEffect(() => {
    (async () => {
      const token = await SecureStore.getItemAsync('token');
      if (!token) {
        setBoot(false);
        return;
      }
      try {
        setUser(await me());
      } catch {
        setUser(null);
      } finally {
        setBoot(false);
      }
    })();
  }, []);

  if (boot) {
    return (
      <View style={styles.boot}>
        <ActivityIndicator color="#fb923c" />
      </View>
    );
  }

  return (
    <NavigationContainer theme={navTheme}>
      <StatusBar style="light" />
      {user ? (
        <MainTabs
          user={user}
          onLogout={async () => {
            await logout();
            setUser(null);
          }}
        />
      ) : (
        <LoginScreen onLoggedIn={setUser} />
      )}
    </NavigationContainer>
  );
}

const styles = StyleSheet.create({
  boot: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center' },
  profile: { flex: 1, backgroundColor: '#0b1018', padding: 24 },
  name: { color: '#fff', fontSize: 22, fontWeight: '700' },
  meta: { color: '#9ca3af', marginTop: 6 },
  logout: { marginTop: 28, backgroundColor: '#1f2937', padding: 14, borderRadius: 12, alignItems: 'center' },
  logoutText: { color: '#fb923c', fontWeight: '700' },
});
