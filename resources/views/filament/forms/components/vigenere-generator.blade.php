<div x-data="{
    key: 'SECRET',
    source: 'PASSWORD',
    generate() {
        const text = this.source.toUpperCase();
        const key = this.key.toUpperCase();
        let result = '';
        for (let i = 0, j = 0; i < text.length; i++) {
            const charCode = text.charCodeAt(i);
            if (charCode >= 65 && charCode <= 90) {
                result += String.fromCharCode(((charCode - 65 + (key.charCodeAt(j % key.length) - 65)) % 26) + 65);
                j++;
            } else {
                result += text[i];
            }
        }
        // Устанавливаем значение в поле пароля Filament
        $wire.set('data.password', result);
    }
}" class="p-4 border rounded-lg bg-blue-600">
    <div class="flex gap-4 items-end">
        <div class="flex-1">
            <label class="text-sm font-medium">Ключевое слово (Шифр Виженера)</label>
            <input type="text" x-model="key" class="w-full mt-1 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
        </div>
        <div class="flex-1">
            <label class="text-sm font-medium">Исходная фраза</label>
            <input type="text" x-model="source" class="w-full mt-1 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
        </div>
        <button type="button" @click="generate()" class="px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700">
            Сгенерировать
        </button>
    </div>
</div>
