import { Route, Routes, useLocation } from 'react-router-dom';
import { permissionFor } from './access';
import { useAuth } from './auth';
import Shell from './components/Shell';
import Alerts from './pages/Alerts';
import Counts from './pages/Counts';
import Floor from './pages/Floor';
import Inventory from './pages/Inventory';
import Issuing from './pages/Issuing';
import Item from './pages/Item';
import Items from './pages/Items';
import Job from './pages/Job';
import Jobs from './pages/Jobs';
import Locations from './pages/Locations';
import Order from './pages/Order';
import Procurement from './pages/Procurement';
import Receiving from './pages/Receiving';
import Reports from './pages/Reports';
import Shipment from './pages/Shipment';
import Suppliers from './pages/Suppliers';
import Transfers from './pages/Transfers';
import Access from './pages/Access';
import Login from './pages/Login';
import { useLocale } from './i18n';

function Screens() {
    const { can } = useAuth();
    const { t } = useLocale();
    const location = useLocation();

    if (!can(permissionFor(location.pathname))) {
        return <p className="banner" role="alert">{t('errors.forbidden')}</p>;
    }

    return (
            <Routes>
                <Route path="/" element={<Floor />} />
                <Route path="/items/:id" element={<Item />} />
                <Route path="/items" element={<Items />} />
                <Route path="/locations" element={<Locations />} />
                <Route path="/suppliers" element={<Suppliers />} />
                <Route path="/procurement/orders/:id" element={<Order />} />
                <Route path="/procurement/shipments/:id" element={<Shipment />} />
                <Route path="/procurement" element={<Procurement />} />
                <Route path="/jobs/:id" element={<Job />} />
                <Route path="/receiving" element={<Receiving />} />
                <Route path="/inventory" element={<Inventory />} />
                <Route path="/issuing" element={<Issuing />} />
                <Route path="/transfers" element={<Transfers />} />
                <Route path="/jobs" element={<Jobs />} />
                <Route path="/counts" element={<Counts />} />
                <Route path="/alerts" element={<Alerts />} />
                <Route path="/reports" element={<Reports />} />
                <Route path="/access" element={<Access />} />
                <Route path="*" element={<Floor />} />
            </Routes>
    );
}

export default function App() {
    const { user, ready } = useAuth();
    const { t } = useLocale();

    if (!ready) {
        return <p className="boot">{t('loading')}</p>;
    }

    if (!user) {
        return <Login />;
    }

    return (
        <Shell>
            <Screens />
        </Shell>
    );
}
