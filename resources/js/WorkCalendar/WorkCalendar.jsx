import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { motion, AnimatePresence } from 'framer-motion';
import {
    Calendar,
    ChevronRight,
    ChevronLeft,
    Clock,
    Wallet,
    X,
    Briefcase,
    Coffee,
    AlertCircle,
    MapPin,
} from 'lucide-react';

/* ===================== CONSTANTS ===================== */

const HOURLY_RATE = 450;

const weekdays = [
    'Пн',
    'Вт',
    'Ср',
    'Чт',
    'Пт',
    'Сб',
    'Вс'
];

/* ===================== COMPONENT ===================== */

export default function WorkCalendar() {

    /* ================= STATE ================= */

    const [currentDate, setCurrentDate] = useState(new Date());
    const [shifts, setShifts] = useState([]);
    const [catalog, setCatalog] = useState([]);

    const [selectedDate, setSelectedDate] = useState(null);
    const [open, setOpen] = useState(false);

    const [mode, setMode] = useState('work'); // work | deviation

    /* WORK */
    const [routeId, setRouteId] = useState('');
    const [startAt, setStartAt] = useState('');
    const [endAt, setEndAt] = useState('');
    const [startLocation, setStartLocation] = useState('');
    const [endLocation, setEndLocation] = useState('');
    const [breakMin, setBreakMin] = useState(0);
    const [activeShift, setActiveShift] = useState(null);

    /* DEVIATION */
    const [deviationsCatalog, setDeviationsCatalog] = useState([]);
    const [selectedDeviationId, setSelectedDeviationId] = useState('');


    /* ================= LOAD ================= */

    const load = async () => {
        try {
            // Данные прилетают в переменную 'res'
            const res = await axios.get('/api/work-shifts', {
                params: {
                    month: currentDate.getMonth() + 1,
                    year: currentDate.getFullYear(),
                }
            });

            // ИСПРАВЛЕНО: Читаем строго из res.data
            if (res && res.data) {
                setShifts(res.data.shifts || []);
                setCatalog(res.data.catalog || []);
                setDeviationsCatalog(res.data.deviations_catalog || []);
            }
        } catch (e) {
            console.error("Ошибка загрузки данных смен:", e);
        }
    };

    useEffect(() => {
        load();
    }, [currentDate]);

    /* ================= ROUTE AUTOFILL ================= */

    const onRouteSelect = (id) => {

        setRouteId(id);

        const route = catalog.find(r => r.id === Number(id));
        if (!route) return;

        const date = selectedDate;

        setStartLocation(route.start_location);
        setEndLocation(route.end_location);
        setBreakMin(route.default_break_duration || 0);

        setStartAt(`${date}T${route.default_start_time.slice(0,5)}`);
        setEndAt(`${date}T${route.default_end_time.slice(0,5)}`);
    };

    /* ================= SHIFT CALC ENGINE ================= */

    const preview = useMemo(() => {
        if (!selectedDate) return null;

        const route = activeShift?.route || null;

        if (mode === 'work') {
            if (!startAt || !endAt) return null;

            const start = new Date(startAt);
            const end = new Date(endAt);

            let hours = (end - start) / 3600000;
            hours = Math.max(hours - breakMin / 60, 0);

            return {
                label: 'Рабочая смена',
                tasks: route?.technological_tasks ?? 'План работ не задан',
                routeNumber: route?.route_number ?? '—',
                hours,
                money: hours * HOURLY_RATE,
            };
        }

        // ИСПРАВЛЕНО: Ищем выбранное отвлечение в правильном массиве по правильному ID
        const dev = Array.isArray(deviationsCatalog)
            ? deviationsCatalog.find(d => Number(d.id) === Number(selectedDeviationId))
            : null;

        if (!dev) return null;

        // Рассчитываем деньги на основе ЖИВОЙ тарифной ставки этого отвлечения из админки
        const devHours = Number(dev.default_minutes || 480) / 60; // Обычно 8 часов
        const devRate = parseFloat(dev.hourly_rate || 0);

        return {
            label: dev.name,
            routeNumber: '—', // На отвлечении маршрута нет
            tasks: 'Отвлечение от работы по графику депо',
            hours: devHours,
            money: devHours * devRate,
        };

        // ИСПРАВЛЕНО: Обновили зависимости хука, добавив selectedDeviationId
    }, [mode, startAt, endAt, breakMin, selectedDeviationId, deviationsCatalog, activeShift]);

    /* ================= CALENDAR ================= */

    const days = useMemo(() => {

        const y = currentDate.getFullYear();
        const m = currentDate.getMonth();

        const first = new Date(y, m, 1);
        const last = new Date(y, m + 1, 0);
        const normalizeDate = (d) => d?.slice(0, 10);

        let offset = first.getDay() - 1;
        if (offset < 0) offset = 6;

        const arr = [];

        for (let i = 0; i < offset; i++) arr.push(null);

        for (let d = 1; d <= last.getDate(); d++) {

            const dateStr = `${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
            const shift = shifts.find(
                s => normalizeDate(s.shift_date) === dateStr
            );
            arr.push({ day: d, dateStr, shift });
        }

        return arr;

    }, [currentDate, shifts]);

    const monthStats = useMemo(() => {

        let totalMinutes = 0;
        let totalMoney = 0;

        for (const s of shifts) {
            totalMinutes += Number(s.total_minutes || 0);
            totalMoney += Number(s.estimated_earnings || 0);
        }

        const hours = totalMinutes / 60;

        return {
            hours,
            money: totalMoney
        };

    }, [shifts]);

    /* ================= OPEN DAY ================= */

    const openDay = (d) => {
        setSelectedDate(d.dateStr);
        setActiveShift(d.shift || null);
        setOpen(true);

        if (d.shift) {
            setMode(d.shift.type);

            if (d.shift.type === 'work') {
                setRouteId(d.shift.route_id || '');
                setStartAt(d.shift.started_at?.slice(0,16) || '');
                setEndAt(d.shift.ended_at?.slice(0,16) || '');
                setStartLocation(d.shift.start_location || '');
                setEndLocation(d.shift.end_location || '');
                setBreakMin(d.shift.break_duration || 0);
                setSelectedDeviationId(''); // Сбрасываем отвлечение, так как это работа
            } else {
                // ИСПРАВЛЕНО: Записываем ID выбранного отвлечения в правильный стейт, принудительно в строку
                setSelectedDeviationId(d.shift.deviation_id ? String(d.shift.deviation_id) : '');

                // Сбрасываем поля работы
                setRouteId('');
                setStartAt('');
                setEndAt('');
            }
        } else {
            // СБРОС ДЛЯ НОВОГО (ПУСТОГО) ДНЯ
            setMode('work');
            setRouteId('');

            // ИСПРАВЛЕНО: Сбрасываем только ID ВЫБРАННОГО отвлечения!
            // Переменную setDeviationsCatalog БОЛЬШЕ ТУТ НЕ ТРОГАЕМ, чтобы справочник не стирался!
            setSelectedDeviationId('');

            setStartAt(`${d.dateStr}T08:00`);
            setEndAt(`${d.dateStr}T16:00`);
            setStartLocation('');
            setEndLocation('');
            setBreakMin(0);
        }
    };

    /* ================= SAVE ================= */

    const save = async (e) => {
        e.preventDefault();

        // Защита: если ID пустой, передаем null, чтобы база данных Postgres не ругалась
        const deviationIdToSend = selectedDeviationId ? Number(selectedDeviationId) : null;
        const routeIdToSend = routeId ? Number(routeId) : null;

        await axios.post('/api/work-shifts', {
            shift_date: selectedDate,
            type: mode,

            // ИСПРАВЛЕНО: Передаем ID конкретного маршрута, а не каталог
            route_id: mode === 'work' ? routeIdToSend : null,

            // ИСПРАВЛЕНО: Передаем ID выбранного отвлечения (selectedDeviationId), а не весь массив каталога!
            deviation_id: mode === 'deviation' ? deviationIdToSend : null,

            started_at: mode === 'work' ? startAt : null,
            ended_at: mode === 'work' ? endAt : null,

            start_location: mode === 'work' ? startLocation : null,
            end_location: mode === 'work' ? endLocation : null,

            break_duration: mode === 'work' ? breakMin : 0,
        });

        setOpen(false);
        load(); // Перезагружаем календарь для отображения изменений
    };


    /* ================= Day Colors ================= */

    const getDayColor = (shift) => {

        if (!shift) return '';

        if (shift.type === 'deviation') {

            switch (shift.deviation_type) {

                case 'sick':
                    return 'bg-blue-500';

                case 'training':
                    return 'bg-purple-500';

                case 'study_leave':
                    return 'bg-cyan-500';

                default:
                    return 'bg-zinc-500';
            }
        }

        const hours = (shift.total_minutes || 0) / 60;

        if (hours < 6) {
            return 'bg-green-500';
        }

        if (hours <= 8) {
            return 'bg-yellow-500';
        }

        return 'bg-red-500';
    };


    /* ================= Check Month ================= */

    const prevMonth = () => {
        setCurrentDate(
            new Date(
                currentDate.getFullYear(),
                currentDate.getMonth() - 1,
                1
            )
        );
    };

    const nextMonth = () => {
        setCurrentDate(
            new Date(
                currentDate.getFullYear(),
                currentDate.getMonth() + 1,
                1
            )
        );
    };

    const goToday = () => {
        setCurrentDate(new Date());
    };

    /* ================= UI ================= */

    return (
        <div className="p-6 text-white bg-[#0b1018] min-h-screen">

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

                {/* CALENDAR */}
                <div className="lg:col-span-2 p-4 rounded-2xl bg-white/5 border border-white/10">

                    <div className="flex items-center justify-between">

                        <div className="flex items-center gap-2">

                            <button
                                onClick={prevMonth}
                                className="p-2 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10"
                            >
                                <ChevronLeft size={18}/>
                            </button>

                            <Calendar size={20} className="text-orange-400"/>

                            <h2 className=" text-sm
                                            sm:text-base
                                            md:text-lg
                                            font-bold
                                            text-orange-400
                                            capitalize">

                                {currentDate.toLocaleString('ru-RU', {
                                    month: 'numeric',
                                    year: 'numeric',
                                })}
                            </h2>

                            <button
                                onClick={nextMonth}
                                className="p-2 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10"
                            >
                                <ChevronRight size={18}/>
                            </button>

                        </div>

                        <button
                            onClick={goToday}
                            className="px-3 py-2 text-sm rounded-xl bg-orange-500 hover:bg-orange-600"
                        >
                            Сегодня
                        </button>

                    </div>

                    {/*<div className="grid grid-cols-7 gap-1 sm:gap-2">*/}

                    {/*    {days.map((d,i) => {*/}

                    {/*        if (!d) return <div key={i} className=" h-10*/}
                    {/*                                                sm:h-12*/}
                    {/*                                                md:h-14" />;*/}
                    {/*        const today = d.dateStr === new Date().toISOString().split('T')[0];*/}

                    {/*        return (*/}
                    {/*            <div*/}
                    {/*                key={d.dateStr}*/}
                    {/*                onClick={() => openDay(d)}*/}
                    {/*                className={`h-14 rounded-xl flex items-center justify-center cursor-pointer border*/}
                    {/*                ${today ? 'border-orange-500 bg-orange-500/10' : 'border-white/10 bg-white/5'}`}*/}
                    {/*            >*/}
                    {/*                {d.day}*/}
                    {/*            </div>*/}
                    {/*        );*/}
                    {/*    })}*/}

                    {/*</div>*/}

                    <div className="grid grid-cols-7 gap-1 sm:gap-2">

                        {/* Заголовок дней недели */}
                        {weekdays.map(day => (
                            <div
                                key={day}
                                className="
                                            text-center
                                            text-xs
                                            text-zinc-500
                                            font-medium
                                            pb-2
                                          "
                            >
                                {day}
                            </div>
                        ))}

                        {/* Дни месяца */}
                        {days.map((d, i) => {

                            if (!d) {
                                return (
                                    <div
                                        key={`empty-${i}`}
                                        className="h-10 sm:h-12 md:h-14"
                                    />
                                );
                            }

                            const today =
                                d.dateStr === new Date().toISOString().split('T')[0];

                            return (
                                <div
                                    key={d.dateStr}
                                    onClick={() => openDay(d)}
                                    className={`
                                                h-10 sm:h-12 md:h-14
                                                rounded-xl
                                                flex
                                                items-center
                                                justify-center
                                                cursor-pointer
                                                border
                                                transition

                                                ${
                                                    today
                                                        ? 'border-orange-500 bg-orange-500/10'
                                                        : 'border-white/10 bg-white/5'
                                                }
                                    `}
                                >
                                    <div
                                        className="
                                                    flex
                                                    flex-col
                                                    items-center
                                                    justify-center
                                                    h-full
                                                "
                                    >

                                    <span className="font-medium">
                                        {d.day}
                                    </span>

                                        {d.shift && (

                                            <div
                                                className={`
                                                            w-2
                                                            h-2
                                                            rounded-full
                                                            mt-1
                                                            ${getDayColor(d.shift)}
                                                        `}
                                            />

                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* COLOR LEGENDS */}
                <div className="mt-4 flex flex-wrap gap-3 text-xs text-zinc-400">

                    <div className="flex items-center gap-1">
                        <div className="w-2 h-2 rounded-full bg-green-500" />
                        <span>&lt; 6ч</span>
                    </div>

                    <div className="flex items-center gap-1">
                        <div className="w-2 h-2 rounded-full bg-yellow-500" />
                        <span>6–8ч</span>
                    </div>

                    <div className="flex items-center gap-1">
                        <div className="w-2 h-2 rounded-full bg-red-500" />
                        <span>&gt; 8ч</span>
                    </div>

                    <div className="flex items-center gap-1">
                        <div className="w-2 h-2 rounded-full bg-blue-500" />
                        <span>Больничный</span>
                    </div>

                    <div className="flex items-center gap-1">
                        <div className="w-2 h-2 rounded-full bg-purple-500" />
                        <span>Повышение квалификации</span>
                    </div>

                    <div className="flex items-center gap-1">
                        <div className="w-2 h-2 rounded-full bg-cyan-500" />
                        <span>Учебный отпуск</span>
                    </div>

                </div>

                {/* WIDGETS */}
                <div className="space-y-3">

                    <Widget
                        icon={<Clock/>}
                        title="Preview"
                        value={preview?.label || '-'}
                    />
                    <Widget
                        icon={<Wallet/>}
                        title="Money"
                        value={preview ? `${preview.money.toFixed(2)} ₽` : '-'}
                    />

                    <Widget
                        icon={<Briefcase/>}
                        title="Hours"
                        value={preview ? `${preview.hours.toFixed(1)}h` : '-'}
                    />

                    <Widget
                        icon={<Clock />}
                        title="Отработано за месяц"
                        value={`${monthStats.hours.toFixed(1)} ч.`}
                    />

                    <Widget
                        icon={<Wallet />}
                        title="Заработано за месяц"
                        value={`${monthStats.money.toLocaleString('ru-RU', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        })} ₽`}
                    />

                </div>

            </div>

            {/* MODAL */}
            <AnimatePresence>

                {open && (

                    <motion.div
                        className="
                                    fixed inset-0
                                    z-50
                                    bg-black/80
                                    overflow-y-auto
                                    p-4
                                    "
                        onClick={() => setOpen(false)}
                    >

                        <motion.form
                            onClick={e => e.stopPropagation()}
                            onSubmit={save}
                            initial={{ scale: 0.95 }}
                            animate={{ scale: 1 }}
                            exit={{ scale: 0.95 }}
                            className="bg-[#0b1018]
                                        w-full
                                        max-w-xl
                                        mx-auto
                                        mt-10
                                        mb-10
                                        p-4
                                        sm:p-6
                                        rounded-2xl
                                        border
                                        border-white/10"
                            >

                            <div className="flex justify-between mb-4">

                                <h3 className="font-bold">
                                    {selectedDate}
                                </h3>

                                <button type="button" onClick={() => setOpen(false)}>
                                    <X/>
                                </button>

                            </div>

                            {/* MODE SWITCH */}
                            <div className="flex gap-2 mb-4">
                                <button type="button"
                                        onClick={() => setMode('work')}
                                        className={`px-3 py-1 rounded ${mode==='work'?'bg-orange-500':'bg-white/10'}`}>
                                    Работа
                                </button>

                                <button type="button"
                                        onClick={() => setMode('deviation')}
                                        className={`px-3 py-1 rounded ${mode==='deviation'?'bg-cyan-500':'bg-white/10'}`}>
                                    Отвлечение
                                </button>
                            </div>

                            {/* WORK (Отображается только на табе "Работа") */}
                            {mode === 'work' && (
                                <div className="space-y-2">
                                    <select
                                        value={routeId}
                                        onChange={(e)=>onRouteSelect(e.target.value)}
                                        className="w-full p-2 bg-white/5 rounded"
                                    >
                                        <option value="">Маршрут</option>
                                        {catalog.map(r=>(
                                            <option key={r.id} value={r.id}>
                                                {r.route_number}
                                            </option>
                                        ))}
                                    </select>

                                    <input type="datetime-local"
                                           value={startAt}
                                           onChange={e => setStartAt(e.target.value)}
                                           className=" w-full h-12 bg-white/5 border border-white/10 rounded-xl px-3" />
                                    <input type="datetime-local"
                                           value={startAt}
                                           onChange={e => setStartAt(e.target.value)}
                                           className=" w-full h-12 bg-white/5 border border-white/10 rounded-xl px-3" />
                                </div>
                            )}

                            {/* DEVIATION */}
                            {mode === 'deviation' && (
                                <div>
                                    <label className="text-[10px] uppercase font-bold text-zinc-500 block mb-1">Вид отвлечения от работы</label>
                                    <select
                                        value={String(selectedDeviationId || "")} // Принудительно приводим стейт селекта к строке
                                        onChange={(e) => setSelectedDeviationId(e.target.value)}
                                        className="w-full h-11 bg-black/30 border border-white/10 rounded-xl px-3 text-sm outline-none text-white focus:border-cyan-500/40"
                                    >
                                        <option value="" className="bg-[#0b1018]">-- Выберите причину из справочника --</option>
                                        {Array.isArray(deviationsCatalog) && deviationsCatalog.map(dev => (
                                            <option
                                                key={dev.id}
                                                value={String(dev.id)} // ИСПРАВЛЕНО: Приводим ID к строке для точного совпадения с e.target.value
                                                className="bg-[#0b1018]"
                                            >
                                                📍 {dev.name} ({parseFloat(dev.hourly_rate).toFixed(2)} руб/ч)
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}


                            {/* PREVIEW */}
                            {preview && (
                                <div className="mt-4 p-3 bg-white/5 rounded">
                                    <div className="font-bold text-orange-400">
                                        {preview?.label}
                                    </div>

                                    <div className="text-xs text-zinc-400">
                                        Маршрут: {preview?.routeNumber}
                                    </div>

                                    <div className="mt-2 text-sm text-white/80 whitespace-pre-line">
                                        {preview?.tasks}
                                    </div>
                                    <div>{preview.hours.toFixed(1)}h</div>
                                    <div>{preview.money.toFixed(2)} ₽</div>
                                </div>
                            )}

                            <button className="w-full mt-4 bg-orange-500 py-2 rounded font-bold">
                                Сохранить
                            </button>

                        </motion.form>

                    </motion.div>

                )}

            </AnimatePresence>

        </div>
    );
}

/* ================= WIDGET ================= */

function Widget({ icon, title, value }) {
    return (
        <div className="p-4 rounded-xl bg-white/5 border border-white/10">
            <div className="flex items-center gap-2 text-xs text-zinc-400">
                {icon}
                {title}
            </div>
            <div className="text-lg font-bold mt-1">{value}</div>
        </div>
    );
}
