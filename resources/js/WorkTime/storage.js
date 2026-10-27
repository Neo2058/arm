export function browserStorage(scope) {
    const key = `arm.personal-worktime.v1:${scope}`;
    return {
        async read() {
            const text = sessionStorage.getItem(key);
            if (!text) return null;
            const data = JSON.parse(text);
            if (data.schema !== 1 || !data.entries?.editor) throw new Error('Invalid draft');
            return data;
        },
        async write(data) {
            sessionStorage.setItem(key, JSON.stringify(data));
            if (sessionStorage.getItem(key) !== JSON.stringify(data)) throw new Error('Storage unavailable');
        },
    };
}
