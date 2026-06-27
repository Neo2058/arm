import gsap from 'gsap'
import React, { useLayoutEffect, useRef } from 'react'

const InnerCard = ({id, src, name, variant, handleClick, details}) => {
    const cardRef = useRef()

    useLayoutEffect(() => {
        if(cardRef.current && variant) {
            gsap.to(cardRef.current, {
                x: variant.x,
                scale: variant.scale,
                zIndex: variant.zIndex,
                duration: 0.5,
                ease: "power3.out"
            })
        }
    }, [variant])

    return (
        <div
            ref={cardRef}
            id={id}
            onClick={handleClick}
            style={{
                // Оставляем инлайново только фоновую картинку,
                // а ширину полностью переносим в классы Tailwind для адаптивности
                backgroundImage: `url(${src})`
            }}
            className='rounded-[24px] xs:rounded-[30px] md:rounded-[40px]
           mt-2 md:mt-4 lg:mt-0
           absolute cursor-pointer
           /* ШИРИНА: На мобилках берем 86% экрана, чтобы были видны карточки сзади, далее по сетке */
           w-[86%] sm:w-[450px] md:w-[600px] lg:w-[800px]
           /* ВЫСОТА: На мобилках делаем её аккуратной и горизонтальной (200px), на ПК увеличиваем */
           h-[200px] sm:h-[280px] md:h-[320px] lg:h-[400px]
           overflow-hidden bg-gray-800 bg-cover bg-center
           p-4 xs:p-6 md:p-8
           flex items-center justify-center flex-col md:flex-row
           gap-3 md:gap-8 lg:gap-12
           shadow-2xl'
        >
            {/* Затемняющий слой */}
            <div className='absolute inset-0 w-full h-full bg-black/40 hover:bg-black/60 transition-opacity rounded-[24px] xs:rounded-[30px] md:rounded-[40px] z-10' />

            {/* Контентная часть */}
            <div className='relative z-20 flex flex-col w-full h-full justify-center lg:justify-start pt-2 md:pt-6 pl-2 md:pl-4 gap-2 md:gap-5 min-w-0'>

                {/* ЗАГОЛОВОК: text-base (16px) на самых маленьких, text-xl на обычных мобилках, md:text-5xl на ПК */}
                <h3 className='text-base xs:text-lg sm:text-xl md:text-5xl lg:text-6xl font-black text-transparent bg-clip-text bg-gradient-to-r from-blue-400 via-cyan-400 to-blue-500 text-center md:text-left break-words leading-tight max-w-full'>
                    {name}
                </h3>

                {/* ДЕТАЛИ: Адаптивный текст описания */}
                <div className='flex flex-col gap-1 md:gap-3 text-zinc-300 text-xs sm:text-sm md:text-xl'>
                    {details.map((detail, index) => (
                        <div key={index} className='flex flex-row justify-center md:justify-start gap-1 min-w-0'>
                            <p className='text-zinc-200 text-xs sm:text-base md:text-xl truncate max-w-full'>
                                {detail.name}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    )
}

export default InnerCard
