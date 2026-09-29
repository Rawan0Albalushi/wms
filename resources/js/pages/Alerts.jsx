import { Link } from 'react-router-dom';
import { Load, PageHead, Pager, SheetBar, useData, usePaged } from '../components/ui';
import { useLocale } from '../i18n';

export default function Alerts() {
    const { t, locale } = useLocale();
    const { data, loading, error } = useData('/alerts');
    const page = usePaged(data || [], (data || []).length);

    return (
        <>
            <PageHead lane={10} title={t('nav.alerts')} text={t('page.alerts')} />
            <Load loading={loading} error={error}>
                <section className="sheet alert-list">
                    <SheetBar title={t('nav.alerts')} count={(data || []).length} />
                    {(data || []).length === 0 ? <p className="empty">{t('empty')}</p> : null}
                    {page.rows.map((alert) => (
                        <article key={alert.id} className={`alert ${alert.severity}`}>
                            <span className="pip" />
                            <div>
                                <h3>{locale === 'ar' ? alert.title_ar : alert.title_en}</h3>
                                <p>{locale === 'ar' ? alert.detail_ar : alert.detail_en}</p>
                            </div>
                            {alert.href ? <Link className="btn ghost small" to={alert.href}>{t('alert.action')}</Link> : null}
                        </article>
                    ))}
                    <Pager page={page.page} pages={page.pages} total={page.total} from={page.from} to={page.to} onPage={page.setPage} />
                </section>
            </Load>
        </>
    );
}
