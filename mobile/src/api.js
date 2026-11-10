import axios from 'axios';
import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';
import { resolveApiUrl } from '../api-config.cjs';
import { isPublicAuthRequest, withTimeout } from './auth-storage.cjs';

export const API_URL = resolveApiUrl(
  process.env.EXPO_PUBLIC_API_URL ||
  Constants.expoConfig?.extra?.apiUrl
);

const STORE_MS = 2000;
const clientHeaders = {
  Accept: 'application/json',
  'X-TCH15-Client': `ru.tch15.arm/${Constants.expoConfig?.version || 'unknown'}`,
};

export const client = axios.create({
  baseURL: API_URL,
  timeout: 25000,
  headers: clientHeaders,
});

export const CHAT_UPLOAD_TIMEOUT_MS = 30000;

export const chatUploadClient = axios.create({
  baseURL: API_URL,
  timeout: CHAT_UPLOAD_TIMEOUT_MS,
  headers: clientHeaders,
});

async function attachToken(config) {
  if (isPublicAuthRequest(config)) return config;
  try {
    const token = await withTimeout(SecureStore.getItemAsync('token'), STORE_MS);
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
  } catch {
    // A hung SecureStore must not block login or the first authenticated call.
  }
  return config;
}

client.interceptors.request.use(attachToken);
chatUploadClient.interceptors.request.use(attachToken);

export async function login(email, password) {
  const { data } = await client.post('/login', { email, password });
  if (typeof data?.token !== 'string' || !data.token || !data.user || typeof data.user !== 'object') {
    const error = new Error('Unexpected authentication response');
    error.code = 'INVALID_AUTH_RESPONSE';
    throw error;
  }
  try {
    await withTimeout(SecureStore.setItemAsync('token', data.token), STORE_MS);
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
  try {
    await withTimeout(SecureStore.deleteItemAsync('token'), STORE_MS);
  } catch {
    // local token may remain; next boot treats storage failure as logged out
  }
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

export function sendChatAttachment(roomId, item) {
  if (!item?.localUri) {
    const error = new Error('Файл недоступен. Выберите его заново.');
    error.response = { status: 422, data: { message: error.message } };
    throw error;
  }
  const form = new FormData();
  form.append('client_id', item.client_id);
  form.append('type', item.type);
  if (item.body) form.append('body', item.body);
  form.append('file', {
    uri: item.localUri,
    name: item.fileName || (item.type === 'pdf' ? 'file.pdf' : 'photo.jpg'),
    type: item.mime || (item.type === 'pdf' ? 'application/pdf' : 'image/jpeg'),
  });
  return chatUploadClient.post(`/chats/${encodeURIComponent(roomId)}/messages`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
}

export function markChatRead(roomId, lastReadMessageId) {
  return client.post(`/chats/${encodeURIComponent(roomId)}/read`, {
    last_read_message_id: lastReadMessageId,
  });
}

export async function listChatMembers(roomId) {
  const { data } = await client.get(`/chats/${encodeURIComponent(roomId)}/members`);
  return data.members || [];
}

export async function listEligibleChatMembers(roomId, q = '') {
  const { data } = await client.get(`/chats/${encodeURIComponent(roomId)}/eligible-members`, {
    params: q ? { q } : {},
  });
  return data.users || [];
}

export function addChatMember(roomId, userId) {
  return client.post(`/chats/${encodeURIComponent(roomId)}/members`, { user_id: userId });
}

export function removeChatMember(roomId, userId) {
  return client.delete(`/chats/${encodeURIComponent(roomId)}/members/${encodeURIComponent(userId)}`);
}
