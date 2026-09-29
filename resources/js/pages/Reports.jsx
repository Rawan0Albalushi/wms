import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { formatDate, formatQty, label } from '../api';
import { Condition, Load, PageHead, Pager, Status, useData, usePaged, Who } from '../components/ui';
import { useLocale } from '../i18n';

const tabs = ['current', 'by_location', 'movements', 'used', 'batches', 'low', 'counts'];

export default function Reports() {
    const { t, locale } = useLocale();
    const [params] = useSearchParams();
    const initial = tabs.includes(params.get('tab')) ? params.get('tab') : 'current';
    const [tab, setTab] = useState(initial);
    const { data, loading, error } = useData('/reports');

    return (
        <>
            <PageHead lane={11} title={t('nav.reports')} text={t('page.reports')} />
            <div className="sheet">
                <div className="tabs" role="tablist">
                    {tabs.map((key) => (
                        <button key={key} type="button" role="tab" aria-selected={tab === key} className={tab === key ? 'on' : ''} onClick={() => setTab(key)}>
                            {t(`report.${key}`)}
                        </button>
                    ))}
                </div>
                <p className="sheet-note">{t(`report.${tab}.hint`)}</p>
                <Load loading={loading} error={error}>
                    {data ? <Report tab={tab} data={data} locale={locale} t={t} /> : null}
                </Load>
            </div>
        </>
    );
}

function Report({ tab, data, locale, t }) {
    if (tab === 'current') {
        return (
            <PagedTable rows={data.current} resetKey={tab} heads={[t('item'), t('part'), t('on_hand'), t('available'), t('value')]} numeric={[2, 3, 4]} render={(row) => (
                <tr key={row.code}>
                    <td><Who code={row.code} name={label(row, locale)} image={row.image_url} /></td>
                    <td className="mono">{row.part_number || '—'}</td>
                    <td className="num">{formatQty(row.on_hand)}</td>
                    <td className="num">{formatQty(row.available)}</td>
                    <td className="num">{formatQty(row.value)}</td>
                </tr>
            )} />
        );
    }

    if (tab === 'by_location') {
        return (
            <PagedTable rows={data.by_location} resetKey={tab} heads={[t('location'), t('item'), t('batch'), t('qty'), t('condition')]} numeric={[3]} render={(row) => (
                <tr key={`${row.location || 'none'}-${row.batch}`}>
                    <td className="mono">{row.location || '—'}</td>
                    <td><Who code={row.item} name={label(row, locale)} image={row.image_url} /></td>
                    <td className="mono">{row.batch}</td>
                    <td className="num">{formatQty(row.quantity)}</td>
                    <td><Condition value={row.condition} /></td>
                </tr>
            )} />
        );
    }

    if (tab === 'movements' || tab === 'used') {
        const rows = tab === 'used' ? data.used : data.movements;

        return (
            <PagedTable rows={rows} resetKey={tab} heads={[t('date'), t('item'), t('batch'), t('from'), t('to'), t('qty'), t('reason'), t('employee')]} numeric={[5]} render={(row) => (
                <tr key={row.id}>
                    <td className="mono">{formatDate(row.happened_at)}</td>
                    <td><Who code={row.item} name={label(row, locale)} image={row.image_url} /></td>
                    <td className="mono">{row.batch}</td>
                    <td className="mono">{row.from || '—'}</td>
                    <td className="mono">{row.to || '—'}</td>
                    <td className="num">{formatQty(row.signed)}</td>
                    <td>{t(`reason.${row.reason}`)} {row.reference || ''}</td>
                    <td>{row.employee || '—'}</td>
                </tr>
            )} />
        );
    }

    if (tab === 'batches') {
        return (
            <PagedTable rows={data.batches} resetKey={tab} heads={[t('batch'), t('item'), t('location'), t('on_hand'), t('remaining'), t('condition'), t('received_on')]} numeric={[3, 4]} render={(row) => (
                <tr key={row.id}>
                    <td className="mono">{row.batch}</td>
                    <td><Who code={row.item} name={label(row, locale)} image={row.image_url} /></td>
                    <td className="mono">{row.location || '—'}</td>
                    <td className="num">{formatQty(row.quantity)}</td>
                    <td className="num">{formatQty(row.remaining)}</td>
                    <td><Condition value={row.condition} /></td>
                    <td className="mono">{formatDate(row.received_on)}</td>
                </tr>
            )} />
        );
    }

    if (tab === 'low') {
        return (
            <PagedTable rows={data.low} resetKey={tab} heads={[t('item'), t('on_hand'), t('min')]} numeric={[1, 2]} render={(row) => (
                <tr key={row.code}>
                    <td><Who code={row.code} name={label(row, locale)} image={row.image_url} /></td>
                    <td className="num">{formatQty(row.on_hand)}</td>
                    <td className="num">{formatQty(row.min_level)}</td>
                </tr>
            )} />
        );
    }

    return (
        <div>
            {data.counts.map((count) => <CountLines key={count.id} count={count} locale={locale} t={t} />)}
        </div>
    );
}

function CountLines({ count, locale, t }) {
    const page = usePaged(count.lines, count.id);

    return (
        <section className="nested-block">
            <div className="sheet-h">
                <h2><span className="mono">{count.number}</span></h2>
                <Status value={count.status} />
            </div>
            <Table heads={[t('item'), t('location'), t('batch'), t('system'), t('counted'), t('variance')]} numeric={[3, 4, 5]}>
                {page.rows.map((line) => (
                    <tr key={line.id}>
                        <td><Who code={line.item_code} name={label(line, locale)} image={line.image_url} /></td>
                        <td className="mono">{line.location || '—'}</td>
                        <td className="mono">{line.batch_no}</td>
                        <td className="num">{formatQty(line.system_qty)}</td>
                        <td className="num">{line.counted_qty === null ? '—' : formatQty(line.counted_qty)}</td>
                        <td className="num">{line.variance === null ? '—' : formatQty(line.variance)}</td>
                    </tr>
                ))}
            </Table>
            <Pager page={page.page} pages={page.pages} total={page.total} from={page.from} to={page.to} onPage={page.setPage} />
        </section>
    );
}

function PagedTable({ rows, resetKey, heads, numeric, render }) {
    const page = usePaged(rows, resetKey);

    return (
        <>
            <Table heads={heads} numeric={numeric}>
                {page.rows.map(render)}
            </Table>
            <Pager page={page.page} pages={page.pages} total={page.total} from={page.from} to={page.to} onPage={page.setPage} />
        </>
    );
}

function Table({ heads, numeric = [], children }) {
    return (
        <div className="table-wrap">
            <table>
                <thead>
                    <tr>
                        {heads.map((head, index) => (
                            <th key={`${head}-${index}`} scope="col" className={numeric.includes(index) ? 'num' : ''}>{head}</th>
                        ))}
                    </tr>
                </thead>
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}
