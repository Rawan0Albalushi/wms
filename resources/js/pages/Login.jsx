import { useState } from 'react';
import { useAuth } from '../auth';
import { errorText } from '../api';
import { Field } from '../components/ui';
import { useLocale } from '../i18n';

export default function Login() {
    const { t, toggle } = useLocale();
    const { login } = useAuth();
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);

    async function submit(event) {
        event.preventDefault();
        const form = event.currentTarget;
        setPending(true);
        setError('');

        try {
            await login({
                email: form.email.value.trim(),
                password: form.password.value,
            });
        } catch (err) {
            setError(errorText(err, t));
        } finally {
            setPending(false);
        }
    }

    return (
        <div className="gate">
            <aside className="gate-side">
                <div className="brand">
                    <span className="brand-mark" aria-hidden="true">W</span>
                    <span className="brand-copy">
                        <strong>WMS</strong>
                        <span>{t('app.system')}</span>
                    </span>
                </div>
                <button type="button" className="lang" onClick={toggle}>{t('app.lang')}</button>
            </aside>
            <main className="gate-main">
                <form className="gate-form stack" onSubmit={submit}>
                    <h1>{t('login.title')}</h1>
                    <p className="hint">{t('login.text')}</p>
                    {error ? <div className="banner" role="alert">{error}</div> : null}
                    <Field label={t('email')}>
                        <input name="email" type="email" autoComplete="username" required />
                    </Field>
                    <Field label={t('login.password')}>
                        <input name="password" type="password" autoComplete="current-password" required />
                    </Field>
                    <button className="btn" type="submit" disabled={pending}>{t('login.submit')}</button>
                </form>
            </main>
        </div>
    );
}
