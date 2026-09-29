import { cloneElement, useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import { api, errorText, label } from '../api';
import { useLocale } from '../i18n';
import Icon from './Icons';

export function useData(path) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(false);
    const [tick, setTick] = useState(0);

    useEffect(() => {
        let live = true;
        setError(false);
        api(path)
            .then((next) => {
                if (live) {
                    setData(next);
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
    }, [path, tick]);

    return {
        data,
        error,
        loading: data === null && !error,
        reload: () => setTick((value) => value + 1),
    };
}

export function Load({ loading, error, children }) {
    const { t } = useLocale();

    if (loading) {
        return <p className="empty">{t('loading')}</p>;
    }

    if (error) {
        return <p className="banner" role="alert">{t('errors.generic')}</p>;
    }

    return children;
}

export function Thumb({ src, alt = '', className = 'thumb' }) {
    if (src) {
        return <img className={className} src={src} alt={alt} />;
    }

    return <span className={`${className} thumb-empty`} aria-hidden="true" />;
}

export function Who({ code, name, to, image }) {
    const text = code || '—';

    return (
        <div className={image !== undefined ? 'who who-photo' : 'who'}>
            {image !== undefined ? <Thumb src={image} /> : null}
            <div className="who-copy">
                {to ? <Link className="mono ref" to={to}>{text}</Link> : <span className="mono">{text}</span>}
                {name ? <span>{name}</span> : null}
            </div>
        </div>
    );
}

export function PhotoField({ current = '', hint = 'photo.item_hint' }) {
    const { t } = useLocale();
    const inputId = useId();
    const inputRef = useRef(null);
    const blobRef = useRef('');
    const [preview, setPreview] = useState(current || '');
    const [fileName, setFileName] = useState('');
    const [remove, setRemove] = useState(false);

    useEffect(() => () => {
        if (blobRef.current) {
            URL.revokeObjectURL(blobRef.current);
        }
    }, []);

    function clearBlob() {
        if (blobRef.current) {
            URL.revokeObjectURL(blobRef.current);
            blobRef.current = '';
        }
    }

    function onFile(event) {
        const file = event.target.files?.[0];
        clearBlob();
        setRemove(false);
        setFileName(file ? file.name : '');

        if (!file) {
            setPreview(current || '');

            return;
        }

        const url = URL.createObjectURL(file);
        blobRef.current = url;
        setPreview(url);
    }

    function onRemove(event) {
        const next = event.target.checked;
        setRemove(next);
        clearBlob();

        if (inputRef.current) {
            inputRef.current.value = '';
        }

        setFileName('');
        setPreview(next ? '' : (current || ''));
    }

    const shown = remove ? '' : preview;

    return (
        <div className="field span-2 photo-span">
            <span id={`${inputId}-label`}>{t('photo')}</span>
            <div className="photo-field">
                {shown ? <img className="portrait" src={shown} alt="" /> : <span className="portrait portrait-empty">{t('photo.empty')}</span>}
                <div className="stack">
                    <input
                        ref={inputRef}
                        id={inputId}
                        className="file-input"
                        name="image"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        aria-labelledby={`${inputId}-label`}
                        onChange={onFile}
                    />
                    <label className="btn ghost" htmlFor={inputId}>{t('photo.choose')}</label>
                    <p className="hint">{fileName || t(hint)}</p>
                    {current ? (
                        <label className="check">
                            <input type="checkbox" name="remove_image" checked={remove} onChange={onRemove} />
                            {t('photo.remove')}
                        </label>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

export function ItemSelect({ id, items, locale, name, value, defaultValue = '', onChange, required = false }) {
    const controlled = value !== undefined;
    const [local, setLocal] = useState(defaultValue);
    const current = controlled ? value : local;
    const item = items.find((row) => String(row.id) === String(current));

    return (
        <div className="pick">
            {item ? <Thumb src={item.image_url} /> : <span className="pick-gap" aria-hidden="true" />}
            <select
                id={id}
                name={name}
                required={required}
                {...(controlled ? { value: value || '' } : { defaultValue })}
                onChange={(event) => {
                    if (!controlled) {
                        setLocal(event.target.value);
                    }

                    onChange?.(event);
                }}
            >
                <option value="" />
                {items.map((row) => (
                    <option key={row.id} value={row.id}>{row.code} {label(row, locale)}</option>
                ))}
            </select>
        </div>
    );
}

export function StockSelect({ id, positions, locale, name = 'position_id', defaultValue = '' }) {
    const [picked, setPicked] = useState(defaultValue);
    const row = positions.find((item) => String(item.id) === String(picked));

    return (
        <div className="pick">
            {row ? <Thumb src={row.image_url} /> : <span className="pick-gap" aria-hidden="true" />}
            <select id={id} name={name} required defaultValue={defaultValue} onChange={(event) => setPicked(event.target.value)}>
                <option value="" />
                {positions.map((item) => (
                    <option key={item.id} value={item.id}>
                        {item.batch_no} {item.item_code} {label(item, locale)} @ {item.location || '—'} ({item.available})
                    </option>
                ))}
            </select>
        </div>
    );
}

export function PlaceSelect({ id, locations, name = 'location_id', defaultValue = '', required = false }) {
    const [picked, setPicked] = useState(defaultValue);
    const row = locations.find((item) => String(item.id) === String(picked));

    return (
        <div className="pick">
            {row ? <Thumb src={row.image_url} /> : <span className="pick-gap" aria-hidden="true" />}
            <select id={id} name={name} required={required} defaultValue={defaultValue} onChange={(event) => setPicked(event.target.value)}>
                <option value="" />
                {locations.map((location) => <option key={location.id} value={location.id}>{location.code}</option>)}
            </select>
        </div>
    );
}

export function PlaceBanner({ place, onPhoto }) {
    const { t } = useLocale();

    if (!place || (!place.image_url && !onPhoto)) {
        return null;
    }

    return (
        <div className="place-photo">
            <Thumb src={place.image_url} className="place-shot" />
            <div>
                <strong className="mono">{place.code}</strong>
                <span>{place.image_url ? t(`kind.${place.kind}`) : t('photo.location_hint')}</span>
            </div>
            {onPhoto ? (
                <button type="button" className="btn ghost small" onClick={() => onPhoto(place)}>
                    {place.image_url ? t('photo.replace') : t('photo.add')}
                </button>
            ) : null}
        </div>
    );
}

export function Facts({ items }) {
    return (
        <dl className="facts">
            {items.map((item) => (
                <div key={item.label}>
                    <dt>{item.label}</dt>
                    <dd>{item.value}</dd>
                </div>
            ))}
        </dl>
    );
}

export function Metrics({ items }) {
    return (
        <div className="metrics">
            {items.map((item) => (
                <div key={item.label} className={item.alert ? 'metric-alert' : undefined}>
                    <span>{item.label}</span>
                    <b>{item.value}</b>
                </div>
            ))}
        </div>
    );
}

export function SheetBar({ title, count, children }) {
    const { t } = useLocale();

    return (
        <div className="sheet-h">
            <div className="sheet-title">
                {title ? <h2>{title}</h2> : null}
                {typeof count === 'number' ? <span className="count">{count} {t('records')}</span> : null}
            </div>
            {children ? <div className="sheet-tools">{children}</div> : null}
        </div>
    );
}

export function PageHead({ title, text, action }) {
    return (
        <header className="page-head">
            <div>
                <h1>{title}</h1>
                {text ? <p>{text}</p> : null}
            </div>
            {action}
        </header>
    );
}

export function Field({ label, children }) {
    const id = useId();

    return (
        <label className="field" htmlFor={id}>
            <span>{label}</span>
            {cloneElement(children, { id })}
        </label>
    );
}

export function RecordView({ image, imageAlt = '', title, note, figure, figureLabel, rows }) {
    return (
        <div className="record">
            {image ? <img className="record-photo" src={image} alt={imageAlt} /> : null}
            <div className="record-body">
                {title ? (
                    <div className="record-head">
                        <strong>{title}</strong>
                        {note ? <span>{note}</span> : null}
                    </div>
                ) : null}
                {figure !== undefined ? (
                    <div className="record-figure">
                        <b>{figure}</b>
                        <span>{figureLabel}</span>
                    </div>
                ) : null}
                <dl className="record-rows">
                    {rows.map((row) => (
                        <div key={row.label}>
                            <dt>{row.label}</dt>
                            <dd>{row.value}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </div>
    );
}

export function Panel({ title, onClose, compact = false, children }) {
    const { t } = useLocale();
    const titleId = useId();
    const dialogRef = useRef(null);

    useEffect(() => {
        const onKey = (event) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        window.addEventListener('keydown', onKey);
        const node = document.getElementById('shell');
        const previousOverflow = document.body.style.overflow;
        const previouslyFocused = document.activeElement;

        if (node) {
            node.inert = true;
        }

        document.body.style.overflow = 'hidden';
        dialogRef.current?.focus();

        return () => {
            window.removeEventListener('keydown', onKey);
            document.body.style.overflow = previousOverflow;

            if (node) {
                node.inert = false;
            }

            if (previouslyFocused instanceof HTMLElement) {
                previouslyFocused.focus();
            }
        };
    }, [onClose]);

    return createPortal(
        <div className="shade" onMouseDown={onClose}>
            <div
                ref={dialogRef}
                className={compact ? 'panel panel-compact' : 'panel'}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                tabIndex={-1}
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="panel-h">
                    <h2 id={titleId}>{title}</h2>
                    <button type="button" className="btn ghost" onClick={onClose}>{t('close')}</button>
                </header>
                {children}
            </div>
        </div>,
        document.body,
    );
}

export function RowActions({ to, onView, onEdit, onDelete }) {
    const { t } = useLocale();
    const [armed, setArmed] = useState(false);
    const [pending, setPending] = useState(false);

    async function confirm() {
        setPending(true);

        try {
            await onDelete();
        } finally {
            setPending(false);
            setArmed(false);
        }
    }

    if (armed) {
        return (
            <div className="row-actions">
                <span className="confirm-inline">{t('confirm.short')}</span>
                <button type="button" className="icon-btn danger" aria-label={t('confirm.delete')} disabled={pending} onClick={confirm}>
                    <Icon name="check" />
                </button>
                <button type="button" className="icon-btn" aria-label={t('close')} disabled={pending} onClick={() => setArmed(false)}>
                    <Icon name="x" />
                </button>
            </div>
        );
    }

    return (
        <div className="row-actions">
            {to ? (
                <Link className="icon-btn" to={to} aria-label={t('btn.view')}><Icon name="eye" /></Link>
            ) : (
                <button type="button" className="icon-btn" aria-label={t('btn.view')} onClick={onView}><Icon name="eye" /></button>
            )}
            {onEdit ? <button type="button" className="icon-btn" aria-label={t('btn.edit')} onClick={onEdit}><Icon name="edit" /></button> : null}
            {onDelete ? <button type="button" className="icon-btn danger" aria-label={t('btn.delete')} onClick={() => setArmed(true)}><Icon name="trash" /></button> : null}
        </div>
    );
}

export function Editor({ title, onClose, onSave, children }) {
    const { t } = useLocale();
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);
    const [done, setDone] = useState(null);

    async function submit(event) {
        event.preventDefault();
        setPending(true);
        setError('');

        try {
            const result = await onSave(event.currentTarget);

            if (result?.done) {
                setDone(result.done);
            } else {
                onClose();
            }
        } catch (err) {
            setError(errorText(err, t));
        } finally {
            setPending(false);
        }
    }

    return (
        <Panel title={done ? done.ref : title} onClose={onClose}>
            {done ? (
                <div className="panel-b stack">
                    <p>{t(done.hint)}</p>
                    {done.next ? <Link className="btn" to={done.next.href}>{t(done.next.label)}</Link> : null}
                    <button type="button" className="btn ghost" onClick={onClose}>{t('close')}</button>
                </div>
            ) : (
                <form className="panel-form" onSubmit={submit}>
                    <div className="panel-b stack">
                        {error ? <div className="banner" role="alert">{error}</div> : null}
                        {children}
                    </div>
                    <div className="panel-f">
                        <div className="panel-f-actions">
                            <button className="btn" type="submit" disabled={pending}>{t('save')}</button>
                        </div>
                    </div>
                </form>
            )}
        </Panel>
    );
}

export function Status({ value }) {
    const { t } = useLocale();

    return <span className={`status status-${value}`}><i />{t(`status.${value}`)}</span>;
}

export function Condition({ value }) {
    const { t } = useLocale();

    return <span className={`cond cond-${value}`}><i />{t(`cond.${value}`)}</span>;
}

export function Barcode({ value }) {
    const bits = String(value || '').split('').flatMap((char) => {
        const n = Number(char);

        return [Number.isNaN(n) ? 2 : (n % 3) + 1, 1];
    });
    let cursor = 0;
    const bars = bits.map((width, index) => {
        const bar = index % 2 === 0 ? { x: cursor, width } : null;
        cursor += width + 1;

        return bar;
    }).filter(Boolean);

    return (
        <svg className="barcode" viewBox={`0 0 ${Math.max(cursor, 1)} 36`} aria-hidden="true">
            {bars.map((bar) => <rect key={bar.x} x={bar.x} y="0" width={bar.width} height="36" fill="#152033" />)}
        </svg>
    );
}

export function Lines({ lines, setLines, items, locale }) {
    const { t } = useLocale();

    return (
        <div className="span-2 stack">
            {lines.map((line, index) => (
                <div className="lines" key={index}>
                    <Field label={t('item')}>
                        <ItemSelect
                            items={items}
                            locale={locale}
                            value={line.item_id}
                            required
                            onChange={(event) => {
                                const next = [...lines];
                                next[index] = { ...line, item_id: event.target.value };
                                setLines(next);
                            }}
                        />
                    </Field>
                    <Field label={t('qty')}>
                        <input type="number" min="1" value={line.quantity} required onChange={(event) => {
                            const next = [...lines];
                            next[index] = { ...line, quantity: event.target.value };
                            setLines(next);
                        }} />
                    </Field>
                </div>
            ))}
            <button type="button" className="btn ghost" onClick={() => setLines([...lines, { item_id: '', quantity: '' }])}>{t('btn.add_line')}</button>
        </div>
    );
}
