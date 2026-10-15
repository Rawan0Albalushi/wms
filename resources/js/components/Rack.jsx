import { useEffect, useState } from 'react';
import { api, formatDate, formatQty, label } from '../api';
import { useLocale } from '../i18n';
import { Pager, usePaged, Who } from './ui';

const STORES = [
    { id: 'S1', label: 'store.1' },
    { id: 'S2', label: 'store.2' },
    { id: 'S3', label: 'store.3' },
    { id: 'other', label: 'store.other' },
];

function storeId(location) {
    const zone = String(location.zone || '').toUpperCase();

    return zone === 'S1' || zone === 'S2' || zone === 'S3' ? zone : 'other';
}

function placeMark(location, locale) {
    const qty = Number(location.on_hand) || 0;
    const cleared = qty > 0 ? null : location.cleared;

    return {
        qty,
        state: qty > 0 ? 'stock' : 'empty',
        caption: qty > 0 ? qty : (cleared?.item_code || '—'),
        title: cleared ? `${cleared.item_code} ${label(cleared, locale)}` : location.code,
    };
}

export default function Rack({ locations, active, onPick }) {
    const { t, locale } = useLocale();
    const [store, setStore] = useState('S1');
    const inStore = locations.filter((location) => storeId(location) === store);
    const bins = store === 'other' ? [] : inStore.filter((location) => location.rack && location.shelf);
    const loose = (store === 'other' ? inStore : inStore.filter((location) => !location.rack || !location.shelf))
        .sort((left, right) => left.code.localeCompare(right.code));
    const racks = [...new Set(bins.map((location) => String(location.rack)))]
        .sort((left, right) => left.localeCompare(right));
    const shelves = [...new Set(bins.map((location) => String(location.shelf)))]
        .sort((left, right) => Number(left) - Number(right) || left.localeCompare(right));
    const slots = bins.reduce((grouped, location) => {
        const key = `${location.rack}|${location.shelf}`;
        grouped.set(key, [...(grouped.get(key) || []), location]);

        return grouped;
    }, new Map());
    const columns = { gridTemplateColumns: `2rem repeat(${shelves.length}, 4.7rem)` };
    const filePage = usePaged(loose, store, 40);

    useEffect(() => {
        if (!active) {
            return;
        }

        const selected = locations.find((location) => location.code === active);

        if (selected) {
            setStore(storeId(selected));
        }
    }, [active, locations]);

    function chooseStore(next) {
        setStore(next);
        const selected = locations.find((location) => location.code === active);

        if (selected && storeId(selected) !== next) {
            onPick('');
        }
    }

    function cellButton(location) {
        const mark = placeMark(location, locale);

        return (
            <button
                key={location.id}
                type="button"
                className={active === location.code ? 'cell on' : 'cell'}
                data-state={mark.state}
                aria-pressed={active === location.code}
                title={mark.title}
                onClick={() => onPick(active === location.code ? '' : location.code)}
            >
                <b>{location.code}</b>
                <small>{mark.caption}</small>
            </button>
        );
    }

    return (
        <>
            <div className="tabs store-tabs" role="tablist" aria-label={t('map')}>
                {STORES.map((item) => {
                    const count = locations.filter((location) => storeId(location) === item.id).length;

                    return (
                        <button
                            key={item.id}
                            type="button"
                            role="tab"
                            aria-selected={store === item.id}
                            className={store === item.id ? 'on' : undefined}
                            onClick={() => chooseStore(item.id)}
                        >
                            {t(item.label)}
                            {item.id !== 'other' ? <span className="mono">{item.id}</span> : null}
                            <small>{count}</small>
                        </button>
                    );
                })}
            </div>
            {bins.length ? (
                <div className="rack" dir="ltr">
                    <div className="rack-line rack-head" style={columns}>
                        <span className="rack-idx" />
                        {shelves.map((shelf) => <span className="rack-idx" key={shelf}>{shelf}</span>)}
                    </div>
                    {racks.map((rack) => (
                        <div className="rack-line" key={rack} style={columns}>
                            <span className="rack-idx">{rack}</span>
                            {shelves.map((shelf) => {
                                const places = slots.get(`${rack}|${shelf}`) || [];

                                if (places.length) {
                                    return places.map(cellButton);
                                }

                                const sample = bins.find((location) => String(location.rack) === String(rack));
                                const shelfText = sample ? String(sample.shelf) : '';
                                const code = sample && String(sample.code).endsWith(shelfText)
                                    ? `${String(sample.code).slice(0, -shelfText.length)}${shelf}`
                                    : `${store}${rack}${shelf}`;

                                return (
                                    <div key={shelf} className="cell" data-state="empty">
                                        <b>{code}</b>
                                        <small>—</small>
                                    </div>
                                );
                            })}
                        </div>
                    ))}
                </div>
            ) : null}
            {loose.length ? (
                <section className="file-places" aria-label={t('rack.file')}>
                    <h3>{t('rack.file')}</h3>
                    <div className="file-bins">
                        {filePage.rows.map(cellButton)}
                    </div>
                    <Pager page={filePage.page} pages={filePage.pages} total={filePage.total} from={filePage.from} to={filePage.to} onPage={filePage.setPage} />
                </section>
            ) : null}
            {inStore.length === 0 ? <p className="empty">{t('empty')}</p> : null}
            <div className="rack-key">
                <span><i data-state="stock" />{t('rack.stocked')}</span>
                <span><i data-state="empty" />{t('rack.empty')}</span>
            </div>
        </>
    );
}

export function BinStock({ place }) {
    const { t, locale } = useLocale();
    const placeId = place && Number(place.on_hand) > 0 ? place.id : null;
    const [rows, setRows] = useState(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (!placeId) {
            return undefined;
        }

        let live = true;
        setRows(null);
        setError(false);
        api(`/locations/${placeId}/stock`)
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
        <section className="bin-stock" aria-label={t('rack.contents')}>
            <h3>{t('rack.contents')}</h3>
            {error ? <p className="banner" role="alert">{t('errors.generic')}</p> : null}
            {rows === null && !error ? <p className="empty">{t('loading')}</p> : null}
            {rows && rows.length === 0 ? <p className="empty">{t('empty')}</p> : null}
            {rows && rows.length > 0 ? (
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{t('item')}</th>
                                <th className="num" scope="col">{t('qty')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.item_id}>
                                    <td><Who code={row.item_code} name={label(row, locale)} to={`/items/${row.item_id}`} /></td>
                                    <td className="num">{formatQty(row.quantity)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}
        </section>
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
