import { useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { login } from '../api';

export default function LoginScreen({ onLoggedIn }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const submit = async () => {
    setError('');
    setLoading(true);
    try {
      const user = await login(email.trim(), password);
      onLoggedIn(user);
    } catch (e) {
      const message = e.response?.data?.message || 'Не удалось войти. Проверьте сеть и данные.';
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <KeyboardAvoidingView
      style={styles.wrap}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <Text style={styles.title}>ТЧ-15</Text>
      <Text style={styles.sub}>Вход для сотрудников</Text>
      <TextInput
        style={styles.input}
        autoCapitalize="none"
        keyboardType="email-address"
        placeholder="Email"
        placeholderTextColor="#9ca3af"
        value={email}
        onChangeText={setEmail}
      />
      <TextInput
        style={styles.input}
        secureTextEntry
        placeholder="Пароль"
        placeholderTextColor="#9ca3af"
        value={password}
        onChangeText={setPassword}
      />
      {error ? <Text style={styles.error}>{error}</Text> : null}
      <Pressable style={styles.btn} onPress={submit} disabled={loading}>
        {loading ? <ActivityIndicator color="#fff" /> : <Text style={styles.btnText}>Войти</Text>}
      </Pressable>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center', padding: 24 },
  title: { color: '#fb923c', fontSize: 36, fontWeight: '700', textAlign: 'center' },
  sub: { color: '#9ca3af', textAlign: 'center', marginBottom: 28, marginTop: 6 },
  input: {
    backgroundColor: '#151b24',
    color: '#fff',
    borderRadius: 14,
    padding: 14,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: '#1f2937',
  },
  error: { color: '#f87171', marginBottom: 12 },
  btn: { backgroundColor: '#ea580c', borderRadius: 14, padding: 16, alignItems: 'center' },
  btnText: { color: '#fff', fontWeight: '700', fontSize: 16 },
});
