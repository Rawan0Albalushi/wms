import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, errorText, formatDate, ping } from '../api';
import { useAuth } from '../auth';
import { Facts, Load, PageHead, Status, useData } from '../components/ui';
import { useLocale } from '../i18n';

export default function Shipment() {
    const { id } = useParams();
    const { t, locale } = useLocale();
    const { can } = useAuth();
    const { data, loading, error, reload } = useData('/procurement');
    const [message, setMessage] = useState('');
    const shipment = (data?.shipments || []).find((row) => row.id === Number(id));

    async function arrive() {
        setMessage('');

        try {
            await api(`/shipments/${shipment.id}/arrive`, { method: 'POST', body: {} });
            ping();
            await reload();
        } catch (err) {
            setMessage(errorText(err, t));
        }
    }

    let next = null;

    if (shipment?.status === 'in_transit' && can('procurement.manage')) {
        next = <button type="button" className="btn" onClick={arrive}>{t('btn.arrive')}</button>;
    } else if (shipment?.status === 'arrived' && can('receiving.post')) {
        next = <Link className="btn" to={`/receiving?new=1&shipment=${shipment.id}&order=${shipment.order_id}`}>{t('btn.receive')}</Link>;
    }

    return (
        <>
            <PageHead
                title={shipment ? shipment.ci_number : t('ci')}
                text={shipment ? (locale === 'ar' ? shipment.supplier : shipment.supplier_en) : t('page.shipment')}
                action={(
                    <div className="actions">
                        {next}
                        <Link className="btn ghost" to={shipment ? `/procurement/orders/${shipment.order_id}` : '/procurement'}>{t('btn.back')}</Link>
                    </div>
                )}
            />
            {message ? <div className="banner" role="alert">{message}</div> : null}
            <Load loading={loading} error={error}>
                {shipment ? (
                    <section className="sheet">
                        <Facts items={[
                            { label: t('order'), value: <Link className="mono ref" to={`/procurement/orders/${shipment.order_id}`}>{shipment.order_number}</Link> },
                            { label: t('expected'), value: <span className="mono">{formatDate(shipment.expected_on)}</span> },
                            { label: t('packing'), value: shipment.packing_note || '—' },
                            { label: t('status'), value: <Status value={shipment.status} /> },
                        ]} />
                    </section>
                ) : <p className="empty">{t('empty')}</p>}
            </Load>
        </>
    );
}
