import { client } from '../api';
import { multipart } from './contract.cjs';

export async function listAssignments(page = 1, status = '', signal) {
  return (await client.get('/discipline', { params: { page, ...(status ? { status } : {}) }, signal })).data;
}
export async function getAssignment(id, signal) {
  return (await client.get(`/discipline/${encodeURIComponent(id)}`, { signal })).data;
}
export async function getReports(id, page) {
  return (await client.get(`/discipline/${encodeURIComponent(id)}/reports`, { params: { page } })).data;
}
export async function markRead(id) {
  await client.post(`/discipline/${encodeURIComponent(id)}/read`);
}
export async function submitReport(snapshot) {
  return (await client.post(`/discipline/${encodeURIComponent(snapshot.assignmentId)}/submit`, multipart(snapshot), {
    headers: { 'Content-Type': 'multipart/form-data' }, timeout: 360000,
  })).data;
}
export async function getAttachment(id) {
  return (await client.get(`/discipline/files/${encodeURIComponent(id)}`, { responseType: 'arraybuffer' })).data;
}
