import { useEffect, useState } from 'react';
import { api, errorText, formatQty, label, ping } from '../api';
import { useAuth } from '../auth';
import { Load, PageHead, SheetBar, useData, Who } from '../components/ui';
import { useLocale } from '../i18n';

export default function Counts() {
    const { t, locale } = useLocale();
    const { can } = useAuth();
    const record = can('counts.manage');
    const approve = can('counts.approve');
    const { data, loading, error, reload } = useData('/counts');
    const [draft, setDraft] = useState({});
    const [message, setMessage] = useState('');
    const [pending, setPending] = useState(false);

    useEffect(() => {
        if (!data?.open) {
            return;
        }

        const next = {};
        data.open.lines.forEach((line) => {
            next[line.id] = line.counted_qty === null || line.counted_qty === undefined ? '' : String(line.counted_qty);
        });
        setDraft(next);
    }, [data]);

    async function run(task) {
        setPending(true);
        setMessage('');

        try {
            await task();
            ping();
            await reload();
        } catch (err) {
            setMessage(errorText(err, t));
        } finally {
            setPending(false);
        }
    }

    const open = data?.open;

    return (
        <>
            <PageHead
                lane={9}
                title={t('nav.counts')}
                text={t('page.counts')}
                action={!open && record ? <button type="button" className="btn" onClick={() => run(() => api('/counts', { method: 'POST', body: {} }))}>{t('btn.count')}</button> : null}
            />
            {message ? <div className="banner" role="alert">{message}</div> : null}
            <Load loading={loading} error={error}>
                {open ? (
                    <section className="sheet">
                        <SheetBar title={open.number} count={open.lines.length}>
                            <div className="actions">
                                {record ? <button type="button" className="btn ghost" disabled={pending} onClick={() => run(async () => {
                                    const lines = Object.entries(draft)
                                        .filter(([, value]) => value !== '')
                                        .map(([id, counted]) => ({ id: Number(id), counted_qty: Number(counted) }));
                                    await api(`/counts/${open.id}`, { method: 'PATCH', body: { lines } });
                                })}>{t('btn.save_count')}</button> : null}
                                {approve ? <button type="button" className="btn" disabled={pending} onClick={() => run(async () => {
                                    const lines = Object.entries(draft)
                                        .filter(([, value]) => value !== '')
                                        .map(([id, counted]) => ({ id: Number(id), counted_qty: Number(counted) }));

                                    if (record && lines.length) {
                                        await api(`/counts/${open.id}`, { method: 'PATCH', body: { lines } });
                                    }

                                    await api(`/counts/${open.id}/approve`, { method: 'POST', body: {} });
                                })}>{t('btn.approve')}</button> : null}
                            </div>
                        </SheetBar>
                        <p className="sheet-note">{t('count.need')}</p>
                        <div className="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">{t('item')}</th>
                                        <th scope="col">{t('location')}</th>
                                        <th scope="col">{t('batch')}</th>
                                        <th className="num" scope="col">{t('system')}</th>
                                        <th className="num" scope="col">{t('counted')}</th>
                                        <th className="num" scope="col">{t('variance')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {open.lines.map((line) => {
                                        const counted = draft[line.id];
                                        const variance = counted === '' || counted === undefined ? null : Number(counted) - line.system_qty;

                                        return (
                                            <tr key={line.id}>
                                                <td><Who code={line.item_code} name={label(line, locale)} image={line.image_url} /></td>
                                                <td className="mono">{line.location || '—'}</td>
                                                <td className="mono">{line.batch_no}</td>
                                                <td className="num">{formatQty(line.system_qty)}</td>
                                                <td className="num">
                                                    <input
                                                        aria-label={`${t('counted')} ${line.batch_no}`}
                                                        type="number"
                                                        min="0"
                                                        disabled={!record}
                                                        value={counted ?? ''}
                                                        onChange={(event) => setDraft({ ...draft, [line.id]: event.target.value })}
                                                    />
                                                </td>
                                                <td className="num">{variance === null ? '—' : formatQty(variance)}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ) : <p className="empty">{t('empty')}</p>}
            </Load>
        </>
    );
}
