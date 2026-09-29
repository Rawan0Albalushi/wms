import { useState } from 'react';
import { api, errorText, label, ping, readForm } from '../api';
import { useAuth } from '../auth';
import { Editor, Field, Load, PageHead, Panel, RecordView, RowActions, SheetBar, useData, Who } from '../components/ui';
import { useLocale } from '../i18n';

export default function Suppliers() {
    const { t, locale } = useLocale();
    const { can } = useAuth();
    const manage = can('suppliers.manage');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [viewing, setViewing] = useState(null);
    const [notice, setNotice] = useState('');
    const { data, loading, error, reload } = useData('/suppliers');

    async function removeSupplier(id) {
        try {
            await api(`/suppliers/${id}`, { method: 'DELETE' });
            setNotice('');
            ping();
            await reload();
        } catch (failure) {
            setNotice(errorText(failure, t));
        }
    }

    const viewedName = viewing ? label(viewing, locale, 'name', 'name_en') : '';
    const viewedOther = viewing ? (locale === 'ar' ? viewing.name_en : viewing.name) : '';

    return (
        <>
            <PageHead
                title={t('nav.suppliers')}
                text={t('page.suppliers')}
                action={manage ? <button type="button" className="btn" onClick={() => { setEditing(null); setViewing(null); setOpen(true); }}>{t('btn.supplier')}</button> : null}
            />
            {notice ? <p className="banner" role="alert">{notice}</p> : null}
            <div className="sheet">
                <SheetBar count={data ? data.length : undefined} />
                <Load loading={loading} error={error}>
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{t('supplier')}</th>
                                    <th scope="col">{t('contact')}</th>
                                    <th scope="col">{t('phone')}</th>
                                    <th scope="col">{t('email')}</th>
                                    <th scope="col">{t('actions')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(data || []).map((supplier) => (
                                    <tr key={supplier.id}>
                                        <td><Who code={supplier.code} name={label(supplier, locale, 'name', 'name_en')} /></td>
                                        <td>{supplier.contact_name || '—'}</td>
                                        <td className="mono">{supplier.phone || '—'}</td>
                                        <td>{supplier.email || '—'}</td>
                                        <td>
                                            <RowActions
                                                onView={() => { setOpen(false); setEditing(null); setViewing(supplier); }}
                                                onEdit={manage ? () => { setOpen(false); setViewing(null); setEditing(supplier); } : undefined}
                                                onDelete={manage ? () => removeSupplier(supplier.id) : undefined}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {data?.length === 0 ? <p className="empty">{t('empty')}</p> : null}
                    </div>
                </Load>
            </div>
            {open && manage ? (
                <Editor title={t('btn.supplier')} onClose={() => setOpen(false)} onSave={async (form) => {
                    const saved = await api('/suppliers', { method: 'POST', body: readForm(form) });
                    ping();
                    await reload();

                    return { done: { ref: saved.code || t('nav.suppliers'), hint: 'saved.supplier' } };
                }}>
                    <SupplierFields />
                </Editor>
            ) : null}
            {editing && manage ? (
                <Editor
                    key={editing.id}
                    title={t('btn.edit')}
                    onClose={() => setEditing(null)}
                    onSave={async (form) => {
                        await api(`/suppliers/${editing.id}`, { method: 'PATCH', body: readForm(form) });
                        ping();
                        await reload();
                    }}
                >
                    <SupplierFields supplier={editing} />
                </Editor>
            ) : null}
            {viewing ? (
                <Panel compact title={viewing.code} onClose={() => setViewing(null)}>
                    <div className="panel-b">
                        <RecordView
                            title={viewedName}
                            note={viewedOther !== viewedName ? viewedOther : ''}
                            rows={[
                                { label: t('contact'), value: viewing.contact_name || '—' },
                                { label: t('phone'), value: viewing.phone ? <a dir="ltr" href={`tel:${viewing.phone}`}>{viewing.phone}</a> : '—' },
                                { label: t('email'), value: viewing.email ? <a dir="ltr" href={`mailto:${viewing.email}`}>{viewing.email}</a> : '—' },
                            ]}
                        />
                    </div>
                </Panel>
            ) : null}
        </>
    );
}

function SupplierFields({ supplier }) {
    const { t } = useLocale();

    return (
        <div className="form-grid">
            <Field label={t('code')}><input name="code" required defaultValue={supplier?.code || ''} /></Field>
            <Field label={t('name_ar')}><input name="name" required defaultValue={supplier?.name || ''} /></Field>
            <Field label={t('name_en')}><input name="name_en" required defaultValue={supplier?.name_en || ''} /></Field>
            <Field label={t('contact')}><input name="contact_name" defaultValue={supplier?.contact_name || ''} /></Field>
            <Field label={t('phone')}><input name="phone" defaultValue={supplier?.phone || ''} /></Field>
            <Field label={t('email')}><input name="email" type="email" defaultValue={supplier?.email || ''} /></Field>
        </div>
    );
}
