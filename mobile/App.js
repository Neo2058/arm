import { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, AppState, Pressable, StyleSheet, Text, View } from 'react-native';
import { NavigationContainer, DefaultTheme } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { StatusBar } from 'expo-status-bar';
import * as Crypto from 'expo-crypto';
import * as FileSystem from 'expo-file-system/legacy';
import JournalScreen, { JournalTasksScreen, JournalCrewScreen } from './src/screens/JournalScreen';
import LoginScreen from './src/screens/LoginScreen';
import ProfileScreen from './src/screens/ProfileScreen';
import DocumentsScreen from './src/screens/DocumentsScreen';
import DocumentViewScreen from './src/screens/DocumentViewScreen';
import TopicsScreen from './src/screens/TopicsScreen';
import MaterialsScreen from './src/screens/MaterialsScreen';
import MaterialScreen from './src/screens/MaterialScreen';
import QuizzesScreen from './src/screens/QuizzesScreen';
import QuizPlayScreen from './src/screens/QuizPlayScreen';
import NaryadsScreen from './src/screens/NaryadsScreen';
import NaryadViewScreen from './src/screens/NaryadViewScreen';
import PhonesScreen from './src/screens/PhonesScreen';
import DisciplineListScreen from './src/screens/DisciplineListScreen';
import DisciplineDetailScreen from './src/screens/DisciplineDetailScreen';
import DisciplineAttachmentScreen from './src/screens/DisciplineAttachmentScreen';
import ChatsScreen from './src/screens/ChatsScreen';
import ChatThreadScreen from './src/screens/ChatThreadScreen';
import * as SecureStore from 'expo-secure-store';
import { API_URL, listChatMessages, logout, markChatRead, me, sendChatText, syncChats } from './src/api';
import { DisciplineProvider, clearDisciplineData } from './src/discipline/context';
import AppIcon, { IconLabel, TAB_ICONS } from './src/components/AppIcon';
import { attachChatSession, clearChatData, createChatPoller, getChatPoller, stopMobileChat } from './src/chatPoller';
import { createFileStorage, createOutbox } from './src/chatOutbox';

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

function QuizzesStack() {
  return (
    <Stack.Navigator screenOptions={{ headerTintColor: '#fb923c', headerStyle: { backgroundColor: '#0b1018' } }}>
      <Stack.Screen name="QuizzesList" component={QuizzesScreen} options={{ title: 'Тесты' }} />
      <Stack.Screen name="QuizPlay" component={QuizPlayScreen} options={({ route }) => ({ title: route.params?.title || 'Тест' })} />
    </Stack.Navigator>
  );
}

function NaryadsStack() {
  return (
    <Stack.Navigator screenOptions={{ headerTintColor: '#fb923c', headerStyle: { backgroundColor: '#0b1018' } }}>
      <Stack.Screen
        name="NaryadsList"
        component={NaryadsScreen}
        options={({ navigation }) => ({
          title: 'Наряды',
          headerRight: () => (
            <Pressable accessibilityRole="button" accessibilityLabel="Телефоны" hitSlop={10} onPress={() => navigation.navigate('Phones')} style={{ padding: 10 }}>
              <AppIcon name="phone" />
            </Pressable>
          ),
        })}
      />
      <Stack.Screen name="NaryadView" component={NaryadViewScreen} options={({ route }) => ({ title: route.params?.title || 'Наряд' })} />
      <Stack.Screen name="DocumentView" component={DocumentViewScreen} options={({ route }) => ({ title: route.params?.title || 'PDF' })} />
      <Stack.Screen name="Phones" component={PhonesScreen} options={{ title: 'Телефоны' }} />
    </Stack.Navigator>
  );
}

function DisciplineStack() {
  return (
    <Stack.Navigator screenOptions={{ headerTintColor: '#fb923c', headerStyle: { backgroundColor: '#0b1018' } }}>
      <Stack.Screen name="DisciplineList" component={DisciplineListScreen} options={{ title: 'Поручения' }} />
      <Stack.Screen name="Journal" component={JournalScreen} options={{ title: 'Журнал инструктора' }} />
      <Stack.Screen name="JournalTasks" component={JournalTasksScreen} options={{ title: 'Личные задачи' }} />
      <Stack.Screen name="JournalCrew" component={JournalCrewScreen} options={{ title: 'Нормативы' }} />
      <Stack.Screen name="DisciplineDetail" component={DisciplineDetailScreen} options={{ title: 'Карточка исполнения' }} />
      <Stack.Screen name="DisciplineAttachment" component={DisciplineAttachmentScreen} options={({ route }) => ({ title: route.params.file.name })} />
    </Stack.Navigator>
  );
}

function ChatStack() {
  return (
    <Stack.Navigator screenOptions={{ headerTintColor: '#fb923c', headerStyle: { backgroundColor: '#0b1018' } }}>
      <Stack.Screen name="ChatsList" component={ChatsScreen} options={{ title: 'Чаты' }} />
      <Stack.Screen name="ChatThread" component={ChatThreadScreen} options={({ route }) => ({ title: route.params?.title || 'Чат' })} />
    </Stack.Navigator>
  );
}

function MainTabs({ user, onLogout, onReauthenticate }) {
  const chatOn = user.features?.chat === true;
  const [unread, setUnread] = useState(0);

  useEffect(() => {
    if (!chatOn) {
      stopMobileChat();
      attachChatSession(null);
      setUnread(0);
      return undefined;
    }
    let cancelled = false;
    let sub;
    const outbox = createOutbox({
      storage: createFileStorage(FileSystem),
      userId: user.id,
      uuid: () => Crypto.randomUUID(),
      digest: (value) => Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, value),
      send: async (item) => {
        const res = await sendChatText(item.room_id, {
          client_id: item.client_id,
          type: item.type,
          body: item.body,
        });
        return { status: res.status, data: res.data };
      },
      onAck: (item, res) => {
        const data = res?.data || {};
        if (data.id != null) {
          getChatPoller()?.mergeThread(item.room_id, [{
            id: data.id,
            client_id: item.client_id,
            type: item.type,
            body: item.body,
            sender_id: user.id,
            sender_name: user.name,
            created_at: data.created_at,
            status: data.status || 'available',
          }]);
        }
        getChatPoller()?.syncNow();
      },
    });
    const poller = createChatPoller({
      featuresChat: true,
      userId: user.id,
      syncInbox: () => syncChats(),
      fetchThread: (roomId) => listChatMessages(roomId, { limit: 50 }),
      flushOutbox: () => outbox.flush(),
      markRead: (roomId, lastId) => markChatRead(roomId, lastId),
      onChange: (state) => setUnread(state.unread),
    });
    (async () => {
      try {
        await outbox.load();
      } catch { /* empty in-memory queue; FileSystem write retried on send */ }
      if (cancelled) {
        poller.stop();
        outbox.stop();
        return;
      }
      attachChatSession({ poller, outbox });
      sub = AppState.addEventListener('change', (next) => poller.setAppState(next));
      poller.setAppState(AppState.currentState);
    })();
    return () => {
      cancelled = true;
      sub?.remove();
      poller.stop();
      outbox.stop();
      stopMobileChat();
    };
  }, [chatOn, user.id]);

  return (
    <Tab.Navigator
      screenOptions={({ route }) => ({
        headerShown: false,
        tabBarIcon: ({ focused, color, size }) => <AppIcon name={TAB_ICONS[route.name]} color={color} size={size} strokeWidth={focused ? 2.3 : 1.8} />,
        tabBarStyle: { backgroundColor: '#0b1018', borderTopColor: '#1f2937' },
        tabBarActiveTintColor: '#fb923c',
        tabBarInactiveTintColor: '#6b7280',
        tabBarLabelStyle: user.role === 'instructor' || chatOn ? { fontSize: 10 } : undefined,
      })}
    >
      {chatOn && (
        <Tab.Screen
          name="ChatTab"
          component={ChatStack}
          options={{
            title: 'Чаты',
            tabBarBadge: unread > 0 ? unread : undefined,
            tabBarBadgeStyle: { backgroundColor: '#ea580c', color: '#fff' },
          }}
          listeners={{
            focus: () => getChatPoller()?.syncNow(),
          }}
        />
      )}
      {user.role === 'instructor' && <Tab.Screen name="DisciplineTab" component={DisciplineStack} options={{ title: 'Поручения' }} />}
      <Tab.Screen name="DocsTab" component={DocumentsStack} options={{ title: 'Документы' }} />
      <Tab.Screen name="QuizTab" component={QuizzesStack} options={{ title: 'Тесты' }} />
      <Tab.Screen name="NaryadTab" component={NaryadsStack} options={{ title: 'Наряды' }} />
      <Tab.Screen name="TrainTab" component={TrainingStack} options={{ title: 'Обучение' }} />
      <Tab.Screen
        name="ProfileTab"
        options={{ title: 'Профиль', headerShown: true, headerTintColor: '#fb923c', headerStyle: { backgroundColor: '#0b1018' } }}
      >
        {() => (
          <ProfileScreen key={user.id} user={user} onLogout={onLogout} onReauthenticate={onReauthenticate} />
        )}
      </Tab.Screen>
    </Tab.Navigator>
  );
}

export default function App() {
  const [reauth, setReauth] = useState(false);
  const [boot, setBoot] = useState(true);
  const [user, setUser] = useState(null);

  useEffect(() => {
    (async () => {
      try {
        const token = await SecureStore.getItemAsync('token');
        if (token) {
          try {
            const fresh = await me();
            await SecureStore.setItemAsync('disciplineIdentity', JSON.stringify({ server: API_URL, user: fresh }));
            setUser(fresh);
          } catch (error) {
            if (error.response) throw error;
            const saved = JSON.parse(await SecureStore.getItemAsync('disciplineIdentity') || 'null');
            if (saved?.server === API_URL && saved.user?.role === 'instructor') setUser({ ...saved.user, _offline: true });
          }
        }
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
      {user && !reauth ? (
        <DisciplineProvider key={String(user.id)} user={user}><MainTabs
          key={String(user.id)}
          user={user}
          onReauthenticate={() => setReauth(true)}
          onLogout={async () => {
            try {
              await clearDisciplineData();
              await clearChatData(createFileStorage(FileSystem));
              await SecureStore.deleteItemAsync('disciplineIdentity');
              await logout();
              setUser(null);
            } catch { Alert.alert('Выход', 'Не удалось очистить локальные черновики. Повторите выход.'); }
          }}
        /></DisciplineProvider>
      ) : (
        <LoginScreen onLoggedIn={async fresh => {
          await SecureStore.setItemAsync('disciplineIdentity', JSON.stringify({ server: API_URL, user: fresh }));
          setUser(fresh); setReauth(false);
        }} />
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
