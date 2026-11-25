// Page Carburant : recalcule dès qu'une destination est choisie (le camion est facultatif)
const form = document.getElementById('form-calcul');
const resultat = document.getElementById('resultat');

// Échappe le texte venu de la base avant de l'insérer dans la page (évite l'injection de code)
function echapper(texte) {
    return String(texte ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

async function calculer() {
    const destination = form.destination_id.value;

    if (!destination) {
        resultat.classList.add('cache');
        return;
    }

    const params = new URLSearchParams({
        camion_id: form.camion_id.value,
        destination_id: destination,
        aller: form.aller.value,
        retour: form.retour.value,
        marge: form.marge.value || '0',
    });

    try {
        const reponse = await fetch('api/calcul.php?' + params);
        const data = await reponse.json();
        if (!reponse.ok) throw new Error(data.erreur || 'Erreur serveur');
        afficher(data);
    } catch (err) {
        resultat.innerHTML = `<p class="erreur">${echapper(err.message)}</p>`;
        resultat.classList.remove('cache');
    }
}

function afficher(d) {
    const nombre = n => n.toLocaleString('fr-FR');
    const lienMission = `disponibilite.php?destination=${d.destination_id}&aller=${d.aller}&retour=${d.retour}`;

    let blocCamion;
    if (d.camion_id) {
        const alerte = d.nb_pleins > 1
            ? `<p class="alerte">Le réservoir (${d.reservoir_l} L) ne suffit pas : prévoir ${d.nb_pleins - 1} ravitaillement(s) en route.</p>`
            : `<p class="succes">Un plein suffit pour ce trajet.</p>`;
        const mission = d.statut === 'disponible'
            ? `<a class="btn btn-auto" href="${lienMission}&camion=${d.camion_id}#m-chauffeur">Envoyer ce camion en mission</a>`
            : `<p class="alerte">Ce camion est actuellement <b>${echapper(d.statut_libelle.toLowerCase())}</b>.</p>`;
        blocCamion = `
            <ul class="details">
                <li><b>Camion :</b> ${echapper(d.camion)}</li>
                <li><b>Semi-remorque :</b> ${echapper(d.remorque || 'aucune')}</li>
            </ul>
            <div class="jauge"><div style="width:${Math.min(d.pct_reservoir, 100)}%"></div></div>
            <p class="petit">${d.pct_reservoir} % du réservoir</p>
            ${alerte}
            ${mission}`;
    } else {
        blocCamion = `
            <p class="petit">Choisissez un camion pour vérifier si son réservoir suffit.</p>
            <a class="btn btn-auto" href="${lienMission}#m-camion">Créer une mission vers ${echapper(d.destination)}</a>`;
    }

    const lignes = d.trajets.map(t => `
        <tr>
            <td>${t.sens}</td><td>${t.etat}</td>
            <td>${nombre(t.km)} km × ${nombre(t.taux)} L/km</td>
            <td><b>${nombre(t.litres)} L</b></td>
        </tr>`).join('');

    resultat.innerHTML = `
        <div class="resume-calcul">
            <div><small>Carburant nécessaire</small><span class="litres">${nombre(d.total_l)} L</span></div>
            <div><small>Montant du carburant</small><span class="litres montant-carburant">${echapper(d.cout)}</span>
                <span class="petit">${nombre(d.total_l)} L × ${nombre(d.prix_litre)} ${echapper(d.devise)}</span></div>
        </div>
        <table class="tableau-calcul">
            <thead><tr><th>Trajet</th><th>État</th><th>Calcul</th><th>Litres</th></tr></thead>
            <tbody>${lignes}</tbody>
        </table>
        <ul class="details">
            <li><b>Destination :</b> ${echapper(d.destination)} —${nombre(d.distance_km)} km au total</li>
            ${d.marge_pct > 0 ? `<li><b>Marge de sécurité :</b> ${d.marge_pct} % (${nombre(d.carburant_l)} L sans marge)</li>` : ''}
        </ul>
        ${blocCamion}`;
    resultat.classList.remove('cache');
}

form.addEventListener('change', calculer);
form.marge.addEventListener('input', calculer);
