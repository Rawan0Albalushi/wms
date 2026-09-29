import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App';
import { AuthProvider } from './auth';
import { LocaleProvider } from './i18n';

const phone = window.matchMedia('(max-width: 720px)');

function labelTables() {
    const compact = phone.matches;
    document.documentElement.classList.toggle('is-phone', compact);

    document.querySelectorAll('.table-wrap table').forEach((table) => {
        const labels = [...table.querySelectorAll(':scope > thead th')].map((cell) => cell.textContent.replace(/\s+/g, ' ').trim());

        table.querySelectorAll(':scope > tbody > tr').forEach((row) => {
            [...row.children].forEach((cell, index) => {
                const label = labels[index] || '';
                let marker = cell.querySelector(':scope > .cell-label');

                if (!compact || label === '') {
                    marker?.remove();

                    return;
                }

                if (!marker) {
                    marker = document.createElement('span');
                    marker.className = 'cell-label';
                    cell.prepend(marker);
                }

                if (marker.textContent !== label) {
                    marker.textContent = label;
                }
            });
        });
    });
}

let frame = 0;

function scheduleLabels() {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(labelTables);
}

createRoot(document.getElementById('root')).render(
    <StrictMode>
        <LocaleProvider>
            <BrowserRouter>
                <AuthProvider>
                    <App />
                </AuthProvider>
            </BrowserRouter>
        </LocaleProvider>
    </StrictMode>,
);

const root = document.getElementById('root');
const observer = new MutationObserver(scheduleLabels);
observer.observe(root, { childList: true, subtree: true });
phone.addEventListener('change', scheduleLabels);
scheduleLabels();
