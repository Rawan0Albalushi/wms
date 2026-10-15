import { useState } from 'react';
import { Link } from 'react-router-dom';
import { formatDate, formatQty, label, matchesText } from '../api';
import { useAuth } from '../auth';
import Rack, { BinHistory, BinStock } from '../components/Rack';
import { Condition, Load, PageHead, Pager, PlaceBanner, SheetBar, useData, usePaged, Who } from '../components/ui';
import { useLocale } from '../i18n';

export default function Inventory() {
    const { t, locale } = useLocale();
    const { can } = useAuth();
    const [active, setActive] = useState('');
    const [q, setQ] = useState('');
    const { data, loading, error } = useData('/inventory?moves=0');
    const positions = (data?.positions || []).filter((row) => (!active || row.location === active) && matchesText(q, [row.item_code, row.name_ar, row.name_en, row.location, row.batch_no, row.condition]));
    const recorded = (data?.recorded || []).filter((row) => (!active || row.location === active) && matchesText(q, [row.item_code, row.name_ar, row.name_en, row.location, row.line_type, row.reference]));
    const page = usePaged(positions, `${active}|${q}`);
    const recordedPage = usePaged(recorded, `recorded|${active}|${q}`);
    const place = (data?.locations || []).find((row) => row.code === active);

    return (
        <>
            <PageHead lane={4} title={t('nav.inventory')} text={t('page.inventory')} />
            <Load loading={loading} error={error}>
                {data ? (
                    <div className="stack">
                        <div className="sheet">
                            <SheetBar title={t('map')} />
                            <Rack locations={data.locations} active={active} onPick={setActive} />
                            <BinStock place={place} />
                            <BinHistory place={place} />
                        </div>
                        <div className="sheet">
                            <SheetBar title={active || t('batch')} count={page.total}>
                                <input className="search" value={q} onChange={(event) => setQ(event.target.value)} placeholder={t('search')} aria-label={t('search')} />
                                {active ? <button type="button" className="btn ghost small" onClick={() => setActive('')}>{t('show_all')}</button> : null}
                            </SheetBar>
                            <PlaceBanner place={place} />
                            <div className="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th scope="col">{t('batch')}</th>
                                            <th scope="col">{t('item')}</th>
                                            <th scope="col">{t('location')}</th>
                                            <th className="num" scope="col">{t('on_hand')}</th>
                                            <th className="num" scope="col">{t('available')}</th>
                                            <th scope="col">{t('condition')}</th>
                                            <th scope="col">{t('expires_on')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {page.rows.map((row) => (
                                            <tr key={row.id}>
                                                <td className="mono">{row.batch_no}</td>
                                                <td><Who code={row.item_code} name={label(row, locale)} to={`/items/${row.item_id}`} image={row.image_url} /></td>
                                                <td className="mono">
                                                    {row.location ? row.location : (
                                                        can('transfers.post')
                                                            ? <Link className="btn link" to={`/transfers?new=1&position=${row.id}`}>{t('task.putaway')}</Link>
                                                            : '—'
                                                    )}
                                                </td>
                                                <td className="num">{formatQty(row.quantity)}</td>
                                                <td className="num">{formatQty(row.available)}</td>
                                                <td><Condition value={row.condition} /></td>
                                                <td className="mono">{formatDate(row.expires_on)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {positions.length === 0 ? <p className="empty">{t('empty')}</p> : null}
                            </div>
                            <Pager page={page.page} pages={page.pages} total={page.total} from={page.from} to={page.to} onPage={page.setPage} />
                        </div>
                        <div className="sheet">
                            <SheetBar title={t('recorded.title')} count={recordedPage.total} />
                            <div className="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th scope="col">{t('item')}</th>
                                            <th scope="col">{t('location')}</th>
                                            <th className="num" scope="col">{t('qty')}</th>
                                            <th scope="col">{t('filter.family')}</th>
                                            <th scope="col">{t('reference')}</th>
                                            <th scope="col">{t('date')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {recordedPage.rows.map((row) => (
                                            <tr key={row.id}>
                                                <td><Who code={row.item_code} name={label(row, locale)} to={`/items/${row.item_id}`} /></td>
                                                <td className="mono">{row.location || '—'}</td>
                                                <td className="num">{formatQty(row.quantity)}</td>
                                                <td>{row.line_type || '—'}</td>
                                                <td>{row.reference || '—'}</td>
                                                <td className="mono">{formatDate(row.recorded_on)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {recorded.length === 0 ? <p className="empty">{t('empty')}</p> : null}
                            </div>
                            <Pager page={recordedPage.page} pages={recordedPage.pages} total={recordedPage.total} from={recordedPage.from} to={recordedPage.to} onPage={recordedPage.setPage} />
                        </div>
                    </div>
                ) : null}
            </Load>
        </>
    );
}
