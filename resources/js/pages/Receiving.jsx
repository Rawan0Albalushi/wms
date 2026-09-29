import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatDate, formatQty, label, ping, readForm } from '../api';
import { Editor, Field, ItemSelect, Load, PageHead, Pager, PlaceSelect, SheetBar, useData, usePaged, Who } from '../components/ui';
import { useLocale } from '../i18n';

export default function Receiving() {
    const { t, locale } = useLocale();
    const [params] = useSearchParams();
    const [open, setOpen] = useState(params.get('new') === '1' || Boolean(params.get('shipment')));
    const [shipmentId, setShipmentId] = useState(params.get('shipment') || '');
    const [orderId, setOrderId] = useState(params.get('order') || '');
    const options = useData('/options');
    const stock = useData('/inventory');
    const buying = useData('/procurement');
    const loading = options.loading || stock.loading || buying.loading;
    const error = options.error || stock.error || buying.error;
    const receipts = (stock.data?.movements || []).filter((row) => row.type === 'in');
    const page = usePaged(receipts, receipts.length);
    const order = (buying.data?.orders || []).find((row) => String(row.id) === orderId);
    const itemChoices = order && options.data
        ? options.data.items.filter((item) => order.lines.some((line) => line.item_id === item.id && line.remaining > 0))
        : (options.data?.items || []);
    const onlyItem = itemChoices.length === 1 ? String(itemChoices[0].id) : '';

    const openShipments = (buying.data?.shipments || []).filter((row) => row.status !== 'received' && (!orderId || String(row.order_id) === orderId));

    function chooseShipment(value) {
        setShipmentId(value);
        const row = (buying.data?.shipments || []).find((item) => String(item.id) === value);

        if (row) {
            setOrderId(String(row.order_id));
        }
    }

    function chooseOrder(value) {
        setOrderId(value);
        const row = (buying.data?.shipments || []).find((item) => String(item.id) === shipmentId);

        if (row && value && String(row.order_id) !== value) {
            setShipmentId('');
        }
    }

    return (
        <>
            <PageHead
                lane={3}
                title={t('nav.receiving')}
                text={t('page.receiving')}
                action={<button type="button" className="btn" onClick={() => setOpen(true)}>{t('btn.receive')}</button>}
            />
            <Load loading={loading} error={error}>
                <div className="sheet">
                    <SheetBar title={t('list.receipts')} count={receipts.length} />
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{t('date')}</th>
                                    <th scope="col">{t('item')}</th>
                                    <th scope="col">{t('batch')}</th>
                                    <th scope="col">{t('to')}</th>
                                    <th className="num" scope="col">{t('qty')}</th>
                                    <th scope="col">{t('reference')}</th>
                                    <th scope="col">{t('employee')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {page.rows.map((row) => (
                                    <tr key={row.id}>
                                        <td className="mono">{formatDate(row.happened_at)}</td>
                                        <td><Who code={row.item} name={label(row, locale)} image={row.image_url} /></td>
                                        <td className="mono">{row.batch}</td>
                                        <td className="mono">{row.to || '—'}</td>
                                        <td className="num">{formatQty(row.quantity)}</td>
                                        <td>{row.reference || '—'}</td>
                                        <td>{row.employee || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pager page={page.page} pages={page.pages} total={page.total} from={page.from} to={page.to} onPage={page.setPage} />
                </div>
            </Load>
            {open && options.data ? (
                <Editor key={`${shipmentId}-${orderId}`} title={t('btn.receive')} onClose={() => setOpen(false)} onSave={async (form) => {
                    const saved = await api('/receipts', { method: 'POST', body: readForm(form) });
                    ping();
                    await stock.reload();
                    await buying.reload();

                    if (!saved.location_id) {
                        return { done: { ref: saved.batch_no, hint: 'saved.receipt', next: { href: `/transfers?new=1&position=${saved.position_id}`, label: 'task.putaway' } } };
                    }

                    return { done: { ref: saved.batch_no, hint: 'saved.receipt' } };
                }}>
                    <div className="form-grid">
                        <Field label={t('item')}>
                            <ItemSelect name="item_id" items={itemChoices} locale={locale} defaultValue={onlyItem} required />
                        </Field>
                        <Field label={t('batch')}><input name="batch_no" required /></Field>
                        <Field label={t('qty')}><input name="quantity" type="number" min="1" required /></Field>
                        <Field label={t('condition')}>
                            <select name="condition" defaultValue="new">
                                <option value="new">{t('cond.new')}</option>
                                <option value="used">{t('cond.used')}</option>
                                <option value="damaged">{t('cond.damaged')}</option>
                            </select>
                        </Field>
                        <Field label={t('location')}>
                            <PlaceSelect locations={options.data.locations} />
                        </Field>
                        <Field label={t('supplier')}>
                            <select name="supplier_id" defaultValue="">
                                <option value="" />
                                {options.data.suppliers.map((supplier) => (
                                    <option key={supplier.id} value={supplier.id}>{label(supplier, locale, 'name', 'name_en')}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t('order')}>
                            <select name="purchase_order_id" value={orderId} onChange={(event) => chooseOrder(event.target.value)}>
                                <option value="" />
                                {(buying.data?.orders || []).filter((row) => row.status !== 'received').map((row) => (
                                    <option key={row.id} value={row.id}>{row.number}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t('ci')}>
                            <select name="shipment_id" value={shipmentId} onChange={(event) => chooseShipment(event.target.value)}>
                                <option value="" />
                                {openShipments.map((row) => (
                                    <option key={row.id} value={row.id}>{row.ci_number}</option>
                                ))}
                            </select>
                        </Field>
                        <Field label={t('reference')}><input name="reference" /></Field>
                        <Field label={t('received_on')}><input name="received_on" type="date" /></Field>
                        <Field label={t('expires_on')}><input name="expires_on" type="date" /></Field>
                    </div>
                </Editor>
            ) : null}
        </>
    );
}
