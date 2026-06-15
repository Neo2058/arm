import { useState, useEffect, useCallback } from 'react';
import axios from 'axios';

/**
 * Custom hook for fetching and managing work shifts + catalogs for the current month.
 * Handles loading and error states.
 */
export function useWorkShifts(currentDate) {
    const [shifts, setShifts] = useState([]);
    const [catalog, setCatalog] = useState([]);
    const [deviationsCatalog, setDeviationsCatalog] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const month = currentDate.getMonth() + 1;
    const year = currentDate.getFullYear();

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);

        try {
            const res = await axios.get('/api/work-shifts', {
                params: { month, year }
            });

            setShifts(res.data.shifts || []);
            setCatalog(res.data.catalog || []);
            setDeviationsCatalog(res.data.deviations_catalog || []);
        } catch (e) {
            console.error('Ошибка загрузки данных смен:', e);
            setError(e);
        } finally {
            setLoading(false);
        }
    }, [month, year]);

    useEffect(() => {
        load();
    }, [load]);

    const refresh = load;

    return {
        shifts,
        catalog,
        deviationsCatalog,
        loading,
        error,
        refresh,
        // Allow optimistic updates from form if needed
        setShifts,
    };
}
