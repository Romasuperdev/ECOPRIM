import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { GraduationCap } from 'lucide-react'
import CarteFormulaire, { BoutonsFormulaire, GrilleFormulaire, OngletsFormulaire } from '../../components/ui/CarteFormulaire'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import ModaleAcces from '../../components/ui/ModaleAcces'
import SelecteurMultiple from '../../components/ui/SelecteurMultiple'
import { fetchMatieres } from '../reference/referenceApi'
import { fetchEnseignant, createEnseignant, updateEnseignant } from './enseignantsApi'

const VIDE = {
  matricule: '', nom: '', prenom: '', sexe: '', date_naissance: '', lieu_naissance: '',
  situation_matrimoniale: '',
  adresse: '', ville: '', telephone: '', cellulaire: '', email: '',
  statut: '', corps: '', grade: '', echelon: '', diplome: '', formation: '',
  matieres: [], volume_horaire: '', date_embauche: '', annee_code: '',
  fonction: '', emploi: '', dren: '', dden: '', service: '',
  date_premiere_prise_service: '', ecole_prise_service: '', annees_service: '', date_arrivee_poste: '',
  date_depart: '', motif_depart: '', etab_accueil: '',
}

const STATUTS = ['TITULAIRE', 'VACATAIRE', 'CONTRACTUEL', 'STAGIAIRE']
const SITUATIONS = [
  ['CELIBATAIRE', 'Célibataire'], ['MARIE', 'Marié(e)'],
  ['DIVORCE', 'Divorcé(e)'], ['VEUF', 'Veuf / Veuve'],
]

// Onglets du formulaire, librement accessibles. « Départ » n'apparaît qu'en modification :
// on ne renseigne pas le départ d'un enseignant qu'on est en train de recruter.
// Chaque onglet liste ses champs : une erreur (locale ou du serveur) sur l'un d'eux
// marque l'onglet, et l'enregistrement y ramène l'utilisateur.
const ONGLETS_BASE = [
  { libelle: 'État civil', champs: ['matricule', 'nom', 'prenom', 'sexe', 'date_naissance', 'lieu_naissance', 'situation_matrimoniale'] },
  { libelle: 'Coordonnées', champs: ['adresse', 'ville', 'telephone', 'cellulaire', 'email'] },
  { libelle: 'Carrière', champs: ['statut', 'corps', 'grade', 'echelon', 'diplome', 'formation', 'matieres', 'volume_horaire', 'date_embauche', 'annee_code'] },
  { libelle: 'Administration', champs: ['fonction', 'emploi', 'service', 'dren', 'dden', 'date_premiere_prise_service', 'ecole_prise_service', 'annees_service', 'date_arrivee_poste'] },
]
const ONGLET_DEPART = { libelle: 'Départ', champs: ['date_depart', 'etab_accueil', 'motif_depart'] }

export default function EnseignantFormPage() {
  const { id } = useParams()
  const enEdition = Boolean(id)
  const navigate = useNavigate()
  const qc = useQueryClient()

  // Le formulaire est DÉRIVÉ de la fiche chargée + les modifications en cours : pas de
  // recopie par effet, donc pas de course entre le chargement et la saisie.
  const [modifs, setModifs] = useState({})
  const [onglet, setOnglet] = useState(0)
  const [erreurs, setErreurs] = useState({})
  // Identifiants de l'enseignant, affichés une seule fois après l'enregistrement.
  const [accesEnseignant, setAccesEnseignant] = useState(null)

  const onglets = enEdition ? [...ONGLETS_BASE, ONGLET_DEPART] : ONGLETS_BASE
  // Le matricule n'est exigé qu'à la création : le rendre obligatoire en modification
  // bloquerait toute fiche déjà existante sans matricule (comptes antérieurs à cette règle).
  const REQUIS = enEdition ? ['nom', 'prenom'] : ['matricule', 'nom', 'prenom']

  /** Index de l'onglet portant le champ `cle` (« matieres.0 » relève de « matieres »). */
  const ongletDe = (cle) => onglets.findIndex((o) => o.champs.includes(String(cle).split('.')[0]))
  const ongletEnErreur = (i) => Object.keys(erreurs).some((cle) => ongletDe(cle) === i)
  /** Ramène sur le premier onglet en erreur, s'il y en a un. */
  const allerALErreur = (err) => {
    const indices = Object.keys(err).map(ongletDe).filter((i) => i >= 0)
    if (indices.length) setOnglet(Math.min(...indices))
  }

  const { data: enseignant } = useQuery({
    queryKey: ['enseignants', id],
    queryFn: () => fetchEnseignant(id),
    enabled: enEdition,
  })

  const { data: matieres } = useQuery({ queryKey: ['matieres', 'all'], queryFn: fetchMatieres })

  const base = enseignant
    ? { ...VIDE, ...Object.fromEntries(Object.entries(enseignant).map(([k, v]) => [k, v ?? ''])) }
    : VIDE
  const form = { ...base, ...modifs }

  const enregistrer = useMutation({
    mutationFn: () => (enEdition ? updateEnseignant(id, form) : createEnseignant(form)),
    onSuccess: (enseignant) => {
      qc.invalidateQueries({ queryKey: ['enseignants'] })
      // Les identifiants ne s'affichent qu'une fois : on reste sur la page tant qu'ils
      // n'ont pas été lus, puis on retourne à la liste.
      if (enseignant?.acces_enseignant) {
        setAccesEnseignant(enseignant.acces_enseignant)
        return
      }
      navigate('/enseignants')
    },
    onError: (e) => {
      const err = e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }
      setErreurs(err)
      // Un champ refusé sur un autre onglet : on y ramène l'utilisateur.
      allerALErreur(err)
    },
  })

  const champ = (k, v) => setModifs((m) => ({ ...m, [k]: v }))
  const err = (k) => erreurs[k]?.[0]
  const visible = (i) => onglet === i

  /**
   * Enregistrement : un clic explicite sur « Enregistrer », et rien d'autre.
   *
   * Tout le formulaire est contrôlé d'un coup, quel que soit l'onglet affiché : un champ
   * requis vide sur un autre onglet y ramène l'utilisateur, l'onglet étant marqué.
   * Le bouton est de type « button » et le formulaire n'a aucun bouton « submit » :
   * la touche Entrée ne peut donc rien envoyer.
   */
  const soumettre = () => {
    if (enregistrer.isPending) return
    const manquants = REQUIS.filter((c) => !String(form[c] ?? '').trim())
    if (manquants.length) {
      const errLocales = Object.fromEntries(manquants.map((c) => [c, ['Ce champ est requis.']]))
      setErreurs(errLocales)
      allerALErreur(errLocales)
      return
    }
    setErreurs({})
    enregistrer.mutate()
  }

  return (
    <>
      <CarteFormulaire
        icon={GraduationCap}
        titre={enEdition ? `${form.nom} ${form.prenom}` : 'Nouvel enseignant'}
        retour="/enseignants"
        iconeCarte={GraduationCap}
        titreCarte={enEdition ? 'Modifier un enseignant' : 'Enregistrer un enseignant'}
        erreurs={erreurs}
      >
        <OngletsFormulaire
          onglets={onglets.map((o, i) => ({ cle: i, libelle: o.libelle, erreur: ongletEnErreur(i) }))}
          actif={onglet}
          onChanger={setOnglet}
        />

        {/* Le formulaire ne se soumet jamais de lui-même : l'enregistrement passe par
            `soumettre`. Ce garde-fou reste pour qu'une soumission accidentelle ne fasse rien. */}
        <form onSubmit={(e) => e.preventDefault()} noValidate>
          {/* 1 — État civil */}
          <div className={visible(0) ? '' : 'hidden'}>
            <GrilleFormulaire>
              <Input label="Matricule *" value={form.matricule} onChange={(e) => champ('matricule', e.target.value)} error={err('matricule')} />
              <Input label="Nom *" value={form.nom} onChange={(e) => champ('nom', e.target.value)} error={err('nom')} />
              <Input label="Prénom(s) *" value={form.prenom} onChange={(e) => champ('prenom', e.target.value)} error={err('prenom')} />
              <Select label="Sexe" value={form.sexe} onChange={(e) => champ('sexe', e.target.value)} error={err('sexe')}>
                <option value="">—</option>
                <option value="M">Masculin</option>
                <option value="F">Féminin</option>
              </Select>
              <Input label="Date de naissance" value={form.date_naissance} onChange={(e) => champ('date_naissance', e.target.value)}
                     error={err('date_naissance')} placeholder="jj/mm/aaaa" />
              <Input label="Lieu de naissance" value={form.lieu_naissance} onChange={(e) => champ('lieu_naissance', e.target.value)} error={err('lieu_naissance')} />
              <Select label="Situation matrimoniale" value={form.situation_matrimoniale}
                      onChange={(e) => champ('situation_matrimoniale', e.target.value)} error={err('situation_matrimoniale')}>
                <option value="">—</option>
                {SITUATIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </Select>
            </GrilleFormulaire>
          </div>

          {/* 2 — Coordonnées */}
          <div className={visible(1) ? '' : 'hidden'}>
            <GrilleFormulaire>
              <Input label="Adresse" value={form.adresse} onChange={(e) => champ('adresse', e.target.value)} error={err('adresse')} />
              <Input label="Ville" value={form.ville} onChange={(e) => champ('ville', e.target.value)} error={err('ville')} />
              <Input label="Téléphone" value={form.telephone} onChange={(e) => champ('telephone', e.target.value)} error={err('telephone')} />
              <Input label="Cellulaire" value={form.cellulaire} onChange={(e) => champ('cellulaire', e.target.value)} error={err('cellulaire')} />
              <Input label="Email" value={form.email} onChange={(e) => champ('email', e.target.value)} error={err('email')} />
            </GrilleFormulaire>
          </div>

          {/* 3 — Carrière */}
          <div className={visible(2) ? '' : 'hidden'}>
            <GrilleFormulaire>
              <Select label="Statut" value={form.statut} onChange={(e) => champ('statut', e.target.value)} error={err('statut')}>
                <option value="">—</option>
                {STATUTS.map((s) => <option key={s} value={s}>{s.charAt(0) + s.slice(1).toLowerCase()}</option>)}
              </Select>
              <Input label="Corps" value={form.corps} onChange={(e) => champ('corps', e.target.value)} error={err('corps')} />
              <Input label="Grade" value={form.grade} onChange={(e) => champ('grade', e.target.value)} error={err('grade')} />
              <Input label="Échelon" value={form.echelon} onChange={(e) => champ('echelon', e.target.value)} error={err('echelon')} />
              <Input label="Diplôme / titre" value={form.diplome} onChange={(e) => champ('diplome', e.target.value)} error={err('diplome')} />
              <Input label="Formation professionnelle" value={form.formation} onChange={(e) => champ('formation', e.target.value)} error={err('formation')} />
              {/* Les matières se cochent dans le référentiel, au lieu d'être tapées à la
                  main : l'ancien champ libre acceptait n'importe quoi, ne tenait qu'une
                  valeur, et n'était rattaché à aucune matière créée. */}
              <SelecteurMultiple
                label="Matières enseignées"
                options={(matieres ?? []).map((m) => ({ valeur: m.code, libelle: m.libelle }))}
                selection={form.matieres ?? []}
                onChange={(v) => champ('matieres', v)}
                placeholder="— Cocher les matières —"
                error={err('matieres') ?? err('matieres.0')}
              />
              <Input label="Volume horaire (h/sem.)" type="number" min="0" max="60" value={form.volume_horaire}
                     onChange={(e) => champ('volume_horaire', e.target.value)} error={err('volume_horaire')} />
              <Input label="Date d’embauche" value={form.date_embauche} onChange={(e) => champ('date_embauche', e.target.value)}
                     error={err('date_embauche')} placeholder="jj/mm/aaaa" />
              <Input label="Année scolaire" value={form.annee_code} onChange={(e) => champ('annee_code', e.target.value)} error={err('annee_code')} />
            </GrilleFormulaire>
            <p className="mt-3 text-xs text-muted">
              Le salaire reste géré dans ECONOMAT et n’est jamais modifié depuis NEXORA.
            </p>
          </div>

          {/* 4 — Administration (rattachement administratif) */}
          <div className={visible(3) ? '' : 'hidden'}>
            <GrilleFormulaire>
              <Input label="Fonction" value={form.fonction} onChange={(e) => champ('fonction', e.target.value)} error={err('fonction')} />
              <Input label="Emploi" value={form.emploi} onChange={(e) => champ('emploi', e.target.value)} error={err('emploi')} />
              <Input label="Service" value={form.service} onChange={(e) => champ('service', e.target.value)} error={err('service')} />
              <Input label="DREN" value={form.dren} onChange={(e) => champ('dren', e.target.value)} error={err('dren')} />
              <Input label="DDEN" value={form.dden} onChange={(e) => champ('dden', e.target.value)} error={err('dden')} />
              <Input label="1re prise de service" type="date" value={form.date_premiere_prise_service}
                     onChange={(e) => champ('date_premiere_prise_service', e.target.value)} error={err('date_premiere_prise_service')} />
              <Input label="École de 1re prise de service" value={form.ecole_prise_service}
                     onChange={(e) => champ('ecole_prise_service', e.target.value)} error={err('ecole_prise_service')} />
              <Input label="Années de service" type="number" min="0" max="60" value={form.annees_service}
                     onChange={(e) => champ('annees_service', e.target.value)} error={err('annees_service')} />
              <Input label="Arrivée au poste" type="date" value={form.date_arrivee_poste}
                     onChange={(e) => champ('date_arrivee_poste', e.target.value)} error={err('date_arrivee_poste')} />
            </GrilleFormulaire>
          </div>

          {/* 5 — Départ (modification seulement) */}
          {enEdition && (
            <div className={visible(4) ? '' : 'hidden'}>
              <p className="mb-3 text-xs text-muted">
                Un enseignant n’est jamais supprimé : son départ se renseigne ici.
              </p>
              <GrilleFormulaire colonnes={2}>
                <Input label="Date de départ" value={form.date_depart} onChange={(e) => champ('date_depart', e.target.value)}
                       error={err('date_depart')} placeholder="jj/mm/aaaa" />
                <Input label="Établissement d’accueil" value={form.etab_accueil}
                       onChange={(e) => champ('etab_accueil', e.target.value)} error={err('etab_accueil')} />
              </GrilleFormulaire>
              <div className="mt-4">
                <Textarea label="Motif" rows={2} value={form.motif_depart}
                          onChange={(e) => champ('motif_depart', e.target.value)} error={err('motif_depart')} />
              </div>
            </div>
          )}

          <BoutonsFormulaire
            annulerVers="/enseignants"
            type="button"
            onValider={soumettre}
            enCours={enregistrer.isPending}
            libelle={enEdition ? 'Mettre à jour' : 'Enregistrer'}
          />
        </form>
      </CarteFormulaire>

      <ModaleAcces acces={accesEnseignant} onFermer={() => navigate('/enseignants')} />
    </>
  )
}
