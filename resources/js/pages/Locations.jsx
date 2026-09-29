import { useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, errorText, formatQty, ping, readForm, syncImage } from '../api';
import { useAuth } from '../auth';
import Rack from '../components/Rack';
import { Editor, Field, Load, PageHead, Panel, PhotoField, PlaceBanner, RecordView, RowActions, SheetBar, Thumb, useData } from '../components/ui';
import { useLocale } from '../i18n';

export default function Locations() {
    const { t } = useLocale();
    const { can } = useAuth();
    const manage = can('locations.manage');
    const [params] = useSearchParams();
    const [open, setOpen] = useState(params.get('new') === '1' && manage);
    const [editing, setEditing] = useState(null);
    const [viewing, setViewing] = useState(null);
    const [notice, setNotice] = useState('');
    const [active, setActive] = useState('');
    const [photo, setPhoto] = useState(null);
    const created = useRef(null);
    const { data, loading, error, reload } = useData('/locations');
    const rows = (data || []).filter((location) => !active || location.code === active);
    const activeRow = (data || []).find((location) => location.code === active);
    const bays = (data || []).filter((location) => location.kind !== 'storage' || location.zone !== 'S1');

    async function removeLocation(id) {
        try {
            await api(`/locations/${id}`, { method: 'DELETE' });
            setNotice('');
            ping();
            await reload();
        } catch (failure) {
            setNotice(errorText(failure, t));
        }
    }

    return (
        <>
            <PageHead
                lane={6}
                title={t('nav.locations')}
                text={t('page.locations')}
                action={manage ? <button type="button" className="btn" onClick={() => { setEditing(null); setViewing(null); setOpen(true); }}>{t('btn.location')}</button> : null}
            />
            {notice ? <p className="banner" role="alert">{notice}</p> : null}
            <div className="sheet">
                <SheetBar title={t('map')} />
                <Load loading={loading} error={error}>
                    {data ? <Rack locations={data} active={active} onPick={setActive} /> : null}
                    <div className="bays">
                        {bays.map((bay) => (
                            <button key={bay.id} type="button" className={bay.image_url ? 'bay bay-photo' : 'bay'} onClick={() => setActive(active === bay.code ? '' : bay.code)}>
                                {bay.image_url ? <img className="thumb" src={bay.image_url} alt="" /> : null}
                                <span className="bay-copy">
                                    <strong className="mono">{bay.code}</strong>
                                    <span>{t(`kind.${bay.kind}`)} · {formatQty(bay.on_hand)}</span>
                                </span>
                            </button>
                        ))}
                    </div>
                </Load>
            </div>
            <div className="sheet">
                <SheetBar title={t('location_list')} count={data ? rows.length : undefined}>
                    {active ? <button type="button" className="btn ghost small" onClick={() => setActive('')}>{t('show_all')}</button> : null}
                </SheetBar>
                <PlaceBanner place={activeRow} onPhoto={manage ? setPhoto : undefined} />
                <div className="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{t('location')}</th>
                                <th scope="col">{t('zone')}</th>
                                <th scope="col">{t('rack')}</th>
                                <th scope="col">{t('shelf')}</th>
                                <th className="num" scope="col">{t('on_hand')}</th>
                                <th scope="col">{t('actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.filter((row) => active || row.on_hand > 0 || row.kind !== 'storage').map((row) => (
                                <tr key={row.id}>
                                    <td>
                                        <div className="who who-photo">
                                            <Thumb src={row.image_url} />
                                            <div className="who-copy"><span className="mono">{row.code}</span></div>
                                        </div>
                                    </td>
                                    <td>{row.zone}</td>
                                    <td>{row.rack || '—'}</td>
                                    <td>{row.shelf || '—'}</td>
                                    <td className="num">{formatQty(row.on_hand)}</td>
                                    <td>
                                        <RowActions
                                            onView={() => { setOpen(false); setEditing(null); setViewing(row); }}
                                            onEdit={manage ? () => { setOpen(false); setViewing(null); setEditing(row); } : undefined}
                                            onDelete={manage ? () => removeLocation(row.id) : undefined}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            {open && manage ? (
                <Editor title={t('btn.location')} onClose={() => { created.current = null; setOpen(false); }} onSave={async (form) => {
                    const saved = created.current
                        ? { id: created.current }
                        : await api('/locations', { method: 'POST', body: readForm(form) });
                    created.current = saved.id;
                    await syncImage(form, `/locations/${saved.id}/image`);
                    ping();
                    await reload();
                }}>
                    <LocationFields />
                </Editor>
            ) : null}
            {editing && manage ? (
                <Editor
                    key={editing.id}
                    title={t('btn.edit')}
                    onClose={() => setEditing(null)}
                    onSave={async (form) => {
                        await api(`/locations/${editing.id}`, { method: 'PATCH', body: readForm(form) });
                        await syncImage(form, `/locations/${editing.id}/image`);
                        ping();
                        await reload();
                    }}
                >
                    <LocationFields location={editing} />
                </Editor>
            ) : null}
            {photo && manage ? (
                <Editor key={photo.id} title={photo.code} onClose={() => setPhoto(null)} onSave={async (form) => {
                    await syncImage(form, `/locations/${photo.id}/image`);
                    ping();
                    await reload();
                }}>
                    <div className="form-grid">
                        <PhotoField current={photo.image_url || ''} hint="photo.location_hint" />
                    </div>
                </Editor>
            ) : null}
            {viewing ? (
                <Panel compact title={viewing.code} onClose={() => setViewing(null)}>
                    <div className="panel-b">
                        <RecordView
                            image={viewing.image_url}
                            imageAlt={viewing.code}
                            figure={formatQty(viewing.on_hand)}
                            figureLabel={t('on_hand')}
                            rows={[
                                { label: t('zone'), value: viewing.zone },
                                { label: t('rack'), value: viewing.rack || '—' },
                                { label: t('shelf'), value: viewing.shelf || '—' },
                                { label: t('kind'), value: t(`kind.${viewing.kind}`) },
                            ]}
                        />
                    </div>
                </Panel>
            ) : null}
        </>
    );
}

function LocationFields({ location }) {
    const { t } = useLocale();

    return (
        <div className="form-grid">
            <PhotoField current={location?.image_url || ''} hint="photo.location_hint" />
            <Field label={t('location')}><input name="code" required defaultValue={location?.code || ''} /></Field>
            <Field label={t('zone')}><input name="zone" required defaultValue={location?.zone || ''} /></Field>
            <Field label={t('rack')}><input name="rack" defaultValue={location?.rack || ''} /></Field>
            <Field label={t('shelf')}><input name="shelf" defaultValue={location?.shelf || ''} /></Field>
            <Field label={t('kind')}>
                <select name="kind" defaultValue={location?.kind || 'storage'}>
                    <option value="storage">{t('kind.storage')}</option>
                    <option value="dock">{t('kind.dock')}</option>
                    <option value="quarantine">{t('kind.quarantine')}</option>
                </select>
            </Field>
        </div>
    );
}
