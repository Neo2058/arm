import React, { useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { X } from 'lucide-react';

/**
 * Shift editing modal.
 * Receives almost all state and handlers from useShiftForm hook.
 */
export default function ShiftModal({
    open,
    selectedDate,
    mode,
    saving,
    preview,
    previewLoading,
    routeId,
    startAt,
    endAt,
    startLocation,
    endLocation,
    breakMin,
    selectedDeviationId,
    catalog,
    deviationsCatalog,
    updateField,
    fetchPreview,
    save,
    close,
}) {
    // Live preview when relevant fields change
    useEffect(() => {
        if (open) {
            fetchPreview();
        }
    }, [
        open,
        mode,
        routeId,
        startAt,
        endAt,
        breakMin,
        selectedDeviationId,
        fetchPreview,
    ]);

    if (!open) return null;

    return (
        <AnimatePresence>
            <motion.div
                className="fixed inset-0 z-50 bg-black/80 overflow-y-auto p-4"
                onClick={close}
            >
                <motion.form
                    onClick={e => e.stopPropagation()}
                    onSubmit={(e) => { e.preventDefault(); save(); }}
                    initial={{ scale: 0.95 }}
                    animate={{ scale: 1 }}
                    exit={{ scale: 0.95 }}
                    className="bg-[#0b1018] w-full max-w-xl mx-auto mt-10 mb-10 p-4 sm:p-6 rounded-2xl border border-white/10"
                >
                    <div className="flex justify-between mb-4">
                        <h3 className="font-bold">{selectedDate}</h3>
                        <button type="button" onClick={close}>
                            <X />
                        </button>
                    </div>

                    {/* Mode switch */}
                    <div className="flex gap-2 mb-4">
                        <button
                            type="button"
                            onClick={() => updateField('mode', 'work')}
                            className={`px-3 py-1 rounded ${mode === 'work' ? 'bg-orange-500' : 'bg-white/10'}`}
                        >
                            Работа
                        </button>
                        <button
                            type="button"
                            onClick={() => updateField('mode', 'deviation')}
                            className={`px-3 py-1 rounded ${mode === 'deviation' ? 'bg-cyan-500' : 'bg-white/10'}`}
                        >
                            Отвлечение
                        </button>
                    </div>

                    {/* WORK FORM */}
                    {mode === 'work' && (
                        <div className="space-y-3">
                            <select
                                value={routeId}
                                onChange={(e) => {
                                    const id = e.target.value;
                                    updateField('routeId', id);

                                    // Autofill from catalog (restored from original logic)
                                    const route = catalog.find(r => r.id === Number(id));
                                    if (!route || !selectedDate) return;

                                    updateField('startLocation', route.start_location || '');
                                    updateField('endLocation', route.end_location || '');
                                    updateField('breakMin', route.default_break_duration || 0);

                                    if (route.default_start_time) {
                                        updateField('startAt', `${selectedDate}T${route.default_start_time.slice(0, 5)}`);
                                    }
                                    if (route.default_end_time) {
                                        updateField('endAt', `${selectedDate}T${route.default_end_time.slice(0, 5)}`);
                                    }
                                }}
                                className="w-full p-2 bg-white/5 rounded"
                            >
                                <option value="">Маршрут</option>
                                {catalog.map(r => (
                                    <option key={r.id} value={r.id}>
                                        {r.route_number}
                                    </option>
                                ))}
                            </select>

                            <input
                                type="datetime-local"
                                value={startAt}
                                onChange={e => updateField('startAt', e.target.value)}
                                className="w-full h-12 bg-white/5 border border-white/10 rounded-xl px-3"
                                placeholder="Время явки"
                            />
                            <input
                                type="datetime-local"
                                value={endAt}
                                onChange={e => updateField('endAt', e.target.value)}
                                className="w-full h-12 bg-white/5 border border-white/10 rounded-xl px-3"
                                placeholder="Время сдачи"
                            />

                            <div className="grid grid-cols-2 gap-2">
                                <input
                                    type="text"
                                    value={startLocation}
                                    onChange={e => updateField('startLocation', e.target.value)}
                                    placeholder="Станция явки"
                                    className="p-2 bg-white/5 rounded"
                                />
                                <input
                                    type="text"
                                    value={endLocation}
                                    onChange={e => updateField('endLocation', e.target.value)}
                                    placeholder="Станция сдачи"
                                    className="p-2 bg-white/5 rounded"
                                />
                            </div>

                            <div>
                                <label className="text-xs text-zinc-400">Перерыв (минут)</label>
                                <input
                                    type="number"
                                    value={breakMin}
                                    onChange={e => updateField('breakMin', e.target.value)}
                                    className="w-full p-2 bg-white/5 rounded"
                                />
                            </div>
                        </div>
                    )}

                    {/* DEVIATION FORM */}
                    {mode === 'deviation' && (
                        <div>
                            <label className="text-[10px] uppercase font-bold text-zinc-500 block mb-1">
                                Вид отвлечения от работы
                            </label>
                            <select
                                value={String(selectedDeviationId || '')}
                                onChange={(e) => updateField('selectedDeviationId', e.target.value)}
                                className="w-full h-11 bg-black/30 border border-white/10 rounded-xl px-3 text-sm outline-none text-white focus:border-cyan-500/40"
                            >
                                <option value="" className="bg-[#0b1018]">-- Выберите причину из справочника --</option>
                                {Array.isArray(deviationsCatalog) && deviationsCatalog.map(dev => (
                                    <option
                                        key={dev.id}
                                        value={String(dev.id)}
                                        className="bg-[#0b1018]"
                                    >
                                        📍 {dev.name} ({parseFloat(dev.hourly_rate || 0).toFixed(2)} руб/ч)
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    {/* LIVE PREVIEW (now from backend) */}
                    {preview && (
                        <div className="mt-4 p-3 bg-white/5 rounded">
                            <div className="font-bold text-orange-400">{preview.label}</div>
                            <div className="text-xs text-zinc-400">Маршрут: {preview.routeNumber}</div>
                            <div className="mt-2 text-sm text-white/80 whitespace-pre-line">{preview.tasks}</div>
                            <div className="mt-1">
                                {preview.hours?.toFixed(1)} ч • {preview.money?.toFixed(2)} ₽
                            </div>
                            {previewLoading && <div className="text-xs text-zinc-500 mt-1">Пересчёт...</div>}
                        </div>
                    )}

                    <button
                        type="submit"
                        disabled={saving}
                        className="w-full mt-4 bg-orange-500 py-2 rounded font-bold disabled:opacity-60"
                    >
                        {saving ? 'Сохраняем...' : 'Сохранить'}
                    </button>
                </motion.form>
            </motion.div>
        </AnimatePresence>
    );
}
