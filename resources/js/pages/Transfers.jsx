import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatDate, formatQty, label, ping, readForm } from '../api';
import { Editor, Field, Load, PageHead, PlaceSelect, SheetBar, StockSelect, useData, Who } from '../components/ui';
import { useLocale } from '../i18n';

export default function Transfers() {
    const { t, locale } = useLocale();
    const [params] = useSearchParams();
    const [open, setOpen] = useState(params.get('new') === '1');
    const options = useData('/options');
    const stock = useData('/inventory');
    const rows = (stock.data?.movements || []).filter((row) => row.type === 'transfer');

    return (
        <>
            <PageHead
                lane={6}
                title={t('nav.transfers')}
                text={t('page.transfers')}
                action={<button type="button" className="btn" onClick={() => setOpen(true)}>{t('btn.transfer')}</button>}
            />
            <Load loading={stock.loading} error={stock.error}>
                <div className="sheet">
                    <SheetBar title={t('list.transfers')} count={rows.length} />
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{t('date')}</th>
                                    <th scope="col">{t('item')}</th>
                                    <th scope="col">{t('batch')}</th>
                                    <th scope="col">{t('from')}</th>
                                    <th scope="col">{t('to')}</th>
                                    <th className="num" scope="col">{t('qty')}</th>
                                    <th scope="col">{t('employee')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.id}>
                                        <td className="mono">{formatDate(row.happened_at)}</td>
                                        <td><Who code={row.item} name={label(row, locale)} image={row.image_url} /></td>
                                        <td className="mono">{row.batch}</td>
                                        <td className="mono">{row.from || '—'}</td>
                                        <td className="mono">{row.to || '—'}</td>
                                        <td className="num">{formatQty(row.quantity)}</td>
                                        <td>{row.employee || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </Load>
            {open && options.data ? (
                <Editor key={params.get('position') || 'new'} title={t('btn.transfer')} onClose={() => setOpen(false)} onSave={async (form) => {
                    await api('/transfers', { method: 'POST', body: readForm(form) });
                    ping();
                    await stock.reload();
                    await options.reload();
                }}>
                    <div className="form-grid">
                        <Field label={t('batch')}>
                            <StockSelect positions={options.data.positions.filter((row) => row.available > 0)} locale={locale} defaultValue={params.get('position') || ''} />
                        </Field>
                        <Field label={t('qty')}><input name="quantity" type="number" min="1" required defaultValue={options.data.positions.find((row) => String(row.id) === params.get('position'))?.available || ''} /></Field>
                        <Field label={t('to')}>
                            <PlaceSelect name="to_location_id" locations={options.data.locations} required />
                        </Field>
                    </div>
                </Editor>
            ) : null}
        </>
    );
}
