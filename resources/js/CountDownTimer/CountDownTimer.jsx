import React from 'react';
import { motion } from 'framer-motion';

const MotionCard = React.memo(function MotionCard({ label, value, delay }) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 40 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.6, delay }}
            whileHover={{
                scale: 1.04,
                y: -6,
            }}
            className="relative overflow-hidden rounded-3xl border border-white/10 bg-white/10 backdrop-blur-xl shadow-2xl"
        >
            <div className="absolute inset-0 bg-gradient-to-br from-white/10 via-transparent to-black/20" />

            <div className="relative flex flex-col items-center justify-center px-8 py-8 md:px-10 md:py-10">
                <motion.div
                    key={value}
                    initial={{ scale: 0.8, opacity: 0 }}
                    animate={{ scale: 1, opacity: 1 }}
                    transition={{ duration: 0.35 }}
                    className="text-5xl md:text-7xl font-black text-white tracking-wider drop-shadow-[0_0_12px_rgba(255,255,255,0.35)]"
                >
                    {value}
                </motion.div>

                <div className="mt-3 text-sm md:text-base uppercase tracking-[0.3em] text-zinc-300">
                    {label}
                </div>
            </div>

            <div className="absolute bottom-0 left-0 h-[3px] w-full bg-gradient-to-r from-orange-400 via-yellow-300 to-orange-500" />
        </motion.div>
    );
});

export default function LocomotiveCountdown() {
    const targetDate = new Date(
        new Date().getFullYear(),
        new Date().getMonth() + 1,
        0,
        23,
        59,
        59
    ).getTime();

    const calculateTimeLeft = () => {
        const now = Date.now();
        const distance = targetDate - now;

        if (distance <= 0) {
            return {
                days: '00',
                hours: '00',
                minutes: '00',
                seconds: '00',
            };
        }

        const days = Math.floor(distance / (1000 * 60 * 60 * 24));

        const hours = Math.floor(
            (distance % (1000 * 60 * 60 * 24)) /
            (1000 * 60 * 60)
        );

        const minutes = Math.floor(
            (distance % (1000 * 60 * 60)) /
            (1000 * 60)
        );

        const seconds = Math.floor(
            (distance % (1000 * 60)) / 1000
        );

        const pad = (n) => String(n).padStart(2, '0');

        return {
            days: pad(days),
            hours: pad(hours),
            minutes: pad(minutes),
            seconds: pad(seconds),
        };
    };

    const [timeLeft, setTimeLeft] = React.useState(
        calculateTimeLeft()
    );

    React.useEffect(() => {
        const interval = setInterval(() => {
            setTimeLeft(calculateTimeLeft());
        }, 1000);

        return () => clearInterval(interval);
    }, []);

    return (
        <section className="relative flex min-h-screen w-full items-center justify-center overflow-hidden px-4 py-20">
            {/* Background image */}
            <div
                className="absolute inset-0 bg-cover bg-center"
                style={{
                    backgroundImage:
                        "url('/images/train.jpg')",
                }}
            />

            {/* Dark overlay */}
            <div className="absolute inset-0 bg-black/65" />

            {/* Radial glow */}
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(255,180,0,0.25),transparent_45%)]" />

            {/* Content */}
            <div className="relative z-10 w-full max-w-7xl">
                {/* Header */}
                <motion.div
                    initial={{ opacity: 0, y: 30 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.7 }}
                    className="mb-14 text-center"
                >
                    <div className="mb-4 inline-flex items-center gap-3 rounded-full border border-orange-400/20 bg-orange-400/10 px-5 py-2 backdrop-blur-md">
                        <div className="h-2 w-2 rounded-full bg-orange-400 animate-pulse" />
                        <span className="text-xs uppercase tracking-[0.35em] text-orange-200">
              Система контроля образования
            </span>
                    </div>

                    <h1 className="mx-auto max-w-5xl text-4xl font-black leading-tight text-white sm:text-5xl md:text-6xl xl:text-7xl">
                        До прохождения ТУ осталось
                    </h1>

                    <p className="mx-auto mt-6 max-w-2xl text-sm leading-relaxed text-zinc-300 md:text-lg">
                        Подготовьтесь к следующему техническому обучению.
                        Таймер автоматически отслеживает окончание текущего месяца.
                    </p>
                </motion.div>

                {/* Countdown Grid */}
                <div className="grid grid-cols-2 gap-4 md:grid-cols-4 md:gap-8">
                    <MotionCard
                        label="Дней"
                        value={timeLeft.days}
                        delay={0.1}
                    />

                    <MotionCard
                        label="Часов"
                        value={timeLeft.hours}
                        delay={0.2}
                    />

                    <MotionCard
                        label="Минут"
                        value={timeLeft.minutes}
                        delay={0.3}
                    />

                    <MotionCard
                        label="Секунд"
                        value={timeLeft.seconds}
                        delay={0.4}
                    />
                </div>

                {/* Bottom locomotive line */}
                <motion.div
                    initial={{ opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ delay: 1 }}
                    className="mt-14 overflow-hidden rounded-2xl border border-white/10 bg-black/30 backdrop-blur-lg"
                >
                    <div className="flex items-center justify-between gap-4 px-6 py-4 text-xs uppercase tracking-[0.25em] text-zinc-400 md:text-sm">
                        <span>Депо: Печатники</span>
                        <span>Система: Активна</span>
                        <span>Статус: Подтверждён</span>
                    </div>

                    <motion.div
                        animate={{
                            x: ['-100%', '100%'],
                        }}
                        transition={{
                            repeat: Infinity,
                            duration: 8,
                            ease: 'linear',
                        }}
                        className="h-[2px] w-40 bg-gradient-to-r from-transparent via-orange-400 to-transparent"
                    />
                </motion.div>
            </div>
        </section>
    );
}
