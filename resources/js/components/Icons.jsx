export default function Icon({ name }) {
    const common = {
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: '1.6',
        strokeLinecap: 'round',
        strokeLinejoin: 'round',
        className: 'icon',
        'aria-hidden': 'true',
    };

    if (name === 'home') {
        return <svg {...common}><path d="M4 11.5 12 5l8 6.5" /><path d="M7 10.5V19h10v-8.5" /></svg>;
    }

    if (name === 'menu') {
        return <svg {...common}><path d="M4 7h16M4 12h16M4 17h16" /></svg>;
    }

    if (name === 'box') {
        return <svg {...common}><path d="M3 8l9-4 9 4-9 4-9-4z" /><path d="M3 8v8l9 4 9-4V8" /><path d="M12 12v8" /></svg>;
    }

    if (name === 'pin') {
        return <svg {...common}><path d="M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11z" /><circle cx="12" cy="10" r="2.2" /></svg>;
    }

    if (name === 'truck') {
        return <svg {...common}><path d="M3 7h11v8H3z" /><path d="M14 10h4l3 3v2h-7" /><circle cx="7" cy="17.5" r="1.5" /><circle cx="17" cy="17.5" r="1.5" /></svg>;
    }

    if (name === 'layers') {
        return <svg {...common}><path d="M12 3l9 5-9 5-9-5 9-5z" /><path d="M3 12l9 5 9-5" /><path d="M3 16l9 5 9-5" /></svg>;
    }

    if (name === 'out') {
        return <svg {...common}><path d="M4 7h9v10H4z" /><path d="M13 12h7" /><path d="M17 9l3 3-3 3" /></svg>;
    }

    if (name === 'move') {
        return <svg {...common}><path d="M4 8h12" /><path d="M13 5l3 3-3 3" /><path d="M20 16H8" /><path d="M11 13l-3 3 3 3" /></svg>;
    }

    if (name === 'wrench') {
        return <svg {...common}><path d="M14 6a4 4 0 0 0-5 5L4 16l4 4 5-5a4 4 0 0 0 5-5l-3 3-3-3 2-3z" /></svg>;
    }

    if (name === 'supplier') {
        return <svg {...common}><circle cx="9" cy="8" r="2.5" /><path d="M4.5 17c.6-2.4 2.4-3.5 4.5-3.5s3.9 1.1 4.5 3.5" /><path d="M16 8h4M16 12h4M16 16h3" /></svg>;
    }

    if (name === 'cart') {
        return <svg {...common}><circle cx="8" cy="19" r="1.4" /><circle cx="17" cy="19" r="1.4" /><path d="M3 5h2l2.2 9h11l2-6H7" /></svg>;
    }

    if (name === 'clipboard') {
        return <svg {...common}><rect x="6" y="4" width="12" height="16" /><path d="M9 4.5h6v2H9z" /><path d="M9 11h6M9 15h4" /></svg>;
    }

    if (name === 'chart') {
        return <svg {...common}><path d="M4 19V5" /><path d="M4 19h16" /><path d="M8 16v-4" /><path d="M12 16V8" /><path d="M16 16v-6" /></svg>;
    }

    if (name === 'key') {
        return <svg {...common}><circle cx="8" cy="14" r="3.2" /><path d="M11 14h9l-2 2m2-2-2-2" /></svg>;
    }

    if (name === 'eye') {
        return <svg {...common}><path d="M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12z" /><circle cx="12" cy="12" r="2.4" /></svg>;
    }

    if (name === 'edit') {
        return <svg {...common}><path d="M4 20h4l11-11-4-4L4 16v4z" /><path d="M13 7l4 4" /></svg>;
    }

    if (name === 'trash') {
        return <svg {...common}><path d="M5 7h14" /><path d="M9 7V5h6v2" /><path d="M7 7l1 12h8l1-12" /></svg>;
    }

    if (name === 'check') {
        return <svg {...common}><path d="M5 12.5 10 17l9-10" /></svg>;
    }

    if (name === 'x') {
        return <svg {...common}><path d="M6 6l12 12M18 6 6 18" /></svg>;
    }

    return <svg {...common}><path d="M6 9a6 6 0 1 1 12 0c0 7-6 7-6 7s-6 0-6-7z" /><path d="M10 20h4" /></svg>;
}
