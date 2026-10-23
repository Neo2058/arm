import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { client } from '../api';

function formatTime(seconds) {
  const safe = Math.max(0, seconds);
  const m = Math.floor(safe / 60);
  const s = safe % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

export default function QuizPlayScreen({ route, navigation }) {
  const { id, title } = route.params;
  const [quiz, setQuiz] = useState(null);
  const [error, setError] = useState('');
  const [index, setIndex] = useState(0);
  const [answers, setAnswers] = useState({});
  const [timeLeft, setTimeLeft] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState(null);
  const startedAt = useRef(Date.now());
  const submittingRef = useRef(false);
  const answersRef = useRef({});
  const timeLeftRef = useRef(null);

  useEffect(() => {
    answersRef.current = answers;
  }, [answers]);
  useEffect(() => {
    timeLeftRef.current = timeLeft;
  }, [timeLeft]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const { data } = await client.get(`/quizzes/${id}`);
        if (cancelled) return;
        setQuiz(data);
        const limit = Number(data.time_limit) > 0 ? Number(data.time_limit) * 60 : null;
        setTimeLeft(limit);
        timeLeftRef.current = limit;
        startedAt.current = Date.now();
      } catch {
        if (!cancelled) setError('Тест недоступен');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [id]);

  const finish = useCallback(async () => {
    if (submittingRef.current || !quiz) return;
    submittingRef.current = true;
    setSubmitting(true);
    const spent = Math.max(
      0,
      timeLeftRef.current == null
        ? Math.round((Date.now() - startedAt.current) / 1000)
        : Number(quiz.time_limit) * 60 - timeLeftRef.current
    );
    try {
      const { data } = await client.post(`/quizzes/${id}/results`, {
        time_spent: spent,
        answers_log: answersRef.current,
      });
      setResult(data);
    } catch {
      submittingRef.current = false;
      setSubmitting(false);
      Alert.alert('Ошибка', 'Не удалось сохранить результат. Попробуйте ещё раз.');
    }
  }, [id, quiz]);

  useEffect(() => {
    if (!quiz || result || timeLeft == null) return;
    if (timeLeft <= 0) {
      finish();
      return;
    }
    const timer = setInterval(() => setTimeLeft((prev) => (prev == null ? prev : prev - 1)), 1000);
    return () => clearInterval(timer);
  }, [quiz, result, timeLeft, finish]);

  useFocusEffect(
    useCallback(() => {
      const before = navigation.addListener('beforeRemove', (event) => {
        if (result || submittingRef.current) return;
        event.preventDefault();
        Alert.alert('Выйти из теста?', 'Ответы не будут сохранены, пока тест не завершён.', [
          { text: 'Остаться', style: 'cancel' },
          { text: 'Выйти', style: 'destructive', onPress: () => navigation.dispatch(event.data.action) },
        ]);
      });
      return before;
    }, [navigation, result])
  );

  if (error) {
    return (
      <View style={styles.center}>
        <Text style={styles.error}>{error}</Text>
      </View>
    );
  }

  if (!quiz) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color="#fb923c" />
        <Text style={styles.muted}>{title}</Text>
      </View>
    );
  }

  if (!Array.isArray(quiz.questions) || quiz.questions.length === 0) {
    return (
      <View style={styles.center}>
        <Text style={styles.error}>В тесте нет вопросов</Text>
      </View>
    );
  }

  if (result) {
    return (
      <View style={styles.center}>
        <Text style={styles.doneTitle}>Тест завершён</Text>
        <Text style={styles.score}>
          {result.score} из {result.total_questions}
        </Text>
        <Text style={styles.muted}>Ответы отправлены инструктору</Text>
        <Pressable style={styles.primary} onPress={() => navigation.goBack()}>
          <Text style={styles.primaryText}>К списку тестов</Text>
        </Pressable>
      </View>
    );
  }

  const question = quiz.questions[index];
  const selected = answers[question.id];
  const last = index === quiz.questions.length - 1;

  return (
    <ScrollView style={styles.wrap} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={styles.top}>
        <Text style={styles.progress}>
          Вопрос {index + 1} из {quiz.questions.length}
        </Text>
        {timeLeft != null ? <Text style={styles.timer}>{formatTime(timeLeft)}</Text> : null}
      </View>
      <View style={styles.bar}>
        <View style={[styles.barFill, { width: `${((index + 1) / quiz.questions.length) * 100}%` }]} />
      </View>
      <Text style={styles.question}>{question.question_text}</Text>
      {(question.references || []).map((ref, idx) => (
        <Text key={`${ref.document_id || 0}-${idx}`} style={styles.ref}>
          {ref.anchor_text}
          {ref.page ? ` · стр. ${ref.page}` : ''}
        </Text>
      ))}
      {question.answers.map((answer) => (
        <Pressable
          key={answer.id}
          style={[styles.answer, selected === answer.id && styles.answerOn]}
          onPress={() => setAnswers((prev) => ({ ...prev, [question.id]: answer.id }))}
        >
          <Text style={styles.answerText}>{answer.answer_text}</Text>
        </Pressable>
      ))}
      <Pressable
        style={[styles.primary, (!selected || submitting) && styles.disabled]}
        disabled={!selected || submitting}
        onPress={() => {
          if (last) finish();
          else setIndex((prev) => prev + 1);
        }}
      >
        <Text style={styles.primaryText}>{submitting ? 'Сохранение…' : last ? 'Завершить тест' : 'Далее'}</Text>
      </Pressable>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center', alignItems: 'center', padding: 24 },
  top: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  progress: { color: '#9ca3af' },
  timer: { color: '#fb923c', fontVariant: ['tabular-nums'], fontWeight: '700' },
  bar: { height: 4, backgroundColor: '#1f2937', borderRadius: 4, marginTop: 12, marginBottom: 20, overflow: 'hidden' },
  barFill: { height: 4, backgroundColor: '#ea580c' },
  question: { color: '#fff', fontSize: 22, fontWeight: '700', marginBottom: 16 },
  ref: { color: '#fb923c', marginBottom: 8, fontSize: 13 },
  answer: { backgroundColor: '#151b24', borderRadius: 16, padding: 16, marginBottom: 10, borderWidth: 1, borderColor: '#1f2937' },
  answerOn: { borderColor: '#fb923c', backgroundColor: '#431407' },
  answerText: { color: '#fff', fontSize: 16 },
  primary: { marginTop: 20, backgroundColor: '#ea580c', borderRadius: 14, padding: 16, alignItems: 'center' },
  primaryText: { color: '#fff', fontWeight: '700', fontSize: 16 },
  disabled: { opacity: 0.45 },
  doneTitle: { color: '#fff', fontSize: 24, fontWeight: '700' },
  score: { color: '#fb923c', fontSize: 28, fontWeight: '800', marginTop: 12 },
  muted: { color: '#9ca3af', marginTop: 12, textAlign: 'center' },
  error: { color: '#f87171', textAlign: 'center' },
});
