const { test } = require('node:test');
const assert = require('node:assert/strict');
const { validateDraft, validateFiles, freezeSubmission, multipart, errorMessage, LIMITS } = require('../src/discipline/contract.cjs');
const a = { id: '42', version: '9007199254740993', status: 'assigned', requirements: { require_response: true, require_report_file: true, require_explanation_file: false } };
const file = { id: 'file', category: 'report', name: 'report.pdf', uri: 'file:///cache/report.pdf', mime: 'application/pdf', size: 500 };
const uuid = '1a7c6694-ae1c-4fac-ab3d-312c33422073';
class Form { constructor() { this.parts = []; } append(...parts) { this.parts.push(parts); } }

test('retry retains original response, exact version and file metadata despite subsequent edits', () => {
  const files = [{ ...file }];
  const snap = freezeSubmission(a, ' Ответ ', files, uuid);
  files[0].name = 'changed.pdf'; files.push({ ...file });
  const changed = { ...a, version: '9007199254740994' };
  assert.equal(snap.version, '9007199254740993');
  assert.notEqual(snap.version, changed.version);
  assert.equal(snap.files.length, 1); assert.equal(snap.files[0].name, 'report.pdf');
  assert.equal(snap.response, 'Ответ'); assert.equal(snap.operation_id, uuid);
  assert.deepEqual(multipart(snap, Form).parts, multipart(snap, Form).parts);
  assert.equal(Object.isFrozen(snap.files[0]), true);
});
test('multipart binds attachments to report categories and has no client-supplied owner', () => {
  const snap = freezeSubmission(a, 'Done', [{ ...file, category: 'other' }, file], uuid);
  const parts = multipart(snap, Form).parts;
  assert.deepEqual(parts.map(p => p[0]), ['operation_id', 'version', 'response', 'report_files[]', 'other_files[]']);
  assert.equal(parts[3][1].uri, file.uri);
  assert.equal(parts[3][1].name, 'report.pdf');
});
test('submission validates requirements, quotas, file formats and current workflow state', () => {
  assert.match(validateDraft(a, '', [file]), /текстовый/);
  assert.match(validateDraft(a, 'Done', []), /файл отчёта/);
  assert.equal(validateDraft(a, 'Done', [file]), '');
  assert.match(validateDraft({ ...a, status: 'in_review' }, 'Done', [file]), /не принимает/);
  assert.match(validateFiles(Array(6).fill(file)), /пяти|5/);
  assert.match(validateFiles([{ ...file, size: LIMITS.max_file_bytes + 1 }]), /10 МиБ/);
  assert.match(validateFiles(Array(3).fill({ ...file, size: 9 * 1048576 })), /25 МиБ/);
  assert.match(validateFiles([{ ...file, name: 'report.exe' }]), /PDF/);
  assert.match(validateFiles([{ ...file, size: undefined }]), /Размер/);
  assert.match(validateDraft({ ...a, requirements: { require_response: false, require_report_file: false, require_explanation_file: true } }, '', [file]), /объяснения/);
});
test('server failures do not leak internals and expired auth/conflicts get actionable messages', () => {
  assert.match(errorMessage({ response: { status: 401 } }), /Вход истёк/);
  assert.match(errorMessage({ response: { status: 412 } }), /другом устройстве/);
  assert.match(errorMessage({ code: 'ERR_NETWORK' }), /тот же запрос/);
  assert.doesNotMatch(errorMessage({ response: { status: 500, data: { message: 'secret SQL password' } } }), /secret|SQL|password/);
  assert.match(errorMessage({ response: { status: 422, data: { errors: { files: ['Антивирус отклонил вложение.'] } } } }), /Антивирус/);
});
