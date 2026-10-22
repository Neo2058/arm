import React, { memo } from 'react'

const InnerCard = ({ src, name, variant, handleClick, details, isCenter }) => {
    const { x, scale, zIndex, opacity = 1 } = variant

    return (
        <div
            onClick={handleClick}
            role={isCenter ? 'link' : 'button'}
            aria-hidden={!isCenter}
            style={{
                backgroundImage: `url(${src})`,
                transform: `translate3d(${x}, 0, 0) scale(${scale})`,
                zIndex,
                opacity,
                pointerEvents: opacity > 0.4 ? 'auto' : 'none',
                willChange: 'transform, opacity',
                backfaceVisibility: 'hidden',
                WebkitBackfaceVisibility: 'hidden',
            }}
            className='carousel-card rounded-[24px] xs:rounded-[30px] md:rounded-[40px]
           mt-2 md:mt-4 lg:mt-0
           absolute cursor-pointer
           w-[86%] sm:w-[450px] md:w-[600px] lg:w-[800px]
           h-[200px] sm:h-[280px] md:h-[320px] lg:h-[400px]
           overflow-hidden bg-gray-800 bg-cover bg-center
           p-4 xs:p-6 md:p-8
           flex items-center justify-center flex-col md:flex-row
           gap-3 md:gap-8 lg:gap-12
           shadow-md md:shadow-2xl
           transition-[transform,opacity] duration-300 ease-out'
        >
            <div className='absolute inset-0 w-full h-full bg-black/40 rounded-[24px] xs:rounded-[30px] md:rounded-[40px] z-10' />

            <div className='relative z-20 flex flex-col w-full h-full justify-center lg:justify-start pt-2 md:pt-6 pl-2 md:pl-4 gap-2 md:gap-5 min-w-0'>
                <h3 className='text-base xs:text-lg sm:text-xl md:text-5xl lg:text-6xl font-black text-cyan-300 md:text-transparent md:bg-clip-text md:bg-gradient-to-r md:from-blue-400 md:via-cyan-400 md:to-blue-500 text-center md:text-left break-words leading-tight max-w-full'>
                    {name}
                </h3>

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

export default memo(InnerCard)
