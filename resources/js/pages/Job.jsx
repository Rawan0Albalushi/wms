import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, errorText, formatQty, jobAction, label, ping } from '../api';
import { useAuth } from '../auth';
import { Load, PageHead, SheetBar, Status, useData, Who } from '../components/ui';
import { useLocale } from '../i18n';

export default function Job() {
    const { id } = useParams();
    const { t, locale } = useLocale();
    const { can } = useAuth();
    const { data, loading, error, reload } = useData('/jobs');
    const [message, setMessage] = useState('');
    const job = (data?.jobs || []).find((row) => row.id === Number(id));
    const action = jobAction(job);
    const shorts = (data?.shortages || []).filter((row) => row.shortage > 0 && job?.lines.some((line) => line.item_id === row.item_id));

    async function act(path) {
        setMessage('');

        try {
            await api(path, { method: 'POST', body: {} });
            ping();
            await reload();
        } catch (err) {
            setMessage(errorText(err, t));
        }
    }

    return (
        <>
            <PageHead
                title={job ? job.number : t('nav.jobs')}
                text={job ? job.title : t('page.job')}
                action={(
                    <div className="actions">
                        {action === 'reserve' && can('jobs.manage') ? <button type="button" className="btn" onClick={() => act(`/jobs/${job.id}/reserve`)}>{t('btn.reserve')}</button> : null}
                        {action === 'issue' && can('issuing.post') ? <button type="button" className="btn" onClick={() => act(`/jobs/${job.id}/issue`)}>{t('btn.issue_job')}</button> : null}
                        <Link className="btn ghost" to="/jobs">{t('btn.back')}</Link>
                    </div>
                )}
            />
            {message ? <div className="banner" role="alert">{message}</div> : null}
            <Load loading={loading} error={error}>
                {job ? (
                    <section className="sheet">
                        <SheetBar title={<span className="mono">{job.number}</span>} count={job.lines.length}>
                            <Status value={job.status} />
                        </SheetBar>
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
                        {shorts.length > 0 && can('procurement.manage') ? (
                            <div className="actions">
                                {shorts.map((row) => (
                                    <Link key={row.item_id} className="btn ghost" to={`/procurement?new=request&item=${row.item_id}&qty=${row.shortage}`}>{t('btn.request')} {row.code}</Link>
                                ))}
                            </div>
                        ) : null}
                    </section>
                ) : <p className="empty">{t('empty')}</p>}
            </Load>
        </>
    );
}
