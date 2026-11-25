// Listes longues (destinations, communes) : un champ au-dessus de la liste permet de taper les 3 premières lettres (ou plus).
// Les lieux correspondants s'affichent aussitôt ; Entrée ou un clic choisit. La liste <select> reste le vrai champ du formulaire.
// À activer avec l'attribut data-recherche sur le <select>.
(function () {
    var MIN_LETTRES = 3;

    // Sans accents ni majuscules : "bouake" trouve "Bouaké"
    function simplifier(texte) {
        return String(texte).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }
    // Le texte commence par la saisie, ou l'un de ses mots (San-Pédro, Grand-Bassam, Port-Bouët…)
    function correspond(texte, saisie) {
        texte = simplifier(texte);
        return texte.indexOf(saisie) === 0
            || texte.split(/[\s\-'’(\/]+/).some(function (mot) { return mot.indexOf(saisie) === 0; });
    }

    document.querySelectorAll('select[data-recherche]').forEach(function (liste) {
        var champ = document.createElement('input');
        champ.type = 'search';
        champ.className = 'recherche-liste';
        champ.placeholder = liste.getAttribute('data-recherche') || 'Tapez les premières lettres…';
        champ.setAttribute('autocomplete', 'off');
        champ.setAttribute('aria-label', 'Rechercher dans la liste');
        var aucun = document.createElement('p');
        aucun.className = 'petit cache';
        aucun.textContent = 'Aucun lieu ne commence par ces lettres.';
        liste.parentNode.insertBefore(champ, liste);
        liste.parentNode.insertBefore(aucun, liste.nextSibling);

        function options() {
            return Array.prototype.filter.call(liste.options, function (o) { return o.value !== ''; });
        }
        function visibles() {
            return options().filter(function (o) { return !o.hidden; });
        }
        function choisir(option) {
            if (!option || liste.value === option.value) return;
            liste.value = option.value;
            liste.dispatchEvent(new Event('change', { bubbles: true }));
        }
        // Remet la liste déroulante normale, avec tous les lieux
        function refermer() {
            Array.prototype.forEach.call(liste.options, function (o) { o.hidden = false; });
            liste.querySelectorAll('optgroup').forEach(function (g) { g.hidden = false; });
            liste.removeAttribute('size');
            aucun.classList.add('cache');
        }

        champ.addEventListener('input', function () {
            var saisie = simplifier(champ.value);
            // La recherche démarre à partir de 3 lettres
            if (saisie.length < MIN_LETTRES) { refermer(); return; }
            // « — Choisir — » est masqué pendant la recherche
            Array.prototype.forEach.call(liste.options, function (o) { o.hidden = o.value === '' || !correspond(o.text, saisie); });
            liste.querySelectorAll('optgroup').forEach(function (g) {
                g.hidden = !Array.prototype.some.call(g.children, function (o) { return !o.hidden; });
            });
            var trouves = visibles();
            aucun.classList.toggle('cache', trouves.length > 0);
            // Liste ouverte sous le champ, avec les lieux trouvés
            liste.size = Math.max(2, Math.min(8, trouves.length + liste.querySelectorAll('optgroup:not([hidden])').length));
            // Le premier lieu trouvé est présélectionné (le prix ou le carburant se met à jour)
            var actuel = liste.selectedOptions[0];
            if (trouves.length && (!actuel || actuel.hidden || !actuel.value)) choisir(trouves[0]);
        });

        champ.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowDown' && liste.size > 1) {
                ev.preventDefault();
                liste.focus();
            } else if (ev.key === 'Enter') {
                // Entrée valide le lieu sélectionné au lieu d'envoyer le formulaire
                ev.preventDefault();
                if (simplifier(champ.value).length < MIN_LETTRES) return;
                var actuel = liste.selectedOptions[0];
                choisir(actuel && !actuel.hidden && actuel.value ? actuel : visibles()[0]);
                if (liste.value) { champ.value = ''; refermer(); }
            } else if (ev.key === 'Escape') {
                champ.value = '';
                refermer();
            }
        });

        // Clic sur un lieu de la liste ouverte, ou Entrée dedans : choisi, la liste se referme
        liste.addEventListener('click', function (ev) {
            if (liste.size > 1 && ev.target.tagName === 'OPTION') { champ.value = ''; refermer(); }
        });
        liste.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && liste.size > 1) { ev.preventDefault(); champ.value = ''; refermer(); }
        });
    });
})();
