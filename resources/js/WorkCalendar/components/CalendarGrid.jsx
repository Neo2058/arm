import React from 'react';

const weekdays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

export default function CalendarGrid({ days, onDayClick, getDayColor }) {
    return (
        <div className="grid grid-cols-7 gap-1 sm:gap-2">
            {/* Weekday headers */}
            {weekdays.map(day => (
                <div
                    key={day}
                    className="text-center text-xs text-zinc-500 font-medium pb-2"
                >
                    {day}
                </div>
            ))}

            {/* Day cells */}
            {days.map((d, i) => {
                if (!d) {
                    return (
                        <div
                            key={`empty-${i}`}
                            className="h-10 sm:h-12 md:h-14"
                        />
                    );
                }

                const today = d.dateStr === new Date().toISOString().split('T')[0];

                return (
                    <div
                        key={d.dateStr}
                        onClick={() => onDayClick(d)}
                        className={`
                            h-10 sm:h-12 md:h-14
                            rounded-xl
                            flex items-center justify-center
                            cursor-pointer border transition
                            ${today
                                ? 'border-orange-500 bg-orange-500/10'
                                : 'border-white/10 bg-white/5'
                            }
                            hover:border-white/30
                        `}
                    >
                        <div className="flex flex-col items-center justify-center h-full">
                            <span className="font-medium">{d.day}</span>

                            {d.shift && (
                                <div
                                    className={`
                                        w-2 h-2 rounded-full mt-1
                                        ${getDayColor(d.shift)}
                                    `}
                                />
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
