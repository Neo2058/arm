import { imagesArray, variantForOffset, wrappedOffset } from './constants.index.js'
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import InnerCard from './InnerCard'

const Arrow = ({ direction }) => (
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        {direction === 'back' ? (
            <>
                <line x1="19" y1="12" x2="5" y2="12" />
                <polyline points="12 19 5 12 12 5" />
            </>
        ) : (
            <>
                <line x1="5" y1="12" x2="19" y2="12" />
                <polyline points="12 5 19 12 12 19" />
            </>
        )}
    </svg>
)

const Carousel = ({ role }) => {
    const filterImages = useMemo(
        () => imagesArray.filter((image) => !image.role || image.role.includes(role)),
        [role]
    )
    const count = filterImages.length
    const [active, setActive] = useState(0)
    const [narrow, setNarrow] = useState(
        typeof window !== 'undefined' ? window.matchMedia('(max-width: 640px)').matches : true
    )
    const trackRef = useRef(null)
    const touchStartX = useRef(0)
    const touchStartY = useRef(0)
    const swiping = useRef(false)

    useEffect(() => {
        const mq = window.matchMedia('(max-width: 640px)')
        const onChange = () => setNarrow(mq.matches)
        onChange()
        mq.addEventListener('change', onChange)
        return () => mq.removeEventListener('change', onChange)
    }, [])

    const go = useCallback((delta) => {
        if (count === 0) {
            return
        }
        setActive((prev) => (prev + delta + count) % count)
    }, [count])

    useEffect(() => {
        const htmlEl = document.documentElement
        const bodyEl = document.body
        const prevHtml = htmlEl.style.overflowX
        const prevBody = bodyEl.style.overflowX
        const prevOverscroll = bodyEl.style.overscrollBehaviorX
        const prevTouch = bodyEl.style.touchAction

        htmlEl.style.overflowX = 'hidden'
        bodyEl.style.overflowX = 'hidden'
        bodyEl.style.overscrollBehaviorX = 'none'
        bodyEl.style.touchAction = 'pan-y'

        return () => {
            htmlEl.style.overflowX = prevHtml
            bodyEl.style.overflowX = prevBody
            bodyEl.style.overscrollBehaviorX = prevOverscroll
            bodyEl.style.touchAction = prevTouch
        }
    }, [])

    useEffect(() => {
        const el = trackRef.current
        if (!el) {
            return
        }

        const onStart = (e) => {
            if (e.touches.length !== 1) {
                return
            }
            touchStartX.current = e.touches[0].clientX
            touchStartY.current = e.touches[0].clientY
            swiping.current = false
        }

        const onMove = (e) => {
            if (e.touches.length !== 1) {
                return
            }
            const dx = e.touches[0].clientX - touchStartX.current
            const dy = e.touches[0].clientY - touchStartY.current
            if (!swiping.current && Math.abs(dx) > 12 && Math.abs(dx) > Math.abs(dy)) {
                swiping.current = true
            }
            if (swiping.current && e.cancelable) {
                e.preventDefault()
            }
        }

        const onEnd = (e) => {
            if (e.changedTouches.length !== 1) {
                return
            }
            const deltaX = e.changedTouches[0].clientX - touchStartX.current
            if (swiping.current && Math.abs(deltaX) > 40) {
                go(deltaX > 0 ? -1 : 1)
            }
            swiping.current = false
        }

        el.addEventListener('touchstart', onStart, { passive: true })
        el.addEventListener('touchmove', onMove, { passive: false })
        el.addEventListener('touchend', onEnd, { passive: true })

        return () => {
            el.removeEventListener('touchstart', onStart)
            el.removeEventListener('touchmove', onMove)
            el.removeEventListener('touchend', onEnd)
        }
    }, [go])

    const handleClick = (index) => {
        if (index !== active) {
            setActive(index)
            return
        }
        const item = filterImages[index]
        if (item?.link) {
            window.location.href = item.link
        }
    }

    return (
        <div className='flex pt-6 md:pt-10 items-center justify-center flex-col gap-2 md:gap-4 bg-black py-6 md:py-10 w-full min-h-dvh h-dvh overflow-x-hidden overflow-y-hidden'>
            <div className='flex pb-6 md:pb-8 flex-col gap-1 md:gap-2 text-center px-4'>
                <h3 className='text-4xl sm:text-5xl lg:text-8xl font-semibold text-blue-500 md:text-transparent md:bg-clip-text md:bg-gradient-to-r md:from-blue-500 md:to-blue-600'>Главное меню</h3>
                <p className='text-gray-300 text-[16px] md:text-[18px]'>Выберите раздел</p>
            </div>

            <div
                ref={trackRef}
                className="carousel-track relative w-full flex justify-center items-center overflow-hidden md:overflow-visible min-h-[200px] sm:min-h-[280px] md:min-h-[320px] lg:min-h-[400px]"
            >
                {filterImages.map((image, index) => {
                    const offset = wrappedOffset(index, active, count)
                    const variant = variantForOffset(offset, narrow)
                    if (!variant) {
                        return null
                    }
                    return (
                        <InnerCard
                            key={image.link + image.name}
                            src={image.src}
                            name={image.name}
                            variant={variant}
                            handleClick={() => handleClick(index)}
                            details={image.details}
                            isCenter={offset === 0}
                        />
                    )
                })}
            </div>

            <div className='flex flex-row gap-6 z-20 mt-4 md:mt-6'>
                <button
                    className='text-white bg-blue-500 cursor-pointer rounded-[12px] py-2 px-4'
                    onClick={() => go(-1)}
                    aria-label="Previous"
                    type="button"
                >
                    <Arrow direction="back" />
                </button>
                <button
                    className='text-white bg-blue-500 cursor-pointer rounded-[12px] py-2 px-4'
                    onClick={() => go(1)}
                    aria-label="Next"
                    type="button"
                >
                    <Arrow direction="forward" />
                </button>
            </div>
        </div>
    )
}

export default Carousel
