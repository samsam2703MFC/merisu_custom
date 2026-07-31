/** Routage et garde d'accès par rôle. */

import { Navigate, Route, Routes } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

import { Layout } from './components/Layout.js';
import { Banner, Spinner } from './components/common.js';
import { EveningCount, MorningCount } from './pages/CountScreen.js';
import { Login } from './pages/Login.js';
import { Production } from './pages/Production.js';
import { AdminAudit } from './pages/admin/AdminAudit.js';
import { AdminDelta } from './pages/admin/AdminDelta.js';
import { AdminHome } from './pages/admin/AdminHome.js';
import { AdminParMatrix } from './pages/admin/AdminParMatrix.js';
import { AdminProducts } from './pages/admin/AdminProducts.js';
import { AdminSettings } from './pages/admin/AdminSettings.js';
import { useSession } from './state/session.js';

/** Les écrans d'administration sont réservés au rôle ADMIN (§2). */
function RequireAdmin({ children }: { children: JSX.Element }) {
  const { isAdmin } = useSession();
  const { t } = useTranslation();

  if (!isAdmin) return <Banner kind="danger">{t('errors.ROLE_NOT_ALLOWED')}</Banner>;
  return children;
}

export function App() {
  const { consultant, loading } = useSession();

  if (loading) return <Spinner />;
  if (!consultant) return <Login />;

  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<Navigate to="/morning" replace />} />
        <Route path="/morning" element={<MorningCount />} />
        <Route path="/evening" element={<EveningCount />} />
        <Route path="/production" element={<Production />} />

        <Route
          path="/admin"
          element={
            <RequireAdmin>
              <AdminHome />
            </RequireAdmin>
          }
        >
          <Route path="products" element={<AdminProducts />} />
          <Route path="par-matrix" element={<AdminParMatrix />} />
          <Route path="settings" element={<AdminSettings />} />
          <Route path="delta" element={<AdminDelta />} />
          <Route path="audit" element={<AdminAudit />} />
        </Route>

        <Route path="*" element={<Navigate to="/morning" replace />} />
      </Route>
    </Routes>
  );
}
