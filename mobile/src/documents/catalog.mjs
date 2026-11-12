export function matchesDocument(document, search) {
  const value = search.trim().toLocaleLowerCase('ru-RU');
  const text = [document.title, document.document_number, document.publication_year,
    ...(document.folder_path || []).map((folder) => folder.name)]
    .filter((part) => part != null).join(' ').toLocaleLowerCase('ru-RU');
  return !value || value.split(/\s+/u).every((word) => text.includes(word));
}

// Derive folders only from authorized documents; never render an empty private branch.
export function documentTree(documents) {
  const root = { items: [], children: [] };
  for (const document of documents) {
    let node = root;
    for (const folder of (document.folder_path || []).slice(0, 6)) {
      let child = node.children.find((item) => item.id === folder.id);
      if (!child) {
        child = { id: folder.id, name: folder.name, items: [], children: [] };
        node.children.push(child);
      }
      node = child;
    }
    node.items.push(document);
  }
  const sort = (node) => {
    node.children.sort((a, b) => a.name.localeCompare(b.name, 'ru'));
    node.children.forEach(sort);
  };
  sort(root);
  return root;
}
