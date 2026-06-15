import React, { useMemo } from 'react';
import { Calendar, ChevronRight, ChevronLeft } from 'lucide-react';
import { useWorkShifts } from './hooks/useWorkShifts';
import { useShiftForm } from './hooks/useShiftForm';
import CalendarGrid from './components/CalendarGrid';
import ShiftModal from './components/ShiftModal';

/* ===================== CONSTANTS ===================== */
const weekdays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

/* ===================== MAIN COMPONENT ===================== */
export default function WorkCalendar() {
    const [currentDate, setCurrentDate] = React.useState(new Date());

    // Data fetching hook
    const {
        shifts,
        catalog,
        deviationsCatalog,
        loading,
        refresh,
    } = useWorkShifts(currentDate);

    // Form + modal hook (centralizes all the messy state)
    const form = useShiftForm({
        onSaved: () => {},
        refreshShifts: refresh,
    });

    /* ================= CALENDAR DAYS ================= */
    const days = useMemo(() => {
        const y = currentDate.getFullYear();
        const m = currentDate.getMonth();

        const first = new Date(y, m, 1);
        const last = new Date(y, m + 1, 0);

        let offset = first.getDay() - 1;
        if (offset < 0) offset = 6;

        const arr = [];
        for (let i = 0; i < offset; i++) arr.push(null);

        for (let d = 1; d <= last.getDate(); d++) {
            const dateStr = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const shift = shifts.find(s => (s.shift_date || '').slice(0, 10) === dateStr);
            arr.push({ day: d, dateStr, shift });
        }
        return arr;
    }, [currentDate, shifts]);

    /* ================= MONTH STATS ================= */
    const monthStats = useMemo(() => {
        let totalMinutes = 0;
        let totalMoney = 0;

        for (const s of shifts) {
            totalMinutes += Number(s.total_minutes || 0);
            totalMoney += Number(s.estimated_earnings || 0);
        }

        return {
            hours: totalMinutes / 60,
            money: totalMoney,
        };
    }, [shifts]);

    /* ================= HELPERS ================= */
    const getDayColor = (shift) => {
        if (!shift) return '';

        if (shift.type === 'deviation') {
            switch (shift.deviation?.sys_key || shift.deviation_type) {
                case 'sick': return 'bg-blue-500';
                case 'training': return 'bg-purple-500';
                case 'study_leave': return 'bg-cyan-500';
                default: return 'bg-zinc-500';
            }
        }

        const hours = (shift.total_minutes || 0) / 60;
        if (hours < 6) return 'bg-green-500';
        if (hours <= 8) return 'bg-yellow-500';
        return 'bg-red-500';
    };

    const prevMonth = () => {
        setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() - 1, 1));
    };

    const nextMonth = () => {
        setCurrentDate(new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 1));
    };

    const goToday = () => setCurrentDate(new Date());

    /* ================= RENDER ================= */
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
                                <ChevronLeft size={18} />
                            </button>

                            <Calendar size={20} className="text-orange-400" />

                            <h2 className="text-sm sm:text-base md:text-lg font-bold text-orange-400 capitalize">
                                {currentDate.toLocaleString('ru-RU', { month: 'numeric', year: 'numeric' })}
                            </h2>

                            <button
                                onClick={nextMonth}
                                className="p-2 rounded-xl bg-white/5 border border-white/10 hover:bg-white/10"
                            >
                                <ChevronRight size={18} />
                            </button>
                        </div>

                        <button
                            onClick={goToday}
                            className="px-3 py-2 text-sm rounded-xl bg-orange-500 hover:bg-orange-600"
                        >
                            Сегодня
                        </button>
                    </div>

                    {loading && (
                        <div className="text-center py-4 text-zinc-400 text-sm">Загрузка смен...</div>
                    )}

                    <CalendarGrid
                        days={days}
                        onDayClick={form.openDay}
                        getDayColor={getDayColor}
                    />
                </div>

                {/* COLOR LEGEND + WIDGETS */}
                <div>
                    <div className="mt-4 flex flex-wrap gap-3 text-xs text-zinc-400 mb-4">
                        <div className="flex items-center gap-1"><div className="w-2 h-2 rounded-full bg-green-500" /> &lt; 6ч</div>
                        <div className="flex items-center gap-1"><div className="w-2 h-2 rounded-full bg-yellow-500" /> 6–8ч</div>
                        <div className="flex items-center gap-1"><div className="w-2 h-2 rounded-full bg-red-500" /> &gt; 8ч</div>
                        <div className="flex items-center gap-1"><div className="w-2 h-2 rounded-full bg-blue-500" /> Больничный</div>
                        <div className="flex items-center gap-1"><div className="w-2 h-2 rounded-full bg-purple-500" /> Повышение квалификации</div>
                        <div className="flex items-center gap-1"><div className="w-2 h-2 rounded-full bg-cyan-500" /> Учебный отпуск</div>
                    </div>

                    {/* Side widgets */}
                    <div className="space-y-3">
                        <Widget icon="⏱" title="Отработано за месяц" value={`${monthStats.hours.toFixed(1)} ч.`} />
                        <Widget icon="💰" title="Заработано за месяц" value={`${monthStats.money.toLocaleString('ru-RU')} ₽`} />
                    </div>
                </div>
            </div>

            {/* MODAL - now fully driven by the hook */}
            <ShiftModal
                open={form.open}
                selectedDate={form.selectedDate}
                mode={form.mode}
                saving={form.saving}
                preview={form.preview}
                previewLoading={form.previewLoading}
                routeId={form.routeId}
                startAt={form.startAt}
                endAt={form.endAt}
                startLocation={form.startLocation}
                endLocation={form.endLocation}
                breakMin={form.breakMin}
                selectedDeviationId={form.selectedDeviationId}
                catalog={catalog}
                deviationsCatalog={deviationsCatalog}
                updateField={form.updateField}
                fetchPreview={form.fetchPreview}
                save={form.save}
                close={form.close}
            />
        </div>
    );
}

/* ===================== SMALL WIDGET ===================== */
function Widget({ icon, title, value }) {
    return (
        <div className="p-4 rounded-xl bg-white/5 border border-white/10">
            <div className="flex items-center gap-2 text-xs text-zinc-400">
                <span>{icon}</span>
                {title}
            </div>
            <div className="text-lg font-bold mt-1">{value}</div>
        </div>
    );
}
