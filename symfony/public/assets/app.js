/*
 * MERISU — comportements client.
 *
 * Volontairement en JavaScript natif, sans framework ni dépendance : les écrans
 * sont rendus par Twig et restent pleinement utilisables sans ce fichier
 * (amélioration progressive). Il ajoute :
 *
 *   1. les boutons − / + de comptage ;
 *   2. la file d'attente hors-ligne (IndexedDB) et le bandeau de coupure (§2) ;
 *   3. la confirmation avant les actions verrouillantes ;
 *   4. l'enregistrement du service worker.
 *
 * ⚠️ Ce fichier ne contient AUCUN style et AUCUN texte d'interface : les
 * libellés proviennent des attributs `data-` posés par les templates.
 */
(function () {
  'use strict';

  // ── 1. Boutons de comptage ────────────────────────────────────────────────

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-qty-step]');
    if (!button) return;

    var input = button.parentElement.querySelector('.qty__input');
    if (!input || input.disabled) return;

    var step = parseFloat(button.getAttribute('data-qty-step')) || 0;
    var current = parseFloat(input.value);
    if (!isFinite(current)) current = 0;

    // Jamais de quantité négative : un stock ne descend pas sous zéro.
    var next = Math.max(0, Math.round((current + step) * 1000) / 1000);
    input.value = String(next);
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });

  // ── 2. Envoi automatique des sélecteurs (langue, poste) ───────────────────

  document.addEventListener('change', function (event) {
    var select = event.target.closest('[data-autosubmit]');
    if (select && select.form) select.form.submit();
  });

  // ── 2 bis. Fermeture du menu de l'avatar ──────────────────────────────────

  // Le menu est un <details> : il s'ouvre et se ferme tout seul, sans script.
  // Ces deux écouteurs n'ajoutent que le confort attendu d'un menu — un clic
  // à côté ou la touche Échap le referment.
  document.addEventListener('click', function (event) {
    document.querySelectorAll('[data-dismiss-outside][open]').forEach(function (menu) {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;

    document.querySelectorAll('[data-dismiss-outside][open]').forEach(function (menu) {
      menu.removeAttribute('open');
      var trigger = menu.querySelector('summary');
      if (trigger) trigger.focus();
    });
  });

  // ── 3. Confirmation avant action verrouillante ────────────────────────────

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-confirm]');
    if (!trigger) return;

    var message = trigger.getAttribute('data-confirm');
    if (message && !window.confirm(message)) event.preventDefault();
  });

  // ── 3 bis. Révélation du code PIN sur la fiche profil ─────────────────────

  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-pin-toggle]');
    if (!toggle) return;

    var masked = document.querySelector('[data-pin-masked]');
    var clear = document.querySelector('[data-pin-clear]');
    if (!masked || !clear) return;

    var revealed = !clear.hidden;
    clear.hidden = revealed;
    masked.hidden = !revealed;
    // Les libellés viennent du gabarit : ce fichier ne contient aucun texte.
    toggle.textContent = revealed
      ? toggle.getAttribute('data-label-show')
      : toggle.getAttribute('data-label-hide');
  });

  // ── 4. File d'attente hors-ligne ──────────────────────────────────────────

  var DB_NAME = 'merisu-offline';
  var STORE = 'queue';

  /**
   * IndexedDB plutôt que localStorage : la file doit survivre à la fermeture de
   * l'onglet et peut contenir des photos, qui dépasseraient vite le quota.
   */
  function openDb() {
    return new Promise(function (resolve, reject) {
      var request = indexedDB.open(DB_NAME, 1);
      request.onupgradeneeded = function () {
        if (!request.result.objectStoreNames.contains(STORE)) {
          request.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
        }
      };
      request.onsuccess = function () { resolve(request.result); };
      request.onerror = function () { reject(request.error); };
    });
  }

  function withStore(mode, action) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var request = action(db.transaction(STORE, mode).objectStore(STORE));
        request.onsuccess = function () { resolve(request.result); };
        request.onerror = function () { reject(request.error); };
      });
    });
  }

  var enqueue = function (item) { return withStore('readwrite', function (s) { return s.add(item); }); };
  var readAll = function () { return withStore('readonly', function (s) { return s.getAll(); }); };
  var remove = function (id) { return withStore('readwrite', function (s) { return s.delete(id); }); };

  // ── État réseau ───────────────────────────────────────────────────────────

  // Plus de pastille « En ligne » dans l'entête : quand tout va bien, l'écran
  // ne dit rien. Seule la coupure s'annonce, par le bandeau — et tant qu'il
  // reste des saisies en file, le compteur les rend visibles.
  var queueCount = document.querySelector('[data-queue-count]');
  var offlineBanner = document.querySelector('[data-offline-banner]');

  function refreshOfflineState() {
    var online = navigator.onLine;

    if (offlineBanner) offlineBanner.hidden = online;

    return readAll().then(function (items) {
      if (!queueCount) return;
      queueCount.hidden = items.length === 0;
      queueCount.textContent = items.length === 0 ? '' : '· ' + items.length;
    }).catch(function () { /* IndexedDB indisponible : le bandeau reste sobre. */ });
  }

  // ── Interception du formulaire de comptage ────────────────────────────────

  var countForm = document.querySelector('[data-count-form]');

  if (countForm) {
    countForm.addEventListener('submit', function (event) {
      // En ligne : on laisse le formulaire HTML partir normalement.
      if (navigator.onLine) return;

      event.preventDefault();

      var quantities = {};
      countForm.querySelectorAll('.qty__input').forEach(function (input) {
        var match = input.name.match(/^qty\[(.+)\]$/);
        if (match && input.value !== '') quantities[match[1]] = input.value;
      });

      // `submitter` distingue « Enregistrer » de « Valider » : la validation
      // doit être rejouée comme telle, sinon le plan ne serait jamais figé.
      var isValidation = event.submitter
        && (event.submitter.getAttribute('formaction') || '').indexOf('valider') !== -1;

      enqueue({
        url: countForm.getAttribute('data-sync-url'),
        payload: {
          date: countForm.getAttribute('data-date'),
          moment: countForm.getAttribute('data-moment'),
          workstationId: countForm.getAttribute('data-workstation'),
          quantities: quantities,
          validate: isValidation
        }
      }).then(refreshOfflineState);
    });
  }

  /**
   * Rejeu de la file, dans l'ordre d'arrivée.
   *
   * L'ordre compte : une validation ne doit jamais partir avant les quantités
   * qu'elle valide. On s'arrête donc au premier échec réseau.
   */
  var syncing = false;

  function flush() {
    if (syncing || !navigator.onLine) return Promise.resolve();
    syncing = true;

    return readAll().then(function (items) {
      var chain = Promise.resolve();

      items.sort(function (a, b) { return a.id - b.id; }).forEach(function (item) {
        chain = chain.then(function (stop) {
          if (stop) return true;

          return fetch(item.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(item.payload)
          }).then(function (response) {
            // 4xx : erreur métier, rejouer n'y changerait rien — on retire
            // l'envoi de la file plutôt que de boucler indéfiniment.
            if (response.ok || (response.status >= 400 && response.status < 500)) {
              return remove(item.id).then(function () { return false; });
            }
            return true;
          }).catch(function () {
            return true; // panne réseau : on préserve l'ordre en stoppant ici
          });
        });
      });

      return chain;
    }).then(function () {
      syncing = false;
      return refreshOfflineState();
    }).catch(function () {
      syncing = false;
    });
  }

  window.addEventListener('online', function () { refreshOfflineState(); flush(); });
  window.addEventListener('offline', refreshOfflineState);

  refreshOfflineState();
  flush();

  // ── 5. Service worker (installation sur l'écran d'accueil, §2) ────────────

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      // L'URL vient du gabarit : elle suit le préfixe d'installation.
      var script = document.querySelector('script[data-sw-url]');
      var swUrl = script && script.getAttribute('data-sw-url');
      if (!swUrl) return;

      navigator.serviceWorker.register(swUrl).catch(function () {
        // Enregistrement impossible (HTTP simple, navigation privée…) :
        // l'application reste utilisable, sans mode hors-ligne.
      });
    });
  }
})();
