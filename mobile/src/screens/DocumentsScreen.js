import { useCallback, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { client } from '../api';
import { documentTree, matchesDocument } from '../documents/catalog.mjs';
import { IconHeading } from '../components/AppIcon';

function FolderNode({ node, navigation, searching }) {
  const [open, setOpen] = useState(false);
  return <View style={{ marginLeft: 12 }}>
    <Pressable accessibilityRole="button" accessibilityState={{ expanded: searching || open }} onPress={() => setOpen(!open)} style={{ paddingVertical: 12 }}>
      <Text style={styles.cat}>{searching || open ? '▾' : '▸'} {node.name}</Text>
    </Pressable>
    {(searching || open) && <TreeNode node={node} navigation={navigation} searching={searching} />}
  </View>;
}
function TreeNode({ node, navigation, searching }) {
  return <>
    {node.items.map((doc) => <Pressable key={`doc-${doc.id}`} style={styles.row} onPress={() => navigation.navigate('DocumentView', { id: doc.id, title: doc.title })}>
      <IconHeading name="documents" textStyle={styles.doc}>{doc.title}</IconHeading>
      <Text style={styles.date}>{[doc.revision_label ? `Редакция ${doc.revision_label}` : null, doc.document_number, doc.publication_year, doc.updated_at].filter(Boolean).join(' · ')}</Text>
    </Pressable>)}
    {node.children.map((child) => <FolderNode key={child.id} node={child} navigation={navigation} searching={searching} />)}
  </>;
}

export default function DocumentsScreen({ navigation }) {
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const filtered = useMemo(() => categories.map((category) => ({ ...category, items: category.items.filter((doc) => matchesDocument(doc, search)) })).filter((category) => category.items.length), [categories, search]);

  const load = async () => {
    setError('');
    try {
      const { data } = await client.get('/documents');
      setCategories(data.categories || []);
    } catch {
      setError('Не удалось загрузить документы');
    } finally {
      setLoading(false);
    }
  };

  useFocusEffect(
    useCallback(() => {
      load();
    }, [])
  );

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color="#fb923c" />
      </View>
    );
  }

  return (
    <FlatList
      style={styles.list}
      contentContainerStyle={{ padding: 16, paddingBottom: 40 }}
      data={filtered}
      keyExtractor={(item) => item.name}
      refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}
      ListHeaderComponent={<View><TextInput accessibilityLabel="Поиск документов" value={search} onChangeText={setSearch} placeholder="Название, номер, год или папка" placeholderTextColor="#9ca3af" style={{ color: '#fff', padding: 14, backgroundColor: '#151b24', borderRadius: 12, marginBottom: 16 }} />{error ? <Text style={styles.muted}>{error}</Text> : null}</View>}
      ListEmptyComponent={<Text style={styles.muted}>{error || 'Документов нет'}</Text>}
      renderItem={({ item }) => (
        <View style={styles.card}>
          <Text style={styles.cat}>{item.name}</Text>
          <TreeNode node={documentTree(item.items)} navigation={navigation} searching={Boolean(search.trim())} />
        </View>
      )}
    />
  );
}

const styles = StyleSheet.create({
  list: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center' },
  card: { backgroundColor: '#151b24', borderRadius: 16, padding: 14, marginBottom: 12 },
  cat: { color: '#fb923c', fontWeight: '700', marginBottom: 8 },
  row: { paddingVertical: 10, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: '#1f2937' },
  doc: { color: '#fff', fontSize: 16 },
  date: { color: '#6b7280', marginTop: 4, fontSize: 12 },
  muted: { color: '#9ca3af', textAlign: 'center', marginTop: 40 },
});
