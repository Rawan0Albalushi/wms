import { useState } from 'react';
import { permissionGroups } from '../access';
import { api, errorText, ping } from '../api';
import { Editor, Field, Load, PageHead, SheetBar, useData } from '../components/ui';
import { useLocale } from '../i18n';

function roleLabel(t, name) {
    const key = `role.${name}`;
    const label = t(key);

    return label === key ? name : label;
}

function permissionLabel(t, name) {
    const key = `perm.${name}`;
    const label = t(key);

    return label === key ? name : label;
}

function RoleSheet({ role, permissions, onSaved }) {
    const { t } = useLocale();
    const [selected, setSelected] = useState(() => new Set(role.permission_ids));
    const [pending, setPending] = useState(false);
    const [message, setMessage] = useState('');
    const byName = new Map(permissions.map((permission) => [permission.name, permission.id]));

    function toggle(id, locked) {
        if (locked) {
            return;
        }

        setSelected((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    }

    async function save() {
        setPending(true);
        setMessage('');

        try {
            await api(`/access/roles/${role.id}`, {
                method: 'PATCH',
                body: { permission_ids: [...selected] },
            });
            ping();
            await onSaved();
            setMessage(t('saved.access'));
        } catch (err) {
            setMessage(errorText(err, t));
        } finally {
            setPending(false);
        }
    }

    return (
        <section className="sheet">
            <SheetBar title={roleLabel(t, role.name)}>
                <button type="button" className="btn small" disabled={pending} onClick={save}>{t('save')}</button>
            </SheetBar>
            {message ? <p className="sheet-note">{message}</p> : null}
            <div className="perm-groups">
                {permissionGroups.map(([group, names]) => (
                    <fieldset key={group}>
                        <legend>{t(group)}</legend>
                        {names.map((name) => {
                            const id = byName.get(name);
                            const locked = role.name === 'administrator' && name === 'access.manage';

                            if (!id) {
                                return null;
                            }

                            return (
                                <label className="check" key={name}>
                                    <input
                                        type="checkbox"
                                        checked={selected.has(id)}
                                        disabled={locked}
                                        onChange={() => toggle(id, locked)}
                                    />
                                    {permissionLabel(t, name)}
                                </label>
                            );
                        })}
                    </fieldset>
                ))}
            </div>
        </section>
    );
}

export default function Access() {
    const { t, locale } = useLocale();
    const { data, loading, error, reload } = useData('/access');
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const roles = data?.roles || [];
    const permissions = data?.permissions || [];

    function close() {
        setOpen(false);
        setEditing(null);
    }

    return (
        <>
            <PageHead
                title={t('nav.access')}
                text={t('page.access')}
                action={<button type="button" className="btn" onClick={() => setOpen(true)}>{t('access.add')}</button>}
            />
            <Load loading={loading} error={error}>
                {data ? (
                    <div className="stack">
                        <section className="sheet">
                            <SheetBar title={t('access.people')} count={data.users.length} />
                            <div className="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th scope="col">{t('employee')}</th>
                                            <th scope="col">{t('email')}</th>
                                            <th scope="col">{t('access.roles')}</th>
                                            <th scope="col">{t('status')}</th>
                                            <th scope="col">{t('actions')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {data.users.map((user) => (
                                            <tr key={user.id}>
                                                <td>{user.name}</td>
                                                <td>{user.email}</td>
                                                <td>{user.role_ids.map((id) => roleLabel(t, roles.find((role) => role.id === id)?.name)).filter(Boolean).join(locale === 'ar' ? '، ' : ', ')}</td>
                                                <td>{user.active ? t('access.active') : t('access.inactive')}</td>
                                                <td>
                                                    <button type="button" className="btn ghost small" onClick={() => setEditing(user)}>{t('btn.edit')}</button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <h2 className="access-heading">{t('access.roles')}</h2>
                        <p className="hint">{t('access.roles_hint')}</p>
                        {roles.map((role) => (
                            <RoleSheet
                                key={`${role.id}:${role.permission_ids.join(',')}`}
                                role={role}
                                permissions={permissions}
                                onSaved={reload}
                            />
                        ))}
                    </div>
                ) : null}
            </Load>
            {open || editing ? (
                <Editor title={editing ? t('btn.edit') : t('access.add')} onClose={close} onSave={async (form) => {
                    const roleIds = [...form.querySelectorAll('input[name="role_ids"]:checked')].map((input) => Number(input.value));
                    const body = {
                        name: form.name.value.trim(),
                        email: form.email.value.trim(),
                        password: form.password.value,
                        role_ids: roleIds,
                    };

                    if (editing) {
                        body.active = form.active.checked;
                        await api(`/access/users/${editing.id}`, { method: 'PATCH', body });
                    } else {
                        await api('/access/users', { method: 'POST', body });
                    }

                    ping();
                    await reload();

                    return editing ? undefined : { done: { ref: body.name, hint: 'saved.user' } };
                }}>
                    <div className="form-grid">
                        <Field label={t('employee')}><input name="name" required defaultValue={editing?.name || ''} /></Field>
                        <Field label={t('email')}><input name="email" type="email" required defaultValue={editing?.email || ''} autoComplete="off" /></Field>
                        <Field label={t('login.password')}>
                            <input name="password" type="password" autoComplete="new-password" required={!editing} minLength={editing ? undefined : 8} />
                        </Field>
                    </div>
                    {editing ? <p className="hint">{t('access.password_hint')}</p> : null}
                    <fieldset className="role-picks">
                        <legend>{t('access.roles')}</legend>
                        {roles.map((role) => (
                            <label className="check" key={role.id}>
                                <input type="checkbox" name="role_ids" value={role.id} defaultChecked={editing?.role_ids.includes(role.id)} />
                                {roleLabel(t, role.name)}
                            </label>
                        ))}
                    </fieldset>
                    {editing ? (
                        <label className="check">
                            <input type="checkbox" name="active" defaultChecked={editing.active} />
                            {t('access.active')}
                        </label>
                    ) : null}
                </Editor>
            ) : null}
        </>
    );
}
