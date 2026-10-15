import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, errorText, formatQty, label, ping, readForm, syncImage } from '../api';
import { useAuth } from '../auth';
import { Editor, Field, Load, PageHead, Pager, PhotoField, RowActions, SheetBar, useData, Who } from '../components/ui';
import { useLocale } from '../i18n';

export default function Items() {
    const { t, locale } = useLocale();
    const { can } = useAuth();
    const manage = can('items.manage');
    const [params] = useSearchParams();
    const [q, setQ] = useState('');
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState('');
    const [unit, setUnit] = useState('');
    const [family, setFamily] = useState('');
    const [place, setPlace] = useState('');
    const [placeQuery, setPlaceQuery] = useState('');
    const [page, setPage] = useState(1);
    const [open, setOpen] = useState(params.get('new') === '1' && manage);
    const [editing, setEditing] = useState(null);
    const [notice, setNotice] = useState('');
    const created = useRef(null);
    const filters = new URLSearchParams({ q: query, page: String(page), status, unit, family, place: placeQuery });
    const { data, loading, error, reload } = useData(`/items?${filters.toString()}`);
    const options = useData('/options?scope=catalog');
    const rows = data?.data || [];
    const families = data?.families || [];
    const units = data?.units || [];

    useEffect(() => {
        const timer = setTimeout(() => {
            setQuery(q);
            setPage(1);
        }, 200);

        return () => clearTimeout(timer);
    }, [q]);

    useEffect(() => {
        const timer = setTimeout(() => {
            setPlaceQuery(place);
            setPage(1);
        }, 200);

        return () => clearTimeout(timer);
    }, [place]);

    async function removeItem(id) {
        try {
            await api(`/items/${id}`, { method: 'DELETE' });
            setNotice('');
            ping();
            await reload();
        } catch (error) {
            setNotice(errorText(error, t));
        }
    }

    return (
        <>
            <PageHead
                lane={1}
                title={t('nav.items')}
                text={t('page.items')}
                action={manage ? <button type="button" className="btn" onClick={() => { setEditing(null); setOpen(true); }}>{t('btn.item')}</button> : null}
            />
            {notice ? <p className="banner" role="alert">{notice}</p> : null}
            <div className="sheet">
                <SheetBar count={data ? data.total : undefined}>
                    <input className="search" value={q} onChange={(event) => setQ(event.target.value)} placeholder={t('search')} aria-label={t('search')} />
                    <select className="filter" value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }} aria-label={t('filter.status')}>
                        <option value="">{t('filter.status')}</option>
                        <option value="rop">{t('stock.rop')}</option>
                        <option value="sufficient">{t('stock.sufficient')}</option>
                    </select>
                    <select className="filter" value={unit} onChange={(event) => { setUnit(event.target.value); setPage(1); }} aria-label={t('filter.unit')}>
                        <option value="">{t('filter.unit')}</option>
                        {units.map((code) => <option key={code} value={code}>{code}</option>)}
                    </select>
                    <select className="filter" value={family} onChange={(event) => { setFamily(event.target.value); setPage(1); }} aria-label={t('filter.family')}>
                        <option value="">{t('filter.family')}</option>
                        {families.map((name) => <option key={name} value={name}>{name}</option>)}
                    </select>
                    <input className="search filter" value={place} onChange={(event) => setPlace(event.target.value)} placeholder={t('filter.location')} aria-label={t('filter.location')} />
                </SheetBar>
                <Load loading={loading} error={error}>
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{t('item')}</th>
                                    <th scope="col">{t('part')}</th>
                                    <th className="num" scope="col">{t('on_hand')}</th>
                                    <th className="num" scope="col">{t('available')}</th>
                                    <th className="num" scope="col">{t('min')}</th>
                                    <th scope="col">{t('filter.status')}</th>
                                    <th scope="col">{t('location')}</th>
                                    <th scope="col">{t('actions')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((item) => (
                                    <tr key={item.id}>
                                        <td><Who code={item.code} name={label(item, locale)} to={`/items/${item.id}`} image={item.image_url} /></td>
                                        <td className="mono">{item.part_number || '—'}{item.family ? <span className="cell-note">{item.family}</span> : null}</td>
                                        <td className="num">{formatQty(item.on_hand)}</td>
                                        <td className="num">{formatQty(item.available)}</td>
                                        <td className="num">{formatQty(item.min_level)}</td>
                                        <td>{item.stock_status ? t(`stock.${item.stock_status}`) : '—'}</td>
                                        <td className="mono">{[...new Set([...(item.locations || []), ...(item.recorded_locations || [])])].join(' / ') || '—'}</td>
                                        <td>
                                            <RowActions
                                                to={`/items/${item.id}`}
                                                onEdit={manage ? () => { setOpen(false); setEditing(item); } : undefined}
                                                onDelete={manage ? () => removeItem(item.id) : undefined}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {data && rows.length === 0 ? <p className="empty">{t('empty')}</p> : null}
                    </div>
                    <Pager page={page} pages={data?.pages || 1} total={data?.total || 0} from={data?.from || 0} to={data?.to || 0} onPage={setPage} />
                </Load>
            </div>
            {open && manage && options.data ? (
                <Editor title={t('btn.item')} onClose={() => { created.current = null; setOpen(false); }} onSave={async (form) => {
                    const body = readForm(form);
                    const saved = created.current
                        ? await api(`/items/${created.current}`, { method: 'PATCH', body })
                        : await api('/items', { method: 'POST', body });
                    created.current = saved.id;
                    await syncImage(form, `/items/${saved.id}/image`);
                    ping();
                    await reload();

                    return { done: { ref: saved.code, hint: 'saved.item', next: { href: `/items/${saved.id}`, label: 'open' } } };
                }}>
                    <ItemFields options={options.data} locale={locale} />
                </Editor>
            ) : null}
            {editing && manage && options.data ? (
                <Editor
                    key={editing.id}
                    title={t('btn.edit')}
                    onClose={() => setEditing(null)}
                    onSave={async (form) => {
                        const body = readForm(form);
                        await api(`/items/${editing.id}`, { method: 'PATCH', body });
                        await syncImage(form, `/items/${editing.id}/image`);
                        ping();
                        await reload();
                    }}
                >
                    <ItemFields options={options.data} locale={locale} item={editing} />
                </Editor>
            ) : null}
        </>
    );
}

export function ItemFields({ options, locale, item }) {
    const { t } = useLocale();

    return (
        <div className="form-grid">
            <PhotoField current={item?.image_url || ''} />
            <Field label={t('item')}><input name="code" required defaultValue={item?.code || ''} /></Field>
            <Field label={t('part')}><input name="part_number" defaultValue={item?.part_number || ''} /></Field>
            <Field label={t('barcode')}><input name="barcode" defaultValue={item?.barcode || ''} /></Field>
            <Field label={t('name_ar')}><input name="name_ar" required defaultValue={item?.name_ar || ''} /></Field>
            <Field label={t('name_en')}><input name="name_en" required defaultValue={item?.name_en || ''} /></Field>
            <Field label={t('unit')}>
                <select name="unit_id" required defaultValue={item?.unit_id || ''}>
                    <option value="" />
                    {options.units.map((unit) => <option key={unit.id} value={unit.id}>{unit.code}</option>)}
                </select>
            </Field>
            <Field label={t('category')}>
                <select name="category_id" required defaultValue={item?.category_id || ''}>
                    <option value="" />
                    {options.categories.map((category) => (
                        <option key={category.id} value={category.id}>{label(category, locale)}</option>
                    ))}
                </select>
            </Field>
            <Field label={t('supplier')}>
                <select name="supplier_id" defaultValue={item?.supplier_id || ''}>
                    <option value="" />
                    {options.suppliers.map((supplier) => (
                        <option key={supplier.id} value={supplier.id}>{label(supplier, locale, 'name', 'name_en')}</option>
                    ))}
                </select>
            </Field>
            <Field label={t('min')}><input name="min_level" type="number" min="0" required defaultValue={item?.min_level ?? 0} /></Field>
            <Field label={t('cost')}><input name="unit_cost" type="number" min="0" required defaultValue={item?.unit_cost ?? 0} /></Field>
        </div>
    );
}
