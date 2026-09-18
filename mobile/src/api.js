import axios from 'axios';
import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';

export const API_URL =
  Constants.expoConfig?.extra?.apiUrl || 'http://127.0.0.1:8000/api/mobile';

export const client = axios.create({
  baseURL: API_URL,
  timeout: 25000,
  headers: { Accept: 'application/json' },
});

client.interceptors.request.use(async (config) => {
  const token = await SecureStore.getItemAsync('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export async function login(email, password) {
  const { data } = await client.post('/login', { email, password });
  await SecureStore.setItemAsync('token', data.token);
  return data.user;
}

export async function logout() {
  try {
    await client.post('/logout');
  } catch {
    // token already invalid
  }
  await SecureStore.deleteItemAsync('token');
}

export async function me() {
  const { data } = await client.get('/me');
  return data.user;
}
