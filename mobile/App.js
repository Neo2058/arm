import TrainingRequestsScreen from './src/screens/TrainingRequestsScreen';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { NavigationContainer, DefaultTheme } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { StatusBar } from 'expo-status-bar';
import JournalScreen, { JournalTasksScreen, JournalCrewScreen } from './src/screens/JournalScreen';
import LoginScreen from './src/screens/LoginScreen';
import ProfileScreen from './src/screens/ProfileScreen';
import WorkTimeScreen from './src/screens/WorkTimeScreen';
import { clearWorkTimeData } from './src/worktime/storage';
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
import * as SecureStore from 'expo-secure-store';
import { API_URL, logout, me } from './src/api';
import { DisciplineProvider, clearDisciplineData } from './src/discipline/context';
import AppIcon, { IconLabel, TAB_ICONS } from './src/components/AppIcon';

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
      <Stack.Screen name="TrainingRequests" component={TrainingRequestsScreen} options={{ title: 'Занятия АТЗ' }} />
      <Stack.Screen name="JournalCrew" component={JournalCrewScreen} options={{ title: 'Нормативы' }} />
      <Stack.Screen name="DisciplineDetail" component={DisciplineDetailScreen} options={{ title: 'Карточка исполнения' }} />
      <Stack.Screen name="DisciplineAttachment" component={DisciplineAttachmentScreen} options={({ route }) => ({ title: route.params.file.name })} />
    </Stack.Navigator>
  );
}

function ProfileStack({ user, onLogout, onReauthenticate, onProfileSaved }) {
  return <Stack.Navigator screenOptions={{ headerTintColor: '#fb923c', headerStyle: { backgroundColor: '#0b1018' } }}>
    <Stack.Screen name="ProfileHome" options={{ title: 'Профиль' }}>{({ navigation }) => <ProfileScreen user={user} onLogout={onLogout} onReauthenticate={onReauthenticate} onProfileSaved={onProfileSaved} onOpenWorkTime={() => navigation.navigate('PersonalWorkTime')} />}</Stack.Screen>
    <Stack.Screen name="PersonalWorkTime" options={{ title: 'Личный табель' }}>{() => <WorkTimeScreen userId={user.id} />}</Stack.Screen>
  </Stack.Navigator>;
}

function MainTabs({ user, onLogout, onReauthenticate, onProfileSaved }) {
  return (
    <Tab.Navigator
      screenOptions={({ route }) => ({
        headerShown: false,
        tabBarIcon: ({ focused, color, size }) => <AppIcon name={TAB_ICONS[route.name]} color={color} size={size} strokeWidth={focused ? 2.3 : 1.8} />,
        tabBarStyle: { backgroundColor: '#0b1018', borderTopColor: '#1f2937' },
        tabBarActiveTintColor: '#fb923c',
        tabBarInactiveTintColor: '#6b7280',
        tabBarLabelStyle: user.role === 'instructor' ? { fontSize: 10 } : undefined,
      })}
    >
      {user.role === 'instructor' && <Tab.Screen name="DisciplineTab" component={DisciplineStack} options={{ title: 'Поручения' }} />}
      <Tab.Screen name="DocsTab" component={DocumentsStack} options={{ title: 'Документы' }} />
      <Tab.Screen name="QuizTab" component={QuizzesStack} options={{ title: 'Тесты' }} />
      <Tab.Screen name="NaryadTab" component={NaryadsStack} options={{ title: 'Наряды' }} />
      <Tab.Screen name="TrainTab" component={TrainingStack} options={{ title: 'Обучение' }} />
      <Tab.Screen
        name="ProfileTab"
        options={{ title: 'Профиль' }}
      >
        {() => (
          <ProfileStack key={user.id} user={user} onLogout={onLogout} onReauthenticate={onReauthenticate} onProfileSaved={onProfileSaved} />
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
          onProfileSaved={async fresh => {
            await SecureStore.setItemAsync('disciplineIdentity', JSON.stringify({ server: API_URL, user: fresh }));
            setUser(fresh);
          }}
          onReauthenticate={() => setReauth(true)}
          onLogout={async () => {
            try {
              await clearWorkTimeData(user.id);
              await clearDisciplineData();
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
