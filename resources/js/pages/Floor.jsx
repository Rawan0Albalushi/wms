import { Link } from 'react-router-dom';
import { formatDate, formatQty, label } from '../api';
import { useAuth } from '../auth';
import { Load, PageHead, Status, useData, Who } from '../components/ui';
import { useLocale } from '../i18n';

const tones = {
    new: '#0b6b4f',
    used: '#c8880a',
    damaged: '#9f1239',
    open: '#c8880a',
    reserved: '#c8880a',
    partial_hold: '#9a4d12',
    in_progress: '#0f7f93',
    closed: '#0b6b4f',
    ordered: '#c8880a',
    partial: '#0f7f93',
    received: '#0b6b4f',
    in_transit: '#c8880a',
    arrived: '#0f7f93',
    converted: '#5c6f68',
    high: '#9f1239',
    medium: '#c8880a',
    low: '#5c6f68',
};

export default function Floor() {
    const { t, locale } = useLocale();
    const { data, loading, error } = useData('/board');

    return (
        <Load loading={loading} error={error}>
            {data ? <Board data={data} locale={locale} t={t} /> : null}
        </Load>
    );
}

function Board({ data, locale, t }) {
    const { can } = useAuth();
    const stats = data.stats || {};
    const tasks = data.tasks || [];
    const trend = data.trend || [];
    const categories = (data.categories || []).map((row) => ({
        key: row.id,
        label: label(row, locale) || '—',
        value: Number(row.value) || 0,
    }));
    const conditions = data.conditions || [];
    const valued = (data.top_value || []).map((item) => ({
        key: item.id,
        label: `${item.code} ${label(item, locale)}`.trim(),
        value: Number(item.value) || 0,
    }));
    const low = data.low || [];
    const shortages = (data.shortages || []).filter((row) => row.shortage > 0);
    const shipments = [...(data.shipments || [])]
        .filter((row) => row.status !== 'received')
        .sort((a, b) => Number(b.delayed) - Number(a.delayed));
    const movements = (data.movements || []).slice(0, 5);
    const alerts = (data.alerts || []).slice(0, 5);
    const openTasks = tasks.slice(0, 5);
    const severity = tally(data.alerts || [], 'severity', ['high', 'medium', 'low']);

    return (
        <div className="stack dash">
            <PageHead
                title={t('nav.floor')}
                text={t('page.dashboard')}
                action={can('reports.view') ? <Link className="btn ghost" to="/reports">{t('nav.reports')}</Link> : null}
            />

            <section className="ledger" aria-label={t('nav.floor')}>
                <div className="figures">
                    <Link to="/items"><b>{formatQty(stats.skus)}</b><span>{t('stat.skus')}</span></Link>
                    <Link to="/inventory"><b>{formatQty(stats.on_hand)}</b><span>{t('stat.on_hand')}</span></Link>
                    <Link to="/inventory"><b>{formatQty(stats.available)}</b><span>{t('stat.available')}</span></Link>
                    <Link to="/reports"><b>{formatQty(stats.value)}</b><span>{t('stat.value')}</span></Link>
                </div>
                <div className="figures">
                    <Link to="/jobs"><b>{formatQty(stats.reserved)}</b><span>{t('stat.reserved')}</span></Link>
                    <Link to="/jobs"><b>{formatQty(stats.jobs_open)}</b><span>{t('stat.jobs')}</span></Link>
                    <Link to="/locations"><b>{formatQty(stats.occupied)}<small>/{formatQty(stats.locations)}</small></b><span>{t('stat.bins')}</span></Link>
                    <Link to="/alerts" className={stats.alerts > 0 ? 'figure-alert' : ''}>
                        <b>{formatQty(stats.alerts)}</b>
                        <span>{t('stat.alerts')}</span>
                    </Link>
                </div>
            </section>

            <div className="desk-split">
                <section className="sheet" aria-labelledby="dash-move">
                    <DashHead id="dash-move" title={t('dash.movement')} to="/reports?tab=movements" more={t('dash.more')} />
                    <MovementChart trend={trend} t={t} />
                </section>
                <section className="sheet" aria-labelledby="dash-cat">
                    <DashHead id="dash-cat" title={t('dash.by_category')} to="/reports" more={t('dash.more')} />
                    <Bars rows={categories.map((row) => ({ ...row, display: formatQty(row.value), color: tones.new }))} empty={t('dash.empty')} />
                </section>
            </div>

            <div className="desk-split">
                <section className="sheet">
                    <DashHead title={t('dash.condition')} to="/inventory" more={t('dash.more')} />
                    <Bars
                        keepEmpty
                        rows={conditions.map((row) => ({
                            key: row.key,
                            label: t(`cond.${row.key}`),
                            value: row.value,
                            display: formatQty(row.value),
                            color: tones[row.key],
                        }))}
                        empty={t('dash.empty')}
                    />
                </section>
                <section className="sheet">
                    <DashHead title={t('dash.top_value')} to="/reports" more={t('dash.more')} />
                    <Bars rows={valued.map((row) => ({ ...row, display: formatQty(row.value), color: '#0f7f93' }))} empty={t('dash.empty')} />
                </section>
            </div>

            <div className="desk-split">
                <section className="sheet">
                    <DashHead title={t('dash.pipeline')} to="/procurement" more={t('dash.more')} />
                    <div className="mix-stack">
                        <Mix title={t('dash.requests')} rows={statusRows(data.requests || [], ['open', 'converted'], t)} />
                        <Mix title={t('dash.orders')} rows={statusRows(data.orders || [], ['ordered', 'partial', 'received'], t)} />
                        <Mix title={t('dash.shipments')} rows={statusRows(data.shipments || [], ['in_transit', 'arrived', 'received'], t)} />
                    </div>
                </section>
                <section className="sheet">
                    <DashHead title={t('dash.jobs')} to="/jobs" more={t('dash.more')} />
                    <div className="mix-stack">
                        <Mix title={t('dash.jobs')} rows={statusRows(data.jobs || [], ['open', 'reserved', 'partial_hold', 'in_progress', 'closed'], t)} />
                        <Mix title={t('dash.alerts')} rows={severity.map((row) => ({
                            key: row.key,
                            label: t(`dash.severity.${row.key}`),
                            value: row.value,
                            color: tones[row.key],
                        }))} />
                    </div>
                </section>
            </div>

            <div className="desk-split">
                <section className="sheet" aria-labelledby="floor-tasks">
                    <DashHead id="floor-tasks" title={t('floor.tasks')} />
                    <div className="task-list">
                        {openTasks.length === 0 ? <p className="empty">{t('floor.none')}</p> : openTasks.map((task) => (
                            <Link className="task" key={`${task.kind}-${task.ref}`} to={task.href}>
                                <strong>{t(`task.${task.kind}`)}</strong>
                                <span className="mono">{task.ref}</span>
                            </Link>
                        ))}
                    </div>
                </section>
                <section className="sheet alert-list">
                    <DashHead title={t('dash.alerts')} to="/alerts" more={t('dash.more')} />
                    {alerts.length === 0 ? <p className="empty">{t('empty')}</p> : alerts.map((alert) => (
                        <article key={alert.id} className={`alert ${alert.severity}`}>
                            <span className="pip" />
                            <div>
                                <h3>{locale === 'ar' ? alert.title_ar : alert.title_en}</h3>
                                <p>{locale === 'ar' ? alert.detail_ar : alert.detail_en}</p>
                            </div>
                            {alert.href ? <Link className="btn ghost small" to={alert.href}>{t('alert.action')}</Link> : null}
                        </article>
                    ))}
                </section>
            </div>

            <div className="desk-split">
                <section className="sheet">
                    <DashHead title={t('dash.below_min')} to="/reports?tab=low" more={t('dash.more')} />
                    {low.length === 0 ? <p className="empty">{t('empty')}</p> : (
                        <div className="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">{t('item')}</th>
                                        <th scope="col" className="num">{t('on_hand')}</th>
                                        <th scope="col" className="num">{t('min')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {low.slice(0, 5).map((row) => (
                                        <tr key={row.id}>
                                            <td><Who code={row.code} name={label(row, locale)} to={`/items/${row.id}`} image={row.image_url} /></td>
                                            <td className="num">{formatQty(row.on_hand)}</td>
                                            <td className="num">{formatQty(row.min_level)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
                <section className="sheet">
                    <DashHead title={t('dash.shortages')} to="/jobs" more={t('dash.more')} />
                    {shortages.length === 0 ? <p className="empty">{t('empty')}</p> : (
                        <div className="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">{t('item')}</th>
                                        <th scope="col" className="num">{t('required')}</th>
                                        <th scope="col" className="num">{t('available')}</th>
                                        <th scope="col" className="num">{t('shortage')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {shortages.slice(0, 5).map((row) => (
                                        <tr key={row.item_id}>
                                            <td><Who code={row.code} name={label(row, locale)} to={`/items/${row.item_id}`} image={row.image_url} /></td>
                                            <td className="num">{formatQty(row.required)}</td>
                                            <td className="num">{formatQty(row.available)}</td>
                                            <td className="num">{formatQty(row.shortage)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>

            <div className="desk-split">
                <section className="sheet">
                    <DashHead title={t('dash.shipments')} to="/procurement" more={t('dash.more')} />
                    {shipments.length === 0 ? <p className="empty">{t('floor.clear')}</p> : (
                        <div className="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">{t('ci')}</th>
                                        <th scope="col">{t('supplier')}</th>
                                        <th scope="col">{t('expected')}</th>
                                        <th scope="col">{t('status')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {shipments.slice(0, 5).map((row) => (
                                        <tr key={row.id}>
                                            <td><Link className="mono ref" to={`/procurement/shipments/${row.id}`}>{row.ci_number}</Link></td>
                                            <td>{locale === 'ar' ? row.supplier : row.supplier_en}</td>
                                            <td className={row.delayed ? 'late mono' : 'mono'}>{formatDate(row.expected_on)}</td>
                                            <td><Status value={row.status} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
                <section className="sheet">
                    <DashHead title={t('dash.recent')} to="/reports?tab=movements" more={t('dash.more')} />
                    {movements.length === 0 ? <p className="empty">{t('floor.no_move')}</p> : (
                        <div className="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">{t('date')}</th>
                                        <th scope="col">{t('item')}</th>
                                        <th scope="col" className="num">{t('qty')}</th>
                                        <th scope="col">{t('reason')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {movements.map((row) => (
                                        <tr key={row.id}>
                                            <td className="mono">{formatDate(row.happened_at)}</td>
                                            <td><Who code={row.item} name={label(row, locale)} image={row.image_url} /></td>
                                            <td className={row.signed < 0 ? 'num late' : 'num'}>{formatQty(row.signed)}</td>
                                            <td>{t(`reason.${row.reason}`)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </div>
    );
}

function DashHead({ id, title, to, more }) {
    return (
        <div className="sheet-h">
            <h2 id={id}>{title}</h2>
            {to ? <Link className="btn link" to={to}>{more}</Link> : null}
        </div>
    );
}

function MovementChart({ trend, t }) {
    const max = Math.max(1, ...trend.map((day) => Math.max(day.in, day.out)));
    const inbound = trend.reduce((sum, day) => sum + day.in, 0);
    const outbound = trend.reduce((sum, day) => sum + day.out, 0);
    const quiet = inbound === 0 && outbound === 0;

    return (
        <div className="chart">
            <div className="chart-legend">
                <span><i className="swatch in" />{t('dash.in')} <b className="mono">{formatQty(inbound)}</b></span>
                <span><i className="swatch out" />{t('dash.out')} <b className="mono">{formatQty(outbound)}</b></span>
            </div>
            {quiet ? <p className="empty">{t('dash.no_move')}</p> : (
                <div className="days days-compact" role="img" aria-label={`${t('dash.movement')}: ${t('dash.in')} ${formatQty(inbound)}, ${t('dash.out')} ${formatQty(outbound)}`}>
                    {trend.map((day, index) => (
                        <div className="day" key={day.date} title={`${formatDate(day.date)}: ${day.in} / ${day.out}`}>
                            <div className="day-bars">
                                <i className="in" style={{ height: `${Math.round((day.in / max) * 132)}px` }} />
                                <i className="out" style={{ height: `${Math.round((day.out / max) * 132)}px` }} />
                            </div>
                            <span>{index % 2 === 0 ? `${Number(String(day.date).slice(8, 10))}/${Number(String(day.date).slice(5, 7))}` : ''}</span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function Bars({ rows, empty, keepEmpty = false }) {
    const visible = keepEmpty ? rows : rows.filter((row) => row.value > 0);
    const max = Math.max(1, ...visible.map((row) => row.value));

    if (visible.length === 0 || visible.every((row) => row.value <= 0)) {
        return <p className="empty">{empty}</p>;
    }

    return (
        <div className="hbars">
            {visible.map((row) => (
                <div className="hbar" key={row.key}>
                    <span title={row.label}>{row.label}</span>
                    <div className="hbar-track">
                        <b style={{ width: row.value > 0 ? `${Math.max(6, (row.value / max) * 100)}%` : '0%', background: row.color }} />
                    </div>
                    <b>{row.display}</b>
                </div>
            ))}
        </div>
    );
}

function Mix({ title, rows }) {
    const total = rows.reduce((sum, row) => sum + row.value, 0);

    return (
        <div className="mix-block">
            <div className="mix-head">
                <span>{title}</span>
                <b className="mono">{formatQty(total)}</b>
            </div>
            <div className="mix" role="img" aria-label={rows.map((row) => `${row.label} ${row.value}`).join(', ')}>
                {total === 0 ? null : rows.filter((row) => row.value > 0).map((row) => (
                    <i key={row.key} style={{ width: `${(row.value / total) * 100}%`, background: row.color }} />
                ))}
            </div>
            <ul className="mix-legend">
                {rows.map((row) => (
                    <li key={row.key}>
                        <i style={{ background: row.color }} />
                        {row.label}
                        <b className="mono">{formatQty(row.value)}</b>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function statusRows(rows, order, t) {
    return tally(rows, 'status', order).map((row) => ({
        key: row.key,
        label: t(`status.${row.key}`),
        value: row.value,
        color: tones[row.key] || '#5c6f68',
    }));
}

function tally(rows, field, order) {
    const counts = new Map(order.map((key) => [key, 0]));

    rows.forEach((row) => {
        const key = row[field];

        if (!counts.has(key)) {
            counts.set(key, 0);
        }

        counts.set(key, counts.get(key) + 1);
    });

    return [...counts.entries()].map(([key, value]) => ({ key, value }));
}

