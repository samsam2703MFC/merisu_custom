/** État de la file hors-ligne et de la connectivité, pour l'indicateur global (§2). */

import { useEffect, useState } from 'react';

import { syncQueue } from '../api/client.js';
import { subscribeQueue, watchConnectivity, type QueueState } from '../offline/queue.js';

export function useOfflineQueue(): QueueState & { sync: () => void } {
  const [state, setState] = useState<QueueState>({
    pending: 0,
    syncing: false,
    online: navigator.onLine,
    failed: [],
  });

  useEffect(() => {
    const unsubscribe = subscribeQueue(setState);
    // Rejeu au retour du réseau, et une première fois au démarrage : l'app a pu
    // être fermée alors que des saisies attendaient encore.
    const stopWatching = watchConnectivity(() => void syncQueue());
    void syncQueue();

    return () => {
      unsubscribe();
      stopWatching();
    };
  }, []);

  return { ...state, sync: () => void syncQueue() };
}
