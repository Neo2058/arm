import React from 'react';
import { createRoot } from 'react-dom/client';
import Carousel from './Carousel/Carousel.jsx';

const el = document.getElementById('carousel-menu');
if (el) {
    createRoot(el).render(
        <Carousel role={el.getAttribute('data-role')} />
    );
}

async function generateDeviceFingerprint() {
    if (document.cookie.includes('device_key=')) return;

    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    ctx.textBaseline = "top";
    ctx.font = "14px 'Arial'";
    ctx.fillText("ARM-Security-Token", 2, 2);

    const canvasData = canvas.toDataURL();
    const rawString = canvasData + navigator.userAgent + screen.width + screen.height + (navigator.hardwareConcurrency || 2);

    const msgBuffer = new TextEncoder().encode(rawString);
    const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');

    document.cookie = `device_key=${hashHex}; path=/; max-age=157680000; SameSite=Lax; Secure`;
}
generateDeviceFingerprint();
