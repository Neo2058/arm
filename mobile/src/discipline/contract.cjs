const LIMITS = Object.freeze({ max_files: 5, max_file_bytes: 10 * 1024 * 1024, max_total_bytes: 25 * 1024 * 1024 });
const LABELS = Object.freeze({ assigned: 'Ожидает отчёта', in_review: 'На проверке', needs_revision: 'На доработке', accepted: 'Принято' });
const CATEGORIES = Object.freeze({ report: 'Отчёт', explanation: 'Объяснение', other: 'Приложение' });
const TYPES = Object.freeze({ 'application/pdf': ['pdf'], 'image/jpeg': ['jpg', 'jpeg'], 'image/png': ['png'] });
function validateDraft(assignment, response, files, limits = LIMITS) {
  if (!['assigned', 'needs_revision'].includes(assignment.status)) return 'Эта карточка сейчас не принимает отчёты.';
  if (response.length > 20000) return 'Ответ должен содержать не более 20 000 символов.';
  if (assignment.requirements.require_response && !response.trim()) return 'Добавьте текстовый ответ.';
  const fileError = validateFiles(files, limits);
  if (fileError) return fileError;
  if (assignment.requirements.require_report_file && !files.some(f => f.category === 'report')) return 'Приложите файл отчёта.';
  if (assignment.requirements.require_explanation_file && !files.some(f => f.category === 'explanation')) return 'Приложите файл объяснения.';
  return '';
}
function validateFiles(files, limits = LIMITS) {
  if (files.length > limits.max_files) return `Можно приложить не более ${limits.max_files} файлов.`;
  let total = 0;
  for (const file of files) {
    if (!CATEGORIES[file.category]) return 'Выберите категорию приложения.';
    if (!Number.isInteger(file.size) || file.size <= 0 || file.size > limits.max_file_bytes) return 'Размер каждого файла должен быть от 1 байта до 10 МиБ.';
    const extension = file.name.split('.').pop().toLowerCase();
    if (!TYPES[file.mime]?.includes(extension)) return 'Допустимы PDF, JPEG и PNG с соответствующим расширением.';
    if (!file.uri) return 'Выберите файл заново.';
    total += file.size;
  }
  if (total > limits.max_total_bytes) return 'Общий размер файлов не должен превышать 25 МиБ.';
  return '';
}
function freezeSubmission(assignment, response, files, operationId) {
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(operationId)) throw new Error('Invalid operation ID');
  return Object.freeze({
    assignmentId: String(assignment.id), version: String(assignment.version), response: response.trim(), operation_id: operationId,
    files: Object.freeze(files.map(file => Object.freeze({ ...file }))),
  });
}
function multipart(snapshot, Form = globalThis.FormData) {
  const form = new Form();
  form.append('operation_id', snapshot.operation_id);
  form.append('version', snapshot.version);
  form.append('response', snapshot.response);
  // Match the web service's deterministic category order for exact request replays.
  for (const category of Object.keys(CATEGORIES)) {
    for (const file of snapshot.files.filter(f => f.category === category)) {
      form.append(`${category}_files[]`, { uri: file.uri, name: file.name, type: file.mime });
    }
  }
  return form;
}
function errorMessage(error) {
  const status = error?.response?.status;
  if (status === 401) return 'Вход истёк. Выйдите через профиль и войдите снова.';
  if (status === 403) return 'Доступ к поручениям недоступен для этой учётной записи.';
  if (status === 404) return 'Карточка недоступна или модуль ещё не подключён на сервере.';
  if (status === 409) return 'Состояние отчёта изменилось. Обновите карточку и проверьте историю.';
  if (status === 412) return 'Карточка изменилась на другом устройстве. Проверьте обновлённые данные перед новой отправкой.';
  if (status === 413) return 'Сервер отклонил размер запроса. Уменьшите вложения.';
  if (status === 422) {
    const messages = Object.values(error?.response?.data?.errors || {}).flat().filter(x => typeof x === 'string');
    return messages.slice(0, 3).join('\n') || 'Проверьте ответ и обязательные приложения.';
  }
  if (status === 429) return 'Слишком много запросов. Подождите и повторите отправку.';
  if (status >= 500) return 'Сервер или проверка файлов временно недоступны. Повторите тот же запрос позже.';
  return 'Не удалось связаться с сервером. Ответ мог быть получен: повторите тот же запрос для проверки.';
}
module.exports = { LIMITS, LABELS, CATEGORIES, TYPES, validateFiles, validateDraft, freezeSubmission, multipart, errorMessage };
