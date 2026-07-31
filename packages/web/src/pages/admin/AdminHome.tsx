/** Sommaire de l'administration (§7.5 à §7.9). */

import { useTranslation } from 'react-i18next';
import { Link, Outlet, useLocation } from 'react-router-dom';

import { Card } from '../../components/common.js';

const SECTIONS = [
  { path: 'products', labelKey: 'nav.products', descriptionKey: 'admin.products.subtitle' },
  { path: 'par-matrix', labelKey: 'nav.parMatrix', descriptionKey: 'admin.parMatrix.subtitle' },
  { path: 'settings', labelKey: 'nav.settings', descriptionKey: 'admin.settings.title' },
  { path: 'delta', labelKey: 'nav.delta', descriptionKey: 'admin.delta.subtitle' },
  { path: 'audit', labelKey: 'nav.audit', descriptionKey: 'admin.audit.subtitle' },
] as const;

export function AdminHome() {
  const { t } = useTranslation();
  const location = useLocation();
  const atRoot = location.pathname.replace(/\/+$/, '').endsWith('/admin');

  return (
    <>
      <nav className="row" style={{ marginBottom: '1rem' }}>
        {SECTIONS.map((section) => (
          <Link
            key={section.path}
            to={section.path}
            className="button secondary"
            style={{ textDecoration: 'none' }}
          >
            {t(section.labelKey)}
          </Link>
        ))}
      </nav>

      {atRoot ? (
        <>
          <h1>{t('admin.title')}</h1>
          {SECTIONS.map((section) => (
            <Card key={section.path}>
              <h2>
                <Link to={section.path}>{t(section.labelKey)}</Link>
              </h2>
              <p className="muted">{t(section.descriptionKey)}</p>
            </Card>
          ))}
        </>
      ) : (
        <Outlet />
      )}
    </>
  );
}
