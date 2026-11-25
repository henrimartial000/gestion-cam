// Formulaire "Envoyer en mission" : affiche le carburant, son montant et le bénéfice estimé
// dès qu'une destination est choisie.
(function () {
    const apercu = document.getElementById('apercu-mission');
    if (!apercu) return;

    const champ = id => document.getElementById(id);
    const destination = champ('m-destination');
    const aller = champ('m-aller');
    const retour = champ('m-sens-retour');
    const montant = champ('m-montant');
    const frais = champ('m-frais');

    const nombre = n => Math.round(n).toLocaleString('fr-FR');
    // Échappe le texte venu de la base avant de l'insérer dans la page
    const echapper = texte => String(texte ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    let dernier = null;

    async function calculer() {
        if (!destination.value) {
            apercu.classList.add('cache');
            dernier = null;
            return;
        }
        const params = new URLSearchParams({
            destination_id: destination.value,
            aller: aller.value,
            retour: retour.value,
        });
        try {
            const reponse = await fetch('api/calcul.php?' + params);
            const data = await reponse.json();
            if (!reponse.ok) throw new Error(data.erreur || 'Erreur serveur');
            dernier = data;
            afficher();
        } catch (err) {
            apercu.innerHTML = `<p class="erreur">${echapper(err.message)}</p>`;
            apercu.classList.remove('cache');
        }
    }

    // Le bénéfice se recalcule sans appeler le serveur quand on tape le montant ou les frais
    function afficher() {
        if (!dernier) return;
        const d = dernier;
        const facture = parseFloat(montant.value) || 0;
        const autres = parseFloat(frais.value) || 0;
        const benefice = facture - d.cout_valeur - autres;
        const detail = d.trajets.map(t => `${t.sens.toLowerCase()} ${t.etat} : ${t.km} × ${t.taux.toLocaleString('fr-FR')} = ${t.litres.toLocaleString('fr-FR')} L`).join(' · ');

        apercu.innerHTML = `
            <div class="apercu-ligne"><span>Carburant</span><b>${d.total_l.toLocaleString('fr-FR')} L</b></div>
            <div class="apercu-ligne"><span>Montant du carburant</span><b>${echapper(d.cout)}</b></div>
            <p class="petit">${detail} · ${d.total_l.toLocaleString('fr-FR')} L × ${d.prix_litre.toLocaleString('fr-FR')} ${echapper(d.devise)}</p>
            ${facture > 0
                ? `<div class="apercu-ligne apercu-benefice"><span>Bénéfice estimé</span>
                       <b class="${benefice < 0 ? 'negatif' : 'positif'}">${nombre(benefice)} ${echapper(d.devise)}</b></div>`
                : `<p class="petit">Saisissez le montant facturé pour voir le bénéfice estimé.</p>`}`;
        apercu.classList.remove('cache');
    }

    [destination, aller, retour].forEach(el => el.addEventListener('change', calculer));
    [montant, frais].forEach(el => el.addEventListener('input', afficher));

    // Destination déjà choisie (venue de la page Carburant ou après une erreur)
    calculer();
})();
