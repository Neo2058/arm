import React from 'react';
import { documentTree } from '../../../mobile/src/documents/catalog.mjs';

export default function CatalogTree({ documents, selectedId, onSelect, searching }) {
  const render = (node) => <>
    {node.items.map((doc) => <button key={`doc-${doc.id}`} onClick={() => onSelect(doc)}
      className={`w-full rounded-xl p-3 text-left ${selectedId === doc.id ? 'bg-orange-400/10 border border-orange-400/30' : 'hover:bg-white/5'}`}>
      <span className="block text-sm break-words">{doc.title}</span>
      {(doc.document_number || doc.publication_year) && <span className="block text-xs text-zinc-400 mt-1">{[doc.document_number, doc.publication_year].filter(Boolean).join(' · ')}</span>}
    </button>)}
    {node.children.map((child) => <details key={child.id} open={searching || undefined} className="ml-2 border-l border-white/10 pl-2">
      <summary className="cursor-pointer p-3 text-orange-300 break-words">{child.name}</summary>
      {render(child)}
    </details>)}
  </>;
  return render(documentTree(documents));
}
