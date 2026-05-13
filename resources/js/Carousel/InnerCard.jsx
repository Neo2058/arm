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
                width: "42%",
                backgroundImage: `url(${src})`
            }}
            className='rounded-[30px] md:rounded-[40px]
           mt-10 md:mt-10 lg:mt-0
           absolute cursor-pointer
           w-[90%] sm:w-[300px] md:w-[600px] lg:w-[800px]
           h-[380px] sm:h-[420px] md:h-[350px] lg:h-[400px]
           overflow-hidden bg-gray-800 bg-cover bg-center
           p-4 md:p-6
           flex items-center justify-center flex-col md:flex-row
           gap-4 md:gap-8 lg:gap-12'
        >
            <div className='absolute inset-0 w-full h-full bg-black opacity-20 hover:opacity-40 rounded-xl z-10' />

            <div className='relative z-20 flex flex-col pt-6 pl-4 gap-3 md:gap-5'>
                <h3 className='text-[24px] md:text-6xl font-semibold text-transparent bg-clip-text bg-gradient-to-r from-blue-500 via-cyan-500 to-blue-500 text-center md:text-left'>{name}</h3>

                <div className='flex flex-col gap-3 text-gray-200 text-[16px] md:text-xl'>
                    {details.map((detail, index) => (
                        <div key={index} className='flex flex-row gap-1'>
                            <p className='text-white text-xl'>{detail.name}</p>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    )
}

export default InnerCard
