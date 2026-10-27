import { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { IconLabel } from '../components/AppIcon';
import { useJournal } from '../journal/useJournal';
import s from '../discipline/styles';
import TaskEditor, { useTaskEditor } from '../journal/TaskEditor';

const date = value => value ? value.split('-').reverse().join('.') : 'не указана';
function Feedback({ query }) {
  return <>{query.busy && !query.data && <ActivityIndicator color="#fb923c" />}{!!query.error && <><Text accessibilityRole="alert" style={s.error}>{query.error}</Text><Pressable style={s.secondary} onPress={query.refresh}><Text style={s.buttonText}>Повторить загрузку</Text></Pressable></>}</>;
}
const stats = { column_members: 'Сотрудников в колонне', tasks_pending: 'Задач к выполнению', tasks_done: 'Выполнено задач', documents_count: 'Документов журнала', norms_completed: 'Нормативов с отметкой выполнения', reports_written: 'Выполнено рапортов' };
export default function JournalScreen({ navigation }) {
  const query = useJournal('/journal');
  const { data } = query;
  return <ScrollView style={s.page} contentContainerStyle={s.content} refreshControl={<RefreshControl refreshing={query.busy} tintColor="#fb923c" onRefresh={query.refresh} />}>
    <Text style={s.title}>Журнал инструктора</Text><Feedback query={query} />
    {data && <><Text style={s.heading}>Колонна: {data.column || 'не указана'}</Text>{!!data.error && <Text style={s.error}>{data.error}</Text>}
      <Text style={s.muted}>Сводка журнала совпадает с веб-версией. Поручения руководителя находятся в отдельном разделе «Поручения».</Text>
      {Object.entries(stats).map(([key, label]) => <View key={key} style={s.card}><Text style={s.muted}>{label}</Text><Text style={s.heading}>{data.stats[key]}{key === 'norms_completed' ? '%' : ''}</Text></View>)}
      {!!data.column && <><Pressable style={s.button} onPress={() => navigation.navigate('JournalTasks')}><IconLabel name="tasks" textStyle={s.buttonText}>Личные задачи</IconLabel></Pressable><Pressable style={s.secondary} onPress={() => navigation.navigate('JournalCrew')}><IconLabel name="team" textStyle={s.buttonText}>Бригада и нормативы</IconLabel></Pressable></>}
    </>}
  </ScrollView>;
}
export function JournalTasksScreen() {
  const [status, setStatus] = useState('');
  const query = useJournal('/journal/tasks', 'tasks', status);
  const editor = useTaskEditor(query.refresh);
  return <FlatList style={s.page} contentContainerStyle={s.content} data={query.data?.tasks || []} keyExtractor={item => item.id}
    refreshControl={<RefreshControl refreshing={query.busy} tintColor="#fb923c" onRefresh={query.refresh} />}
    ListHeaderComponent={<><Text style={s.title}>Личные задачи журнала</Text><Text style={s.muted}>Личные задачи общие с веб-журналом. Поручения руководителя находятся в отдельном разделе.</Text><View style={s.row}>{[['', 'Все'], ['pending', 'К выполнению'], ['done', 'Выполнены']].map(([key, label]) => <Pressable key={key} style={[s.chip, key === status && s.selected]} onPress={() => setStatus(key)} accessibilityRole="button" accessibilityState={{ selected: key === status }}><Text style={s.body}>{label}</Text></Pressable>)}</View><Feedback query={query} /><TaskEditor editor={editor} /></>}
    ListEmptyComponent={!query.busy && !query.error ? <Text style={s.muted}>Задач по выбранному фильтру нет.</Text> : null}
    renderItem={({ item }) => <View style={s.card}><Text selectable style={s.heading}>{item.title}</Text><Text style={item.status === 'done' ? s.success : s.accent}>{item.status === 'done' ? 'Выполнена' : 'К выполнению'}</Text><Text style={s.muted}>Дата: {date(item.due_date)}</Text>{item.is_overdue && <Text style={s.error}>Просрочено</Text>}{!!item.description && <Text selectable style={s.body}>{item.description}</Text>}<View style={s.row}>{[['update', 'Изменить'], ...(item.status === 'pending' ? [['complete', 'Выполнено']] : []), ['delete', 'Удалить']].map(([action, label]) => <Pressable key={action} accessibilityLabel={`${label}: ${item.title}`} style={s.secondary} disabled={!!editor.entry.pending || editor.busy} onPress={() => editor.act(action, item)}><Text style={s.buttonText}>{label}</Text></Pressable>)}</View></View>}
    ListFooterComponent={query.data?.pagination.next_page ? <Pressable style={s.secondary} disabled={query.busy} onPress={query.more}><Text style={s.buttonText}>Загрузить ещё</Text></Pressable> : null} />;
}
export function JournalCrewScreen() {
  const query = useJournal('/journal/crew', 'crew');
  return <FlatList style={s.page} contentContainerStyle={s.content} data={query.data?.crew || []} keyExtractor={item => item.id}
    refreshControl={<RefreshControl refreshing={query.busy} tintColor="#fb923c" onRefresh={query.refresh} />}
    ListHeaderComponent={<><Text style={s.title}>Бригада и нормативы</Text><Text style={s.muted}>Машинисты вашей колонны. Показаны нормативы, закреплённые за вами; отсутствие записи обозначено явно. Изменение дат выполняется в веб-журнале.</Text><Feedback query={query} /></>}
    ListEmptyComponent={!query.busy && !query.error ? <Text style={s.muted}>В вашей колонне нет машинистов.</Text> : null}
    renderItem={({ item }) => <View style={s.card}><Text style={s.heading}>{item.name}</Text><Text style={s.muted}>Класс: {item.class || 'не указан'}</Text>{item.normatives.map(norm => <View key={norm.type} style={{ marginTop: 12 }}><Text style={s.body}>{norm.label}</Text>{norm.recorded ? <><Text style={s.muted}>Выполнен: {date(norm.last_date)} · следующий: {date(norm.next_date)}</Text>{norm.is_overdue && <Text style={s.error}>Просрочено</Text>}</> : <Text style={s.muted}>Запись в журнале отсутствует</Text>}</View>)}</View>}
    ListFooterComponent={query.data?.pagination.next_page ? <Pressable style={s.secondary} disabled={query.busy} onPress={query.more}><Text style={s.buttonText}>Загрузить ещё</Text></Pressable> : null} />;
}
