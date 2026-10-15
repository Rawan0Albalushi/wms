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

function compareCodes(left, right) {
    const parts = (code) => String(code).toUpperCase().replace(/\s+/g, ' ').replace(/([A-Z])(\d)/g, '$1 $2').trim().match(/\d+|\D+/g) || [];
    const a = parts(left);
    const b = parts(right);
    const length = Math.max(a.length, b.length);

    for (let index = 0; index < length; index += 1) {
        const x = a[index] || '';
        const y = b[index] || '';
        const diff = /^\d+$/.test(x) && /^\d+$/.test(y) ? Number(x) - Number(y) : x.localeCompare(y);

        if (diff !== 0) {
            return diff;
        }
    }

    return 0;
}

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

function itemPlaces(item) {
    const here = [...new Set(item?.locations || [])].filter(Boolean);
    const recorded = [...new Set(item?.recorded_locations || [])].filter(Boolean);

    return {
        here,
        recorded: recorded.filter((code) => !here.includes(code)),
        hits: here.length ? here : recorded,
    };
}

export default function Rack({ locations, active, onPick }) {
    const { t, locale } = useLocale();
    const [store, setStore] = useState('S1');
    const [query, setQuery] = useState('');
    const [matches, setMatches] = useState([]);
    const [picked, setPicked] = useState(null);
    const [finding, setFinding] = useState(false);
    const [findError, setFindError] = useState(false);
    const places = itemPlaces(picked);
    const hitCodes = new Set(places.hits);
    const inStore = locations.filter((location) => storeId(location) === store);
    const bins = store === 'other' ? [] : inStore.filter((location) => location.rack && location.shelf);
    const loose = (store === 'other' ? inStore : inStore.filter((location) => !location.rack || !location.shelf))
        .sort((left, right) => Number(hitCodes.has(right.code)) - Number(hitCodes.has(left.code)) || compareCodes(left.code, right.code));
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

    useEffect(() => {
        const term = query.trim();

        if (term.length < 2) {
            setMatches([]);
            setPicked(null);
            setFinding(false);
            setFindError(false);

            return undefined;
        }

        let live = true;
        const timer = setTimeout(() => {
            setFinding(true);
            api(`/items?q=${encodeURIComponent(term)}&per_page=20`)
                .then((rows) => {
                    if (!live) {
                        return;
                    }

                    const list = Array.isArray(rows) ? rows : (rows?.data || []);
                    const exact = list.find((item) => [item.code, item.part_number, item.barcode].some((value) => String(value || '').toLowerCase() === term.toLowerCase()));

                    setMatches(list);
                    setPicked(exact || (list.length === 1 ? list[0] : null));
                    setFindError(false);
                })
                .catch(() => {
                    if (live) {
                        setFindError(true);
                    }
                })
                .finally(() => {
                    if (live) {
                        setFinding(false);
                    }
                });
        }, 300);

        return () => {
            live = false;
            clearTimeout(timer);
        };
    }, [query]);

    useEffect(() => {
        if (!picked) {
            return;
        }

        const place = locations.find((location) => places.hits.includes(location.code));

        if (place) {
            setStore(storeId(place));
        }

        if (active && !places.hits.includes(active)) {
            onPick('');
        }
    }, [picked]);

    useEffect(() => {
        if (!picked) {
            return;
        }

        document.querySelector('.cell.hit')?.scrollIntoView({ block: 'nearest', inline: 'center' });
    }, [picked, store]);

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
                className={['cell', active === location.code ? 'on' : '', hitCodes.has(location.code) ? 'hit' : ''].filter(Boolean).join(' ')}
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
            <form className="map-find" role="search" onSubmit={(event) => event.preventDefault()}>
                <div className="map-find-row">
                    <input
                        className="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={t('rack.find')}
                        aria-label={t('rack.find')}
                    />
                    {query ? <button type="button" className="btn ghost small" onClick={() => setQuery('')}>{t('show_all')}</button> : null}
                </div>
                {findError ? <p className="banner" role="alert">{t('errors.generic')}</p> : null}
                {finding ? <p className="empty">{t('loading')}</p> : null}
                {!finding && query.trim().length >= 2 && matches.length === 0 && !findError ? <p className="empty">{t('empty')}</p> : null}
                {!picked && matches.length > 1 ? (
                    <>
                        <p className="map-find-note">{t('rack.find.pick')}</p>
                        <ul className="map-find-list">
                            {matches.slice(0, 8).map((item) => (
                                <li key={item.id}>
                                    <button type="button" onClick={() => setPicked(item)}>
                                        <b className="mono">{item.code}</b> {label(item, locale)}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </>
                ) : null}
                {picked ? (
                    <p className="map-find-note">
                        <b className="mono">{picked.code}</b> {label(picked, locale)}
                        {' · '}
                        {places.here.length ? `${t('rack.find.here')} ${places.here.join('، ')}` : t('rack.find.none')}
                        {places.recorded.length ? ` · ${t('rack.find.file')} ${places.recorded.join('، ')}` : ''}
                    </p>
                ) : null}
            </form>
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
                <div className={hitCodes.size ? 'rack is-filtering' : 'rack'} dir="ltr">
                    <div className="rack-line rack-head" style={columns}>
                        <span className="rack-idx" />
                        {shelves.map((shelf) => <span className="rack-idx" key={shelf}>{shelf}</span>)}
                    </div>
                    {racks.map((rack) => (
                        <div className="rack-line" key={rack} style={columns}>
                            <span className="rack-idx">{rack}</span>
                            {shelves.map((shelf) => {
                                const slotPlaces = slots.get(`${rack}|${shelf}`) || [];

                                if (slotPlaces.length) {
                                    return slotPlaces.map(cellButton);
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
                    <div className={hitCodes.size ? 'file-bins is-filtering' : 'file-bins'}>
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
