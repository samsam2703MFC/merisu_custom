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

  // ── 2 ter. Saisie du code PIN, coupe par coupe ────────────────────────────

  // Sans ce bloc, la saisie reste possible : six champs et la touche Tab
  // suffisent. Il n'ajoute que l'enchaînement attendu d'un code à chiffres —
  // avancer seul, revenir sur effacement, accepter un code collé d'un bloc.
  var coupes = Array.prototype.slice.call(document.querySelectorAll('.pin__cup'));

  if (coupes.length) {
    coupes.forEach(function (coupe, rang) {
      coupe.addEventListener('input', function () {
        // Un clavier de téléphone peut livrer plusieurs chiffres d'un coup.
        var chiffres = coupe.value.replace(/\D/g, '');

        if (chiffres.length > 1) {
          repartir(chiffres, rang);
          return;
        }

        coupe.value = chiffres;
        if (chiffres && coupes[rang + 1]) coupes[rang + 1].focus();
      });

      coupe.addEventListener('keydown', function (event) {
        // Effacer une coupe déjà vide remonte à la précédente : c'est le
        // geste naturel pour corriger, sans viser la bonne case du doigt.
        if (event.key === 'Backspace' && !coupe.value && coupes[rang - 1]) {
          coupes[rang - 1].focus();
          coupes[rang - 1].value = '';
          event.preventDefault();
        }
        if (event.key === 'ArrowLeft' && coupes[rang - 1]) coupes[rang - 1].focus();
        if (event.key === 'ArrowRight' && coupes[rang + 1]) coupes[rang + 1].focus();
      });

      coupe.addEventListener('paste', function (event) {
        var colle = (event.clipboardData || window.clipboardData).getData('text');
        event.preventDefault();
        repartir(colle.replace(/\D/g, ''), rang);
      });

      // Toucher une coupe déjà remplie sélectionne son chiffre : la frappe
      // suivante le remplace, au lieu de s'ajouter et d'être ignorée.
      coupe.addEventListener('focus', function () { coupe.select(); });
    });

    function repartir(chiffres, depart) {
      for (var i = 0; i < chiffres.length && depart + i < coupes.length; i++) {
        coupes[depart + i].value = chiffres.charAt(i);
      }

      var suivante = Math.min(depart + chiffres.length, coupes.length - 1);
      coupes[suivante].focus();
    }
  }

  // ── 3. Confirmation avant action verrouillante ────────────────────────────

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-confirm]');
    if (!trigger) return;

    var message = trigger.getAttribute('data-confirm');
    if (message && !window.confirm(message)) event.preventDefault();
  });

  // ── 3 quater. Le champ actif reste sous les yeux ──────────────────────────

  /*
   * Sur un téléphone, le clavier logiciel recouvre la moitié basse de l'écran.
   * Le champ que l'on vient de toucher se retrouve dessous, invisible : on tape
   * à l'aveugle, ou on fait défiler d'une main en tenant l'autre au-dessus du
   * clavier. C'est le geste le plus pénible de toute la saisie de stock.
   *
   * On recentre donc le champ dans la bande RÉELLEMENT visible — sous l'entête
   * collant, au-dessus du clavier — que `visualViewport` sait mesurer, à la
   * différence de `window.innerHeight` qui ignore le clavier.
   *
   * Deux précautions :
   * · on ne bouge QUE si le champ n'est pas déjà confortablement visible. Un
   *   défilement à chaque tabulation donnerait le mal de mer ;
   * · on fait défiler la page, jamais on ne redimensionne quoi que ce soit :
   *   la mise en page ne bouge pas d'un pixel, seul le point de vue change.
   */
  (function () {
    var vue = window.visualViewport;
    var marge = 16;             // respiration minimale autour du champ
    var actif = null;

    function doux() {
      return !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function estSaisie(el) {
      if (!el || !el.matches) return false;
      if (!el.matches('input, select, textarea, [contenteditable="true"]')) return false;

      // Cases, boutons radio et boutons déguisés en <input> n'ouvrent aucun
      // clavier : les recentrer serait un mouvement gratuit.
      return !el.matches(
        '[type=hidden], [type=checkbox], [type=radio], [type=submit], [type=button], [type=reset], [type=file]'
      );
    }

    /** Bande visible, en coordonnées de page. */
    function bande() {
      var haut = vue ? vue.offsetTop : 0;
      var hauteur = vue ? vue.height : window.innerHeight;

      var entete = document.querySelector('.app-header');
      if (entete) {
        var rect = entete.getBoundingClientRect();
        // Entête collant : il mange le sommet de la bande.
        if (rect.bottom > haut) haut = rect.bottom;
      }

      return { haut: haut, bas: (vue ? vue.offsetTop : 0) + hauteur };
    }

    function recentrer(el) {
      if (!el || !el.isConnected) return;

      var b = bande();
      var rect = el.getBoundingClientRect();

      // Déjà bien placé : on ne touche à rien.
      if (rect.top >= b.haut + marge && rect.bottom <= b.bas - marge) return;

      var hauteurBande = b.bas - b.haut;
      var cible = b.haut + Math.max(0, (hauteurBande - rect.height) / 2);
      var delta = rect.top - cible;

      if (Math.abs(delta) < 2) return;

      window.scrollBy({ top: delta, behavior: doux() ? 'smooth' : 'auto' });
    }

    document.addEventListener('focusin', function (event) {
      if (!estSaisie(event.target)) return;
      actif = event.target;

      // Un cran d'attente : le clavier n'est pas encore monté, et mesurer la
      // bande maintenant donnerait la hauteur d'avant. `visualViewport` nous
      // rappellera de toute façon quand il aura fini de s'ouvrir.
      window.requestAnimationFrame(function () { recentrer(actif); });
    });

    document.addEventListener('focusout', function () { actif = null; });

    // Ouverture du clavier, rotation de l'écran : la bande visible change de
    // hauteur sans que le champ ait bougé. C'est ici que se joue l'essentiel.
    if (vue) {
      var attente = null;
      vue.addEventListener('resize', function () {
        if (attente) window.clearTimeout(attente);
        attente = window.setTimeout(function () { recentrer(actif); }, 80);
      });
    }
  }());

  // ── 3 ter. Impression de la planche d'étiquettes ──────────────────────────

  // Raccourci, rien de plus : la page est déjà mise en page pour l'impression,
  // donc le Ctrl+P du navigateur donne exactement le même résultat. Sans ce
  // script, on perd un bouton, pas une fonction.
  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-print]')) window.print();
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
