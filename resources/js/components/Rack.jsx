import { useEffect, useState } from 'react';
import { api, formatDate, formatQty, label } from '../api';
import { useLocale } from '../i18n';
import { Who } from './ui';

export default function Rack({ locations, active, onPick }) {
    const { t, locale } = useLocale();
    const bins = locations.filter((location) => location.zone === 'S1' && location.rack && location.shelf);
    const racks = bins.length
        ? [...new Set(bins.map((location) => location.rack))].sort()
        : ['A', 'B', 'C', 'D', 'E', 'F'];
    const shelves = bins.length
        ? [...new Set(bins.map((location) => String(location.shelf)))].sort((left, right) => Number(right) - Number(left) || right.localeCompare(left))
        : ['6', '5', '4', '3', '2', '1'];
    const byCode = Object.fromEntries(locations.map((location) => [location.code, location]));
    const columns = { gridTemplateColumns: `28px repeat(${racks.length}, minmax(4.5rem, 1fr))` };

    return (
        <>
            <div className="rack" dir="ltr">
                {shelves.map((shelf) => (
                    <div className="rack-row" key={shelf} style={columns}>
                        <span className="rack-idx">{shelf}</span>
                        {racks.map((rack) => {
                            const code = `S1${rack}${shelf}`;
                            const cell = byCode[code];
                            const qty = cell?.on_hand || 0;
                            const cleared = qty > 0 ? null : cell?.cleared;
                            const state = qty > 0 ? 'stock' : 'empty';

                            return (
                                <button
                                    key={code}
                                    type="button"
                                    className={active === code ? 'cell on' : 'cell'}
                                    data-state={state}
                                    aria-pressed={active === code}
                                    title={cleared ? `${cleared.item_code} ${label(cleared, locale)}` : undefined}
                                    onClick={() => onPick(active === code ? '' : code)}
                                >
                                    <b>{code}</b>
                                    <small>{qty > 0 ? qty : (cleared?.item_code || '—')}</small>
                                </button>
                            );
                        })}
                    </div>
                ))}
                <div className="rack-cols" style={columns}>
                    <span />
                    {racks.map((rack) => <span key={rack}>{rack}</span>)}
                </div>
            </div>
            <div className="rack-key">
                <span><i data-state="stock" />{t('rack.stocked')}</span>
                <span><i data-state="empty" />{t('rack.empty')}</span>
            </div>
        </>
    );
}

export function BinHistory({ place }) {
    const { t, locale } = useLocale();
    const placeId = place && Number(place.on_hand) === 0 && place.cleared ? place.id : null;
    const [rows, setRows] = useState(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (!placeId) {
            return undefined;
        }

        let live = true;
        setRows(null);
        setError(false);
        api(`/locations/${placeId}/history`)
            .then((next) => {
                if (live) {
                    setRows(next);
                }
            })
            .catch(() => {
                if (live) {
                    setError(true);
                }
            });

        return () => {
            live = false;
        };
    }, [placeId]);

    if (!placeId) {
        return null;
    }

    return (
        <section className="bin-history" aria-label={t('rack.history')}>
            <h3>{t('rack.history')}</h3>
            {error ? <p className="banner" role="alert">{t('errors.generic')}</p> : null}
            {rows === null && !error ? <p className="empty">{t('loading')}</p> : null}
            {rows && rows.length === 0 ? <p className="empty">{t('empty')}</p> : null}
            {rows && rows.length > 0 ? (
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{t('item')}</th>
                                <th scope="col">{t('kind')}</th>
                                <th className="num" scope="col">{t('qty')}</th>
                                <th scope="col">{t('date')}</th>
                                <th scope="col">{t('from')}</th>
                                <th scope="col">{t('to')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id}>
                                    <td><Who code={row.item_code} name={label(row, locale)} /></td>
                                    <td>{t(`move.${row.type}`)}</td>
                                    <td className="num">{formatQty(row.quantity)}</td>
                                    <td className="mono">{formatDate(row.happened_at)}</td>
                                    <td className="mono">{row.from || '—'}</td>
                                    <td className="mono">{row.to || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}
        </section>
    );
}
