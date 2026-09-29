import { useState } from 'react';
import { Link } from 'react-router-dom';
import { formatDate, formatQty, label } from '../api';
import { useAuth } from '../auth';
import Rack from '../components/Rack';
import { Condition, Load, PageHead, PlaceBanner, SheetBar, useData, Who } from '../components/ui';
import { useLocale } from '../i18n';

export default function Inventory() {
    const { t, locale } = useLocale();
    const { can } = useAuth();
    const [active, setActive] = useState('');
    const { data, loading, error } = useData('/inventory');
    const positions = (data?.positions || []).filter((row) => !active || row.location === active);
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
                        </div>
                        <div className="sheet">
                            <SheetBar title={active || t('batch')} count={positions.length}>
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
                                        {positions.map((row) => (
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
                        </div>
                    </div>
                ) : null}
            </Load>
        </>
    );
}
