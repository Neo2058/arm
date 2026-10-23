const DEFAULT_API_URL = 'https://www.xn---15-7edz.xn--p1ai/api/mobile';

function resolveApiUrl(value) {
  const url = (typeof value === 'string' && value.trim() ? value.trim() : DEFAULT_API_URL).replace(/\/+$/, '');
  // The production certificate is valid for www only. Resolve before any credentials are sent;
  // an HTTP redirect cannot repair a TLS hostname mismatch.
  return url.replace(/^https:\/\/(?:www\.)?(?:xn---15-7edz\.xn--p1ai|тч-15\.рф)(?=\/|$)/i,
    'https://www.xn---15-7edz.xn--p1ai');
}

function loginErrorMessage(error) {
  if (error.code === 'INVALID_AUTH_RESPONSE') return 'Сервер вернул неожиданный ответ. Обновите приложение или обратитесь к администратору.';
  if (error.code === 'AUTH_STORAGE_FAILED') return 'Не удалось сохранить вход на устройстве. Повторите попытку.';
  if (!error.response) return 'Не удалось установить защищённое соединение с сервером. Проверьте интернет и версию приложения.';
  const status = error.response.status;
  if (status === 429) return 'Слишком много попыток входа. Подождите минуту и попробуйте снова.';
  if (status >= 500) return 'Сервер временно недоступен. Попробуйте позже.';
  if (status === 422 || status === 401) return 'Неверный email или пароль.';
  if (status === 403) return 'Доступ к аккаунту ограничен. Обратитесь к администратору.';
  return 'Не удалось войти. Обратитесь к администратору.';
}

module.exports = { DEFAULT_API_URL, resolveApiUrl, loginErrorMessage };
