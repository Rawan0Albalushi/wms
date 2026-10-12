import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, formatDate, formatQty, label, ping, readForm, syncImage } from '../api';
import { useAuth } from '../auth';
import { Condition, Editor, Load, PageHead, SheetBar, useData } from '../components/ui';
import { useLocale } from '../i18n';
import { ItemFields } from './Items';

export default function Item() {
    const { id } = useParams();
    const { t, locale } = useLocale();
    const { can } = useAuth();
    const manage = can('items.manage');
    const move = can('transfers.post');
    const [open, setOpen] = useState(false);
    const item = useData(`/items/${id}`);
    const options = useData('/options');

    return (
        <>
            <PageHead
                title={item.data ? label(item.data, locale) : t('nav.items')}
                text={item.data ? <span className="mono">{item.data.code}</span> : t('page.item')}
                action={(
                    <div className="actions">
                        {manage && item.data && options.data ? <button type="button" className="btn" onClick={() => setOpen(true)}>{t('btn.edit')}</button> : null}
                        <Link className="btn ghost" to="/items">{t('btn.back')}</Link>
                    </div>
                )}
            />
            <Load loading={item.loading} error={item.error}>
                {item.data ? (
                    <div className="stack">
                        <section className="sheet item-card">
                            <div className="item-stock">
                                {item.data.image_url ? (
                                    <img className="item-photo" src={item.data.image_url} alt={label(item.data, locale)} />
                                ) : (
                                    <div className="item-photo item-photo-empty">{t('photo.empty')}</div>
                                )}
                                <div className="item-balance">
                                    <b className={Number(item.data.on_hand) < Number(item.data.min_level) ? 'is-low' : undefined}>{formatQty(item.data.on_hand)}</b>
                                    <span>{t('on_hand')} · {item.data.unit}</span>
                                    {Number(item.data.on_hand) < Number(item.data.min_level) ? <em className="stock-flag">{t('stock.low')}</em> : null}
                                </div>
                                <dl className="item-figures">
                                    <div>
                                        <dt>{t('available')}</dt>
                                        <dd>{formatQty(item.data.available)}</dd>
                                    </div>
                                    <div>
                                        <dt>{t('reserved')}</dt>
                                        <dd>{formatQty(item.data.reserved)}</dd>
                                    </div>
                                    <div>
                                        <dt>{t('min')}</dt>
                                        <dd>{formatQty(item.data.min_level)}</dd>
                                    </div>
                                    <div>
                                        <dt>{t('cost')}</dt>
                                        <dd>{formatQty(item.data.unit_cost)}</dd>
                                    </div>
                                </dl>
                            </div>
                            <dl className="item-specs">
                                <div>
                                    <dt>{t('part')}</dt>
                                    <dd className="mono">{item.data.part_number || '—'}</dd>
                                </div>
                                <div>
                                    <dt>{t('barcode')}</dt>
                                    <dd className="mono">{item.data.barcode || '—'}</dd>
                                </div>
                                    <div>
                                        <dt>{t('unit')}</dt>
                                        <dd>{item.data.unit}{item.data.alt_unit ? ` / ${item.data.alt_unit}` : ''}</dd>
                                    </div>
                                    <div>
                                        <dt>{t('filter.status')}</dt>
                                        <dd>{item.data.stock_status ? t(`stock.${item.data.stock_status}`) : '—'}</dd>
                                    </div>
                                    <div>
                                        <dt>{t('filter.family')}</dt>
                                        <dd>{item.data.family || '—'}</dd>
                                    </div>
                                <div>
                                    <dt>{t('category')}</dt>
                                    <dd>{label(item.data.category, locale)}</dd>
                                </div>
                                <div>
                                    <dt>{t('supplier')}</dt>
                                    <dd>{item.data.supplier ? label(item.data.supplier, locale, 'name', 'name_en') : '—'}</dd>
                                </div>
                            </dl>
                        </section>
                        <section className="sheet item-batches">
                            <SheetBar title={t('batch')} count={item.data.batches.length} />
                            <div className="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th scope="col">{t('batch')}</th>
                                            <th scope="col">{t('location')}</th>
                                            <th className="num" scope="col">{t('on_hand')}</th>
                                            <th className="num" scope="col">{t('reserved')}</th>
                                            <th scope="col">{t('condition')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {item.data.batches.flatMap((batch) => batch.positions.map((position, index) => (
                                            <tr key={position.id} className={index === 0 ? 'group-start' : undefined}>
                                                {index === 0 ? (
                                                    <td rowSpan={batch.positions.length}>
                                                        <span className="mono">{batch.batch_no}</span>
                                                        {batch.expires_on ? <span className="cell-note">{t('expires_on')} {formatDate(batch.expires_on)}</span> : null}
                                                    </td>
                                                ) : null}
                                                <td>
                                                    {position.location ? <span className="mono">{position.location}</span> : (
                                                        move
                                                            ? <Link className="btn link" to={`/transfers?new=1&position=${position.id}`}>{t('task.putaway')}</Link>
                                                            : '—'
                                                    )}
                                                </td>
                                                <td className="num">{formatQty(position.quantity)}</td>
                                                <td className="num">{formatQty(position.reserved)}</td>
                                                {index === 0 ? <td rowSpan={batch.positions.length}><Condition value={batch.condition} /></td> : null}
                                            </tr>
                                        )))}
                                    </tbody>
                                </table>
                                {item.data.batches.length === 0 ? <p className="empty">{t('empty')}</p> : null}
                            </div>
                        </section>
                        <section className="sheet">
                            <SheetBar title={t('recorded.title')} count={item.data.recorded?.length || 0} />
                            <div className="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th scope="col">{t('location')}</th>
                                            <th className="num" scope="col">{t('qty')}</th>
                                            <th scope="col">{t('filter.family')}</th>
                                            <th scope="col">{t('condition')}</th>
                                            <th scope="col">{t('reference')}</th>
                                            <th scope="col">{t('date')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(item.data.recorded || []).map((row) => (
                                            <tr key={row.id}>
                                                <td className="mono">{row.location || '—'}</td>
                                                <td className="num">{formatQty(row.quantity)}</td>
                                                <td>{row.line_type || '—'}</td>
                                                <td>{row.condition ? <Condition value={row.condition} /> : '—'}</td>
                                                <td>{row.reference || '—'}</td>
                                                <td className="mono">{formatDate(row.recorded_on)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {(item.data.recorded || []).length === 0 ? <p className="empty">{t('empty')}</p> : null}
                            </div>
                        </section>
                    </div>
                ) : null}
            </Load>
            {open && manage && options.data && item.data ? (
                <Editor key={item.data.id} title={t('btn.edit')} onClose={() => setOpen(false)} onSave={async (form) => {
                    await api(`/items/${item.data.id}`, { method: 'PATCH', body: readForm(form) });
                    await syncImage(form, `/items/${item.data.id}/image`);
                    ping();
                    await item.reload();
                }}>
                    <ItemFields options={options.data} locale={locale} item={item.data} />
                </Editor>
            ) : null}
        </>
    );
}
