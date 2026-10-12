import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api, formatDate, formatQty, label, matchesText, ping, readForm } from '../api';
import { useAuth } from '../auth';
import { Editor, Field, Lines, Load, PageHead, Pager, SheetBar, Status, useData, usePaged, Who } from '../components/ui';
import { useLocale } from '../i18n';

export default function Procurement() {
    const { t, locale } = useLocale();
    const { can } = useAuth();
    const manage = can('procurement.manage');
    const [params, setParams] = useSearchParams();
    const mode = manage && ['request', 'order', 'shipment'].includes(params.get('new')) ? params.get('new') : null;
    const [lines, setLines] = useState([{ item_id: '', quantity: '' }]);
    const [q, setQ] = useState('');
    const [kind, setKind] = useState('');
    const { data, loading, error, reload } = useData('/procurement');
    const options = useData('/options');
    const orders = usePaged((data?.orders || []).filter((order) => (!kind || (order.kind || 'receipt') === kind) && matchesText(q, [order.number, order.supplier, order.supplier_en, order.notes, order.status, ...(order.lines || []).flatMap((line) => [line.item_code, line.name_ar, line.name_en])])), `${q}|${kind}`);
    const shipments = usePaged((data?.shipments || []).filter((row) => matchesText(q, [row.ci_number, row.order_number, row.status])), q);

    function openMode(next) {
        const query = new URLSearchParams(params);
        query.set('new', next);
        setParams(query);
    }

    function close() {
        const next = new URLSearchParams(params);
        ['new', 'request', 'order', 'item', 'qty'].forEach((key) => next.delete(key));
        setParams(next, { replace: true });
    }

    async function save(path, body) {
        const saved = await api(path, { method: 'POST', body });
        ping();
        setLines([{ item_id: '', quantity: '' }]);
        await reload();

        return saved;
    }

    return (
        <>
            <PageHead lane={2} title={t('nav.procurement')} text={t('page.procurement')} action={manage ? (
                <div className="actions">
                    <button type="button" className="btn ghost" onClick={() => openMode('request')}>{t('btn.request')}</button>
                    <button type="button" className="btn ghost" onClick={() => openMode('order')}>{t('btn.order')}</button>
                    <button type="button" className="btn" onClick={() => openMode('shipment')}>{t('btn.shipment')}</button>
                </div>
            ) : null} />
            <Load loading={loading} error={error}>
                {data ? (
                    <div className="stack">
                            <section className="sheet">
                                <SheetBar title={t('btn.request')} count={data.requests.length} />
                                <Simple rows={data.requests} numeric={[2]} render={(row) => (
                                    <>
                                        <td className="mono">{row.number}</td>
                                        <td><Who code={row.item_code} name={label(row, locale)} image={row.image_url} /></td>
                                        <td className="num">{formatQty(row.quantity)}</td>
                                        <td><Status value={row.status} /></td>
                                    </>
                                )} heads={[t('reference'), t('item'), t('qty'), t('status')]} />
                            </section>
                            <section className="sheet">
                                <SheetBar title={t('btn.order')} count={orders.total}>
                                    <input className="search" value={q} onChange={(event) => setQ(event.target.value)} placeholder={t('search')} aria-label={t('search')} />
                                    <select className="filter" value={kind} onChange={(event) => setKind(event.target.value)} aria-label={t('filter.orders')}>
                                        <option value="">{t('filter.orders')}</option>
                                        <option value="receipt">{t('kind.receipt')}</option>
                                        <option value="future">{t('kind.future')}</option>
                                    </select>
                                </SheetBar>
                                <div className="table-wrap">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th scope="col">{t('order')}</th>
                                                <th scope="col">{t('supplier')}</th>
                                                <th scope="col">{t('item')}</th>
                                                <th className="num" scope="col">{t('remaining')}</th>
                                                <th scope="col">{t('status')}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {orders.rows.map((order) => (
                                                <tr key={order.id}>
                                                    <td><Link className="mono ref" to={`/procurement/orders/${order.id}`}>{order.number}</Link></td>
                                                    <td>{locale === 'ar' ? order.supplier : order.supplier_en}</td>
                                                    <td>
                                                        <div className="stack-cell">
                                                            {order.lines.map((line) => (
                                                                <Who key={line.item_id} code={line.item_code} name={label(line, locale)} image={line.image_url} />
                                                            ))}
                                                        </div>
                                                    </td>
                                                    <td className="num">
                                                        <div className="stack-cell">
                                                            {order.lines.map((line) => <span key={line.item_id}>{formatQty(line.remaining)}</span>)}
                                                        </div>
                                                    </td>
                                                    <td><Status value={order.status} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <Pager page={orders.page} pages={orders.pages} total={orders.total} from={orders.from} to={orders.to} onPage={orders.setPage} />
                            </section>
                        <section className="sheet">
                            <SheetBar title={t('btn.shipment')} count={data.shipments.length} />
                            <div className="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th scope="col">{t('ci')}</th>
                                            <th scope="col">{t('order')}</th>
                                            <th scope="col">{t('expected')}</th>
                                            <th scope="col">{t('status')}</th>
                                            <th scope="col">{t('actions')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {shipments.rows.map((row) => (
                                            <tr key={row.id}>
                                                <td><Link className="mono ref" to={`/procurement/shipments/${row.id}`}>{row.ci_number}</Link></td>
                                                <td className="mono">{row.order_number}</td>
                                                <td className="mono">{formatDate(row.expected_on)}</td>
                                                <td><Status value={row.status} /></td>
                                                <td>
                                                    {manage && row.status === 'in_transit' ? (
                                                        <button type="button" className="btn small" onClick={async () => {
                                                            await api(`/shipments/${row.id}/arrive`, { method: 'POST', body: {} });
                                                            ping();
                                                            await reload();
                                                        }}>{t('btn.arrive')}</button>
                                                    ) : '—'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <Pager page={shipments.page} pages={shipments.pages} total={shipments.total} from={shipments.from} to={shipments.to} onPage={shipments.setPage} />
                        </section>
                    </div>
                ) : null}
            </Load>
            {mode && options.data ? (
                <Editor key={`${mode}-${params.get('request') || ''}-${params.get('order') || ''}-${params.get('item') || ''}`} title={t(`btn.${mode}`)} onClose={close} onSave={async (form) => {
                    const body = readForm(form);

                    if (mode === 'request') {
                        const saved = await save('/requests', body);

                        return { done: { ref: saved.number, hint: 'saved.request', next: { href: `/procurement?new=order&request=${saved.id}`, label: 'btn.order' } } };
                    }

                    if (mode === 'order') {
                        const saved = await save('/orders', {
                            ...body,
                            lines: lines.filter((line) => line.item_id && line.quantity),
                        });

                        return { done: { ref: saved.number, hint: 'saved.order', next: { href: `/procurement?new=shipment&order=${saved.id}`, label: 'btn.shipment' } } };
                    }

                    if (mode === 'shipment') {
                        const saved = await save('/shipments', body);

                        return { done: { ref: saved.number, hint: 'saved.shipment', next: { href: `/procurement/shipments/${saved.id}`, label: 'open' } } };
                    }

                    return undefined;
                }}>
                    {mode === 'request' ? (
                        <div className="form-grid">
                            <Field label={t('item')}>
                                <select name="item_id" required defaultValue={params.get('item') || ''}>
                                    <option value="" />
                                    {options.data.items.map((item) => <option key={item.id} value={item.id}>{item.code} {label(item, locale)}</option>)}
                                </select>
                            </Field>
                            <Field label={t('qty')}><input name="quantity" type="number" min="1" required defaultValue={params.get('qty') || ''} /></Field>
                            <Field label={t('note')}><input name="note" className="span-2" /></Field>
                        </div>
                    ) : null}
                    {mode === 'order' ? (
                        <div className="form-grid">
                            <Field label={t('supplier')}>
                                <select name="supplier_id" required defaultValue="">
                                    <option value="" />
                                    {options.data.suppliers.map((supplier) => (
                                        <option key={supplier.id} value={supplier.id}>{label(supplier, locale, 'name', 'name_en')}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label={t('btn.request')}>
                                <select name="purchase_request_id" defaultValue={params.get('request') || ''}>
                                    <option value="" />
                                    {(data?.requests || []).filter((row) => row.status === 'open').map((row) => (
                                        <option key={row.id} value={row.id}>{row.number}</option>
                                    ))}
                                </select>
                            </Field>
                            <Lines lines={lines} setLines={setLines} items={options.data.items} locale={locale} />
                        </div>
                    ) : null}
                    {mode === 'shipment' ? (
                        <div className="form-grid">
                            <Field label={t('order')}>
                                <select name="purchase_order_id" required defaultValue={params.get('order') || ''}>
                                    <option value="" />
                                    {(data?.orders || []).filter((order) => order.kind !== 'future').map((order) => <option key={order.id} value={order.id}>{order.number}</option>)}
                                </select>
                            </Field>
                            <Field label={t('ci')}><input name="ci_number" required /></Field>
                            <Field label={t('expected')}><input name="expected_on" type="date" required /></Field>
                            <Field label={t('packing')}><input name="packing_note" /></Field>
                        </div>
                    ) : null}
                </Editor>
            ) : null}
        </>
    );
}

function Simple({ rows, heads, numeric = [], render }) {
    const page = usePaged(rows, rows.length);

    return (
        <>
            <div className="table-wrap">
                <table>
                    <thead>
                        <tr>{heads.map((head, index) => <th key={`${head}-${index}`} scope="col" className={numeric.includes(index) ? 'num' : ''}>{head}</th>)}</tr>
                    </thead>
                    <tbody>
                        {page.rows.map((row) => <tr key={row.id}>{render(row)}</tr>)}
                    </tbody>
                </table>
            </div>
            <Pager page={page.page} pages={page.pages} total={page.total} from={page.from} to={page.to} onPage={page.setPage} />
        </>
    );
}
