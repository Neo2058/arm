import { imagesArray, imageVariants, positions } from './constants.index.js'
import React, { useState } from 'react'
import InnerCard from './InnerCard'
import { StepBack, StepForward } from 'lucide-react'

const Carousel = ({ role }) => {
    const [positionIndexes, setPositionIndexes] = useState([0, 1, 2, 3, 4, 5, 6])

    const filterImages = imagesArray.filter(image => {
        if (!image.role) return true;

        return image.role.includes(role)
    })

    const activePosition = positions.slice(0, filterImages.length);

    const handleNext = (number = 1) => {
        setPositionIndexes((prevIndexes) =>
            prevIndexes.map((i) => (i + number + filterImages.length) % filterImages.length)
        )
    }

    const handleBack = (number = 1) => {
        setPositionIndexes((prevIndexes) =>
            prevIndexes.map((i) => (i - number + filterImages.length) % filterImages.length)
        )
    }

    const handleClick = (clickedIndex) => {
        const clickedPosition = positions[positionIndexes[clickedIndex]]

        // const positionMap = {
        //     left3: -3,
        //     left2: -2,
        //     left1: -1,
        //     center: 0,
        //     right1: 1,
        //     right2: 2,
        //     right3: 3
        // }
        //
        // const offset = positionMap[clickedPosition]
        //
        // if(offset > 0) {
        //     handleNext(offset)
        // } else if (offset < 0) {
        //     handleBack(-offset)
        // }
        // return clickedPosition;
        if (clickedPosition !== 'center') {
            const positionMap = {
                left3: -3, left2: -2, left1: -1, center: 0, right1: 1, right2: 2, right3: 3
            };
            const offset = positionMap[clickedPosition];
            offset > 0 ? handleNext(offset) : handleBack(-offset);

            return clickedPosition; // Выходим, чтобы не сработал переход сразу
        }

        const item = filterImages[clickedIndex];

        if (role === 'student') {
            // Студентов всегда кидаем на форму входа Filament (обычно /admin/login)
            window.location.href = '/admin/login';
        } else {
            // Все остальные идут по прямой ссылке на Blade-шаблоны
            window.location.href = item.link;
        }
    }
    return (
        <div className='flex pt-20 items-center justify-center flex-col gap-2 md:gap-4 bg-black py-24 w-screen h-screen'>
            <div className='flex pb-64 flex-col gap-2 text-center'>
                <h3 className='text-5xl lg:text-8xl font-semibold text-transparent bg-clip-text bg-gradient-to-r from-blue-500 to-blue-600'>Главное меню</h3>
                <p className='text-gray-300 text-[16px] md:text-[18px]'>Выберите раздел</p>
            </div>

            {filterImages.map((image, index) => (
                <InnerCard
                    key={image.name}
                    id={`tech-${index}`}
                    src={image.src}
                    name={image.name}
                    variant={imageVariants[positions[positionIndexes[index]]]}
                    imageLogo={image.logo}
                    handleClick={() => handleClick(index)}
                    details={image.details}
                />
            ))}

            <div className='flex flex-row gap-6 pb-12 z-20'>
                <button
                    className='text-white mt-48 bg-blue-500 cursor-pointer rounded-[12px] py-2 px-4'
                    onClick={() => handleNext(1)}
                >
                    <StepBack />
                </button>
                <button
                    className='text-white mt-48 bg-blue-500 cursor-pointer rounded-[12px] py-2 px-4'
                    onClick={() => handleBack(1)}
                >
                    <StepForward />
                </button>
            </div>
        </div>
    )
}

export default Carousel
