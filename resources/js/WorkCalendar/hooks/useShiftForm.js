import { useState, useCallback } from 'react';
import axios from 'axios';

/**
 * Custom hook that manages the entire state and logic for the shift editing modal.
 * Includes form fields, mode switching, preview calculation via backend, save, and reset.
 */
export function useShiftForm({ onSaved, refreshShifts }) {
    const [open, setOpen] = useState(false);
    const [selectedDate, setSelectedDate] = useState(null);
    const [mode, setMode] = useState('work'); // 'work' | 'deviation'
    const [activeShift, setActiveShift] = useState(null);

    // Work fields
    const [routeId, setRouteId] = useState('');
    const [startAt, setStartAt] = useState('');
    const [endAt, setEndAt] = useState('');
    const [startLocation, setStartLocation] = useState('');
    const [endLocation, setEndLocation] = useState('');
    const [breakMin, setBreakMin] = useState(0);

    // Deviation fields
    const [selectedDeviationId, setSelectedDeviationId] = useState('');

    const [saving, setSaving] = useState(false);
    const [preview, setPreview] = useState(null);
    const [previewLoading, setPreviewLoading] = useState(false);

    const resetForm = useCallback((dateStr = null, existingShift = null) => {
        setSelectedDate(dateStr);
        setActiveShift(existingShift || null);
        setMode(existingShift?.type || 'work');
        setPreview(null);

        if (existingShift) {
            if (existingShift.type === 'work') {
                setRouteId(existingShift.route_id ? String(existingShift.route_id) : '');
                setStartAt(existingShift.started_at?.slice(0, 16) || '');
                setEndAt(existingShift.ended_at?.slice(0, 16) || '');
                setStartLocation(existingShift.start_location || '');
                setEndLocation(existingShift.end_location || '');
                setBreakMin(existingShift.break_duration || 0);
                setSelectedDeviationId('');
            } else {
                setSelectedDeviationId(existingShift.deviation_id ? String(existingShift.deviation_id) : '');
                setRouteId('');
                setStartAt('');
                setEndAt('');
                setStartLocation('');
                setEndLocation('');
                setBreakMin(0);
            }
        } else {
            // New day defaults
            setMode('work');
            setRouteId('');
            setSelectedDeviationId('');
            setStartAt(dateStr ? `${dateStr}T08:00` : '');
            setEndAt(dateStr ? `${dateStr}T16:00` : '');
            setStartLocation('');
            setEndLocation('');
            setBreakMin(0);
        }
    }, []);

    const openDay = useCallback((dayObj) => {
        const dateStr = dayObj.dateStr;
        const existing = dayObj.shift || null;

        resetForm(dateStr, existing);
        setOpen(true);
    }, [resetForm]);

    const close = useCallback(() => {
        setOpen(false);
        setPreview(null);
        // Keep the form values until next open for better UX if user reopens same day
    }, []);

    // Fetch live preview from backend (single source of truth)
    const fetchPreview = useCallback(async () => {
        if (!selectedDate) return;

        const payload = {
            shift_date: selectedDate,
            type: mode,
            deviation_id: mode === 'deviation' ? (selectedDeviationId ? Number(selectedDeviationId) : null) : null,
            route_id: mode === 'work' ? (routeId ? Number(routeId) : null) : null,
            started_at: mode === 'work' ? startAt : null,
            ended_at: mode === 'work' ? endAt : null,
            break_duration: mode === 'work' ? breakMin : 0,
        };

        // Don't call preview if required fields for work are missing
        if (mode === 'work' && (!startAt || !endAt)) {
            setPreview(null);
            return;
        }
        if (mode === 'deviation' && !selectedDeviationId) {
            setPreview(null);
            return;
        }

        setPreviewLoading(true);
        try {
            const res = await axios.post('/api/work-shifts/preview', payload);
            setPreview(res.data);
        } catch (e) {
            console.error('Preview error:', e);
            setPreview(null);
        } finally {
            setPreviewLoading(false);
        }
    }, [selectedDate, mode, routeId, startAt, endAt, breakMin, selectedDeviationId]);

    // Auto-fetch preview when relevant fields change
    // (simple debounce not needed for this size; can add later)
    const [previewDeps] = useState(0); // trigger mechanism
    // We call fetchPreview from the modal when fields change (see ShiftModal)

    const save = useCallback(async () => {
        if (!selectedDate) return;

        setSaving(true);

        const payload = {
            shift_date: selectedDate,
            type: mode,
            route_id: mode === 'work' ? (routeId ? Number(routeId) : null) : null,
            deviation_id: mode === 'deviation' ? (selectedDeviationId ? Number(selectedDeviationId) : null) : null,
            started_at: mode === 'work' ? startAt : null,
            ended_at: mode === 'work' ? endAt : null,
            start_location: mode === 'work' ? startLocation : null,
            end_location: mode === 'work' ? endLocation : null,
            break_duration: mode === 'work' ? breakMin : 0,
        };

        try {
            await axios.post('/api/work-shifts', payload);
            setOpen(false);
            setPreview(null);

            if (refreshShifts) await refreshShifts();
            if (onSaved) onSaved();

        } catch (e) {
            console.error('Save shift error:', e);
            alert('Не удалось сохранить смену. Проверьте данные.');
        } finally {
            setSaving(false);
        }
    }, [selectedDate, mode, routeId, selectedDeviationId, startAt, endAt, startLocation, endLocation, breakMin, refreshShifts, onSaved]);

    // Helper to update individual field and trigger preview
    const updateField = useCallback((field, value) => {
        switch (field) {
            case 'mode': setMode(value); break;
            case 'routeId': setRouteId(value); break;
            case 'startAt': setStartAt(value); break;
            case 'endAt': setEndAt(value); break;
            case 'startLocation': setStartLocation(value); break;
            case 'endLocation': setEndLocation(value); break;
            case 'breakMin': setBreakMin(Number(value) || 0); break;
            case 'selectedDeviationId': setSelectedDeviationId(value); break;
            default: break;
        }
    }, []);

    return {
        // Modal state
        open,
        selectedDate,
        mode,
        activeShift,
        saving,
        preview,
        previewLoading,

        // Form fields
        routeId,
        startAt,
        endAt,
        startLocation,
        endLocation,
        breakMin,
        selectedDeviationId,

        // Actions
        openDay,
        close,
        save,
        updateField,
        fetchPreview,   // call this from the modal when fields change
        resetForm,
    };
}
