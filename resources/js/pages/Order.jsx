import { Link, useParams } from 'react-router-dom';
import { formatDate, formatQty, label } from '../api';
import { useAuth } from '../auth';
import { Load, PageHead, SheetBar, Status, useData, Who } from '../components/ui';
import { useLocale } from '../i18n';

export default function Order() {
    const { id } = useParams();
    const { t, locale } = useLocale();
    const { can } = useAuth();
    const { data, loading, error } = useData('/procurement');
    const order = (data?.orders || []).find((row) => row.id === Number(id));
    const shipments = (data?.shipments || []).filter((row) => order && row.order_id === order.id);
    const arrived = shipments.find((row) => row.status === 'arrived');
    const transit = shipments.find((row) => row.status === 'in_transit');

    let next = null;

    if (order && order.status !== 'received') {
        if (arrived && can('receiving.post')) {
            next = <Link className="btn" to={`/receiving?new=1&shipment=${arrived.id}&order=${order.id}`}>{t('btn.receive')}</Link>;
        } else if (transit && can('procurement.manage')) {
            next = <Link className="btn" to={`/procurement/shipments/${transit.id}`}>{t('btn.arrive')}</Link>;
        } else if (can('procurement.manage')) {
            next = <Link className="btn" to={`/procurement?new=shipment&order=${order.id}`}>{t('btn.shipment')}</Link>;
        }
    }

    return (
        <>
            <PageHead
                title={order ? order.number : t('order')}
                text={order ? (locale === 'ar' ? order.supplier : order.supplier_en) : t('page.order')}
                action={(
                    <div className="actions">
                        {next}
                        <Link className="btn ghost" to="/procurement">{t('btn.back')}</Link>
                    </div>
                )}
            />
            <Load loading={loading} error={error}>
                {order ? (
                    <div className="stack">
                        <section className="sheet">
                            <SheetBar title={<span className="mono">{formatDate(order.ordered_on)}</span>} count={order.lines.length}>
                                <Status value={order.status} />
                            </SheetBar>
                            <div className="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th scope="col">{t('item')}</th>
                                            <th className="num" scope="col">{t('qty')}</th>
                                            <th className="num" scope="col">{t('remaining')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {order.lines.map((line) => (
                                            <tr key={line.item_id}>
                                                <td><Who code={line.item_code} name={label(line, locale)} image={line.image_url} /></td>
                                                <td className="num">{formatQty(line.quantity)}</td>
                                                <td className="num">{formatQty(line.remaining)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <section className="sheet">
                            <SheetBar title={t('btn.shipment')} count={shipments.length} />
                            {shipments.length === 0 ? <p className="empty">{t('empty')}</p> : (
                                <div className="table-wrap">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th scope="col">{t('ci')}</th>
                                                <th scope="col">{t('expected')}</th>
                                                <th scope="col">{t('status')}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {shipments.map((row) => (
                                                <tr key={row.id}>
                                                    <td><Link className="mono ref" to={`/procurement/shipments/${row.id}`}>{row.ci_number}</Link></td>
                                                    <td className="mono">{formatDate(row.expected_on)}</td>
                                                    <td><Status value={row.status} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    </div>
                ) : <p className="empty">{t('empty')}</p>}
            </Load>
        </>
    );
}
