import * as FS from 'expo-file-system/legacy';
import * as Crypto from 'expo-crypto';

export async function createStorage(server, userId, namespace = 'discipline') {
  const scope = await Crypto.digestStringAsync(Crypto.CryptoDigestAlgorithm.SHA256, `${server}\n${userId}`);
  const root = `${FS.documentDirectory}${namespace}/${scope}/`;
  await FS.makeDirectoryAsync(`${root}files`, { intermediates: true });
  let revision = 0;
  const revisions = async () => (await FS.readDirectoryAsync(root)).filter(n => /^state-\d+\.json$/.test(n)).sort((a, b) => Number(b.slice(6, -5)) - Number(a.slice(6, -5)));
  return {
    async read() {
      const names = await revisions();
      for (const name of names) {
        revision = Math.max(revision, Number(name.slice(6, -5)));
        try { const data = JSON.parse(await FS.readAsStringAsync(root + name)); if (data.schema === 1 && data.entries) return data; } catch { /* crash during last write: try previous completed revision */ }
      }
      if (names.length) throw new Error('Не удалось прочитать сохранённые черновики. Данные сохранены на устройстве.');
      return null;
    },
    async write(data) {
      const name = `state-${++revision}.json`;
      await FS.writeAsStringAsync(root + name, JSON.stringify(data));
      // Read-back before acknowledging persistence; keep the previous completed revision.
      JSON.parse(await FS.readAsStringAsync(root + name));
      const names = await revisions();
      for (const old of names.slice(2)) await FS.deleteAsync(root + old, { idempotent: true }).catch(() => {});
      const used = new Set();
      for (const saved of names.slice(0, 2)) {
        try { const savedState = JSON.parse(await FS.readAsStringAsync(root + saved)); for (const e of Object.values(savedState.entries)) for (const f of [...e.files, ...(e.pending?.files || [])]) used.add(f.uri); } catch { /* retain unknown files on invalid state */ return; }
      }
      for (const file of await FS.readDirectoryAsync(root + 'files')) if (!used.has(`${root}files/${file}`)) await FS.deleteAsync(`${root}files/${file}`, { idempotent: true }).catch(() => {});
    },
    async importFiles(files) {
      const result = [];
      for (const file of files) {
        const uri = `${root}files/${file.id}.${file.name.split('.').pop().toLowerCase()}`;
        await FS.copyAsync({ from: file.uri, to: uri });
        const info = await FS.getInfoAsync(uri);
        if (!info.exists || info.size !== file.size) throw new Error('Файл не удалось полностью сохранить. Выберите его заново.');
        result.push({ ...file, uri });
      }
      return result;
    },
    async verify(files) {
      for (const file of files) {
        const info = await FS.getInfoAsync(file.uri);
        if (!file.uri.startsWith(root + 'files/') || !info.exists || info.size !== file.size) { const error = new Error('File missing'); error.code = 'LOCAL_FILE_MISSING'; throw error; }
      }
    },
    async clear() { await FS.deleteAsync(root, { idempotent: true }); },
  };
}
