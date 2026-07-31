/** Session : consultant connecté, poste, paramètres, langue par défaut. */

import type { Consultant, GeneralSettings } from '@merisu/domain';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

import { ApiError, api, getToken, setToken } from '../api/client.js';
import { applyDefaultLocale } from '../i18n/index.js';

interface SessionValue {
  consultant: Consultant | null;
  workstationId: string | null;
  settings: GeneralSettings | null;
  loading: boolean;
  isAdmin: boolean;
  login: (login: string, secret: string, workstationId?: string) => Promise<void>;
  logout: () => void;
  selectWorkstation: (workstationId: string) => Promise<void>;
  refreshSettings: () => Promise<void>;
}

const SessionContext = createContext<SessionValue | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const [consultant, setConsultant] = useState<Consultant | null>(null);
  const [workstationId, setWorkstationId] = useState<string | null>(null);
  const [settings, setSettings] = useState<GeneralSettings | null>(null);
  const [loading, setLoading] = useState(true);

  /** Restaure la session au démarrage (le jeton survit à la fermeture de l'app). */
  useEffect(() => {
    if (!getToken()) {
      setLoading(false);
      return;
    }

    api
      .me()
      .then((me) => {
        setConsultant(me.consultant);
        setWorkstationId(me.workstationId);
        setSettings(me.settings);
        // La langue par défaut de l'admin ne s'applique que sans choix explicite.
        applyDefaultLocale(me.settings.defaultLocale);
      })
      .catch((error) => {
        // Jeton expiré ou invalide : on repart proprement sur l'écran de connexion.
        // Hors-ligne, on conserve le jeton pour retenter plus tard.
        if (error instanceof ApiError) setToken(null);
      })
      .finally(() => setLoading(false));
  }, []);

  const login = useCallback(async (id: string, secret: string, ws?: string) => {
    const response = await api.login(id, secret, ws);
    setToken(response.token);
    setConsultant(response.consultant);
    setWorkstationId(response.workstationId);

    const me = await api.me();
    setSettings(me.settings);
    applyDefaultLocale(me.settings.defaultLocale);
  }, []);

  const logout = useCallback(() => {
    setToken(null);
    setConsultant(null);
    setWorkstationId(null);
    setSettings(null);
  }, []);

  const selectWorkstation = useCallback(async (id: string) => {
    const response = await api.selectWorkstation(id);
    setToken(response.token);
    setWorkstationId(response.workstationId);
  }, []);

  const refreshSettings = useCallback(async () => {
    const me = await api.me();
    setSettings(me.settings);
  }, []);

  const value = useMemo<SessionValue>(
    () => ({
      consultant,
      workstationId,
      settings,
      loading,
      isAdmin: consultant?.role === 'ADMIN',
      login,
      logout,
      selectWorkstation,
      refreshSettings,
    }),
    [consultant, workstationId, settings, loading, login, logout, selectWorkstation, refreshSettings],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const context = useContext(SessionContext);
  if (!context) throw new Error('useSession doit être utilisé dans <SessionProvider>');
  return context;
}
