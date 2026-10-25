import axios from 'axios';
import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';
import { resolveApiUrl } from '../api-config.cjs';

export const API_URL = resolveApiUrl(
  process.env.EXPO_PUBLIC_API_URL ||
  Constants.expoConfig?.extra?.apiUrl
);

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
  if (typeof data?.token !== 'string' || !data.token || !data.user || typeof data.user !== 'object') {
    const error = new Error('Unexpected authentication response');
    error.code = 'INVALID_AUTH_RESPONSE';
    throw error;
  }
  try {
    await SecureStore.setItemAsync('token', data.token);
  } catch {
    const error = new Error('Authentication storage unavailable');
    error.code = 'AUTH_STORAGE_FAILED';
    throw error;
  }
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

export async function syncChats() {
  const { data } = await client.get('/chats/sync');
  return data;
}

export async function listChatMessages(roomId, params = {}) {
  const { data } = await client.get(`/chats/${encodeURIComponent(roomId)}/messages`, { params });
  return data;
}

export function sendChatText(roomId, payload) {
  return client.post(`/chats/${encodeURIComponent(roomId)}/messages`, payload);
}

export function markChatRead(roomId, lastReadMessageId) {
  return client.post(`/chats/${encodeURIComponent(roomId)}/read`, {
    last_read_message_id: lastReadMessageId,
  });
}
