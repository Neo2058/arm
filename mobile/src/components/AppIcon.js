import { StyleSheet, Text, View } from 'react-native';
import Svg, { Path } from 'react-native-svg';

// One outline vocabulary, shared by navigation, lists and actions. No remote fonts.
const paths = {
  documents: 'M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z M14 3v6h6 M8 13h8 M8 17h5',
  quiz: 'M9 4H6a2 2 0 0 0-2 2v14h16V6a2 2 0 0 0-2-2h-3 M9 3h6v4H9z M8 12l2 2 5-4 M8 18h8',
  train: 'M6 16V6c0-3 12-3 12 0v10a3 3 0 0 1-3 3H9a3 3 0 0 1-3-3z M6 11h12 M12 4v7 M8 15h1 M15 15h1 M8 19l-3 3 M16 19l3 3 M8 22h8',
  training: 'M12 5c-3-2-6-2-10-1v15c4-1 7-1 10 1 3-2 6-2 10-1V4c-4-1-7-1-10 1z M12 5v15',
  profile: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0 M4 21v-2a7 7 0 0 1 16 0v2',
  tasks: 'M9 4H5v17h14V4h-4 M9 3h6v4H9z M8 12l1 1 2-2 M13 12h3 M8 17l1 1 2-2 M13 17h3',
  phone: 'M7 3H3v4c0 8 6 14 14 14h4v-4l-5-2-2 2a13 13 0 0 1-7-7l2-2z',
  search: 'M16 10a6 6 0 1 1-12 0 6 6 0 0 1 12 0 M15 15l6 6',
  refresh: 'M20 8a8 8 0 1 0 0 8 M20 3v5h-5',
  logout: 'M9 4H4v16h5 M10 12h11 M17 8l4 4-4 4',
  login: 'M15 4h5v16h-5 M3 12h11 M10 8l4 4-4 4',
  shield: 'M12 2l8 3v6c0 5-4 8-8 11-4-3-8-6-8-11V5z M9 12l2 2 4-4',
  send: 'M22 2 9 15 M22 2l-7 20-6-7-7-6z',
  attach: 'M8 12l7-7a3 3 0 0 1 4 4L9 19a5 5 0 0 1-7-7L13 1 M5 15l9-9',
  play: 'M8 4l12 8-12 8z',
  pause: 'M8 4v16 M16 4v16',
  audio: 'M4 14V9a8 8 0 0 1 16 0v5 M4 12H2v8h5v-8z M20 12h2v8h-5v-8z',
  video: 'M3 5h13v14H3z M16 9l6-4v14l-6-4',
  folder: 'M3 6V4h6l2 3h10v14H3z',
  chevron: 'M9 5l7 7-7 7',
  team: 'M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0 M6 21v-2a6 6 0 0 1 12 0v2 M3 10a3 3 0 0 1 2-5 M21 10a3 3 0 0 0-2-5 M2 20v-3l2-2 M22 20v-3l-2-2',
  journal: 'M5 3h15v18H5z M2 7h6 M2 12h6 M2 17h6 M11 8h5 M11 12h5',
  close: 'M6 6l12 12 M18 6 6 18',
};

export const TAB_ICONS = { DocsTab: 'documents', QuizTab: 'quiz', NaryadTab: 'train', TrainTab: 'training', ProfileTab: 'profile', DisciplineTab: 'tasks' };

export default function AppIcon({ name, size = 22, color = '#fb923c', strokeWidth = 1.8 }) {
  return <Svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke={color} strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round" accessible={false} importantForAccessibility="no-hide-descendants">
    <Path d={paths[name] || paths.documents} />
  </Svg>;
}

export function IconLabel({ name, children, textStyle, color = '#fff', size = 19, style }) {
  return <View style={[styles.label, style]}><AppIcon name={name} size={size} color={color} /><Text style={[styles.text, { color }, textStyle]}>{children}</Text></View>;
}

export function IconHeading({ name, children, textStyle }) {
  return <View style={styles.heading}><View style={styles.tile}><AppIcon name={name} size={23} /></View><Text style={[styles.title, textStyle]}>{children}</Text></View>;
}

const styles = StyleSheet.create({
  label: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 9 },
  text: { fontSize: 14, fontWeight: '600', flexShrink: 1 },
  heading: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  tile: { width: 42, height: 42, backgroundColor: '#2b211c', borderRadius: 13, alignItems: 'center', justifyContent: 'center' },
  title: { color: '#fff', fontSize: 17, fontWeight: '600', flex: 1 },
});
