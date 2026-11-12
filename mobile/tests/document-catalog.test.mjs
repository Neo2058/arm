import test from 'node:test';
import assert from 'node:assert/strict';
import { documentTree, matchesDocument } from '../src/documents/catalog.mjs';

test('old flat response and authorized nested items share one tree without copies', () => {
  const tree = documentTree([
    { id: 1, title: 'Прямой' },
    { id: 2, title: 'Электрика', folder_path: [{ id: 10, name: '2026' }, { id: 11, name: 'Обучение' }] },
    { id: 3, title: 'Ещё документ', folder_path: [{ id: 10, name: '2026' }] },
  ]);
  assert.deepEqual(tree.items.map((item) => item.id), [1]);
  assert.equal(tree.children.length, 1);
  assert.deepEqual(tree.children[0].items.map((item) => item.id), [3]);
  assert.equal(tree.children[0].children[0].items[0].id, 2);
});

test('search matches title, number, publication year and folder without mutating input', () => {
  const document = { id: 1, title: 'Электробезопасность', document_number: 'И-123', publication_year: 2026, folder_path: [{ id: 10, name: 'Обучение' }] };
  for (const query of ['электро', 'и-123', '2026', 'ОБУЧЕНИЕ', '2026 и-123', 'электро 2026', '  ']) assert.equal(matchesDocument(document, query), true);
  assert.equal(matchesDocument(document, '2025'), false);
  assert.equal(documentTree([document]).children[0].name, 'Обучение');
  assert.equal(document.folder_path.length, 1);
});
