import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, formatQty, label, ping, readForm } from '../api';
import { useAuth } from '../auth';
import { Editor, Field, Lines, Load, PageHead, SheetBar, Status, useData, Who } from '../components/ui';
import { useLocale } from '../i18n';

export default function Jobs() {
    const { t, locale } = useLocale();
    const { can } = useAuth();
    const manage = can('jobs.manage');
    const buy = can('procurement.manage');
    const [open, setOpen] = useState(false);
    const [lines, setLines] = useState([{ item_id: '', quantity: '' }]);
    const { data, loading, error, reload } = useData('/jobs');
    const options = useData('/options');

    return (
        <>
            <PageHead
                lane={7}
                title={t('nav.jobs')}
                text={t('page.jobs')}
                action={manage ? <button type="button" className="btn" onClick={() => setOpen(true)}>{t('btn.job')}</button> : null}
            />
            <Load loading={loading} error={error}>
                <div className="stack">
                        {(data?.jobs || []).length === 0 ? <p className="empty sheet">{t('empty')}</p> : null}
                        {(data?.jobs || []).map((job) => (
                            <section className="sheet" key={job.id}>
                                <div className="sheet-h">
                                    <div className="sheet-title">
                                        <h2><Link className="mono ref" to={`/jobs/${job.id}`}>{job.number}</Link></h2>
                                        <span className="quiet">{job.title}</span>
                                    </div>
                                    <div className="sheet-tools">
                                        <Status value={job.status} />
                                        {job.status !== 'closed' ? <Link className="btn small" to={`/jobs/${job.id}`}>{t('open')}</Link> : null}
                                    </div>
                                </div>
                                <div className="table-wrap">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th scope="col">{t('item')}</th>
                                                <th className="num" scope="col">{t('required')}</th>
                                                <th className="num" scope="col">{t('reserved')}</th>
                                                <th className="num" scope="col">{t('qty')}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {job.lines.map((line) => (
                                                <tr key={line.id}>
                                                    <td><Who code={line.item_code} name={label(line, locale)} image={line.image_url} /></td>
                                                    <td className="num">{formatQty(line.required_qty)} {line.unit}</td>
                                                    <td className="num">{formatQty(line.reserved_qty)}</td>
                                                    <td className="num">{formatQty(line.issued_qty)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        ))}
                    <section className="sheet">
                        <SheetBar title={t('dash.shortages')} count={(data?.shortages || []).length} />
                        <div className="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">{t('item')}</th>
                                        <th className="num" scope="col">{t('required')}</th>
                                        <th className="num" scope="col">{t('available')}</th>
                                        <th className="num" scope="col">{t('shortage')}</th>
                                        <th scope="col">{t('status')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(data?.shortages || []).map((row) => (
                                        <tr key={row.code}>
                                            <td className="mono">{row.code}</td>
                                            <td className="num">{formatQty(row.required)}</td>
                                            <td className="num">{formatQty(row.available)}</td>
                                            <td className="num">{formatQty(row.shortage)}</td>
                                            <td>
                                                {row.shortage > 0 && buy ? (
                                                    <Link className="btn ghost small" to={`/procurement?new=request&item=${row.item_id}&qty=${row.shortage}`}>{t('btn.request')}</Link>
                                                ) : <Status value={row.status} />}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </Load>
            {open && manage && options.data ? (
                <Editor title={t('btn.job')} onClose={() => setOpen(false)} onSave={async (form) => {
                    const body = readForm(form);
                    const saved = await api('/jobs', {
                        method: 'POST',
                        body: {
                            title: body.title,
                            lines: lines.filter((line) => line.item_id && line.quantity),
                        },
                    });
                    ping();
                    setLines([{ item_id: '', quantity: '' }]);
                    await reload();

                    return { done: { ref: saved.number, hint: 'saved.job', next: { href: `/jobs/${saved.id}`, label: 'btn.reserve' } } };
                }}>
                    <Field label={t('title')}><input name="title" required /></Field>
                    <Lines lines={lines} setLines={setLines} items={options.data.items} locale={locale} />
                </Editor>
            ) : null}
        </>
    );
}
