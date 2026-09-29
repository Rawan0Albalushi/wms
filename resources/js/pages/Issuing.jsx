import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatDate, formatQty, label, ping, readForm } from '../api';
import { Editor, Field, Load, PageHead, SheetBar, StockSelect, useData, Who } from '../components/ui';
import { useLocale } from '../i18n';

export default function Issuing() {
    const { t, locale } = useLocale();
    const [params] = useSearchParams();
    const [open, setOpen] = useState(params.get('new') === '1');
    const options = useData('/options');
    const stock = useData('/inventory');
    const rows = (stock.data?.movements || []).filter((row) => row.type === 'out' || row.type === 'consume');

    return (
        <>
            <PageHead
                lane={5}
                title={t('nav.issuing')}
                text={t('page.issuing')}
                action={<button type="button" className="btn" onClick={() => setOpen(true)}>{t('btn.issue')}</button>}
            />
            <Load loading={options.loading || stock.loading} error={options.error || stock.error}>
                <div className="sheet">
                    <SheetBar title={t('list.issues')} count={rows.length} />
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{t('date')}</th>
                                    <th scope="col">{t('item')}</th>
                                    <th scope="col">{t('batch')}</th>
                                    <th scope="col">{t('from')}</th>
                                    <th className="num" scope="col">{t('qty')}</th>
                                    <th scope="col">{t('reason')}</th>
                                    <th scope="col">{t('reference')}</th>
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
                                        <td className="num">{formatQty(Math.abs(row.signed))}</td>
                                        <td>{t(`reason.${row.reason}`)}</td>
                                        <td>{row.reference || row.department || '—'}</td>
                                        <td>{row.employee || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {rows.length === 0 ? <p className="empty">{t('empty')}</p> : null}
                    </div>
                </div>
            </Load>
            {open && options.data ? (
                <Editor title={t('btn.issue')} onClose={() => setOpen(false)} onSave={async (form) => {
                    await api('/issues', { method: 'POST', body: readForm(form) });
                    ping();
                    await stock.reload();
                    await options.reload();
                }}>
                    <div className="form-grid">
                        <Field label={t('batch')}>
                            <StockSelect positions={options.data.positions.filter((row) => row.available > 0)} locale={locale} />
                        </Field>
                        <Field label={t('qty')}><input name="quantity" type="number" min="1" required /></Field>
                        <Field label={t('reason')}>
                            <select name="reason" defaultValue="maintenance">
                                <option value="maintenance">{t('reason.maintenance')}</option>
                                <option value="other">{t('reason.other')}</option>
                            </select>
                        </Field>
                        <Field label={t('department')}><input name="department" /></Field>
                        <Field label={t('reference')}><input name="reference" /></Field>
                    </div>
                </Editor>
            ) : null}
        </>
    );
}
