import { useState } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import StepIndicator from '../../components/ui/StepIndicator'
import { fetchEnseignant, createEnseignant, updateEnseignant } from './enseignantsApi'

const VIDE = {
  matricule: '', nom: '', prenom: '', sexe: '', date_naissance: '', lieu_naissance: '',
  situation_matrimoniale: '',
  adresse: '', ville: '', telephone: '', cellulaire: '', email: '',
  statut: '', corps: '', grade: '', echelon: '', diplome: '', formation: '',
  matiere: '', volume_horaire: '', date_embauche: '', annee_code: '',
  fonction: '', emploi: '', dren: '', dden: '', service: '',
  date_premiere_prise_service: '', ecole_prise_service: '', annees_service: '', date_arrivee_poste: '',
  date_depart: '', motif_depart: '', etab_accueil: '',
}

const STATUTS = ['TITULAIRE', 'VACATAIRE', 'CONTRACTUEL', 'STAGIAIRE']
const SITUATIONS = [
  ['CELIBATAIRE', 'Célibataire'], ['MARIE', 'Marié(e)'],
  ['DIVORCE', 'Divorcé(e)'], ['VEUF', 'Veuf / Veuve'],
]

// Étapes de l'assistant. « Départ » n'apparaît qu'en modification : on ne renseigne
// pas le départ d'un enseignant qu'on est en train de recruter.
const ETAPES_BASE = ['État civil', 'Coordonnées', 'Carrière', 'Administration']

export default function EnseignantFormPage() {
  const { id } = useParams()
  const enEdition = Boolean(id)
  const navigate = useNavigate()
  const qc = useQueryClient()

  // Le formulaire est DÉRIVÉ de la fiche chargée + les modifications en cours : pas de
  // recopie par effet, donc pas de course entre le chargement et la saisie.
  const [modifs, setModifs] = useState({})
  const [etape, setEtape] = useState(0)
  const [erreurs, setErreurs] = useState({})

  const etapes = enEdition ? [...ETAPES_BASE, 'Départ'] : ETAPES_BASE
  // Le matricule n'est exigé qu'à la création : le rendre obligatoire en modification
  // bloquerait toute fiche déjà existante sans matricule (comptes antérieurs à cette règle).
  const REQUIS = [enEdition ? ['nom', 'prenom'] : ['matricule', 'nom', 'prenom'], [], [], [], []]

  const { data: enseignant } = useQuery({
    queryKey: ['enseignants', id],
    queryFn: () => fetchEnseignant(id),
    enabled: enEdition,
  })

  const base = enseignant
    ? { ...VIDE, ...Object.fromEntries(Object.entries(enseignant).map(([k, v]) => [k, v ?? ''])) }
    : VIDE
  const form = { ...base, ...modifs }

  const enregistrer = useMutation({
    mutationFn: () => (enEdition ? updateEnseignant(id, form) : createEnseignant(form)),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['enseignants'] })
      navigate('/enseignants')
    },
    onError: (e) => {
      const err = e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }
      setErreurs(err)
      // Un champ refusé sur une étape précédente : on y ramène l'utilisateur.
      const fautive = REQUIS.findIndex((champs) => champs.some((c) => err[c]))
      if (fautive >= 0 && fautive !== etape) setEtape(fautive)
    },
  })

  const champ = (k, v) => setModifs((m) => ({ ...m, [k]: v }))
  const err = (k) => erreurs[k]?.[0]
  const visible = (i) => etape === i
  const derniere = etape === etapes.length - 1

  const suivant = () => {
    const manquants = (REQUIS[etape] ?? []).filter((c) => !String(form[c] ?? '').trim())
    if (manquants.length) {
      setErreurs(Object.fromEntries(manquants.map((c) => [c, ['Ce champ est requis.']])))
      return
    }
    setErreurs({})
    setEtape((e) => e + 1)
  }

  return (
    <div className="max-w-4xl">
      <div className="mb-6 flex items-center gap-3">
        <Link to="/enseignants" className="rounded p-1.5 text-slate-500 hover:bg-slate-100">
          <ArrowLeft size={18} />
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-slate-800">
            {enEdition ? `${form.nom} ${form.prenom}` : 'Nouvel enseignant'}
          </h1>
          <p className="text-xs text-slate-400">Fiche alimentée par ECONOMAT.T_PROFESSEUR.</p>
        </div>
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-6">
        <StepIndicator etapes={etapes} etape={etape} onAller={(i) => { setErreurs({}); setEtape(i) }} />

        <form
          onSubmit={(e) => {
            e.preventDefault()
            setErreurs({})
            enregistrer.mutate()
          }}
          onKeyDown={(e) => {
            // La touche Entrée ne doit jamais déclencher l'enregistrement : elle avance
            // seulement dans l'assistant. Seul un clic explicite sur « Enregistrer » sauvegarde.
            if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA') return
            e.preventDefault()
            if (!derniere) suivant()
          }}
          className="space-y-5"
        >
          {/* 1 — État civil */}
          <div className={visible(0) ? '' : 'hidden'}>
            <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">État civil</p>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
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
            </div>
          </div>

          {/* 2 — Coordonnées */}
          <div className={visible(1) ? '' : 'hidden'}>
            <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Coordonnées</p>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              <Input label="Adresse" value={form.adresse} onChange={(e) => champ('adresse', e.target.value)} error={err('adresse')} />
              <Input label="Ville" value={form.ville} onChange={(e) => champ('ville', e.target.value)} error={err('ville')} />
              <Input label="Téléphone" value={form.telephone} onChange={(e) => champ('telephone', e.target.value)} error={err('telephone')} />
              <Input label="Cellulaire" value={form.cellulaire} onChange={(e) => champ('cellulaire', e.target.value)} error={err('cellulaire')} />
              <Input label="Email" value={form.email} onChange={(e) => champ('email', e.target.value)} error={err('email')} />
            </div>
          </div>

          {/* 3 — Carrière */}
          <div className={visible(2) ? '' : 'hidden'}>
            <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Carrière</p>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              <Select label="Statut" value={form.statut} onChange={(e) => champ('statut', e.target.value)} error={err('statut')}>
                <option value="">—</option>
                {STATUTS.map((s) => <option key={s} value={s}>{s.charAt(0) + s.slice(1).toLowerCase()}</option>)}
              </Select>
              <Input label="Corps" value={form.corps} onChange={(e) => champ('corps', e.target.value)} error={err('corps')} />
              <Input label="Grade" value={form.grade} onChange={(e) => champ('grade', e.target.value)} error={err('grade')} />
              <Input label="Échelon" value={form.echelon} onChange={(e) => champ('echelon', e.target.value)} error={err('echelon')} />
              <Input label="Diplôme / titre" value={form.diplome} onChange={(e) => champ('diplome', e.target.value)} error={err('diplome')} />
              <Input label="Formation professionnelle" value={form.formation} onChange={(e) => champ('formation', e.target.value)} error={err('formation')} />
              <Input label="Matière enseignée" value={form.matiere} onChange={(e) => champ('matiere', e.target.value)} error={err('matiere')} />
              <Input label="Volume horaire (h/sem.)" type="number" min="0" max="60" value={form.volume_horaire}
                     onChange={(e) => champ('volume_horaire', e.target.value)} error={err('volume_horaire')} />
              <Input label="Date d’embauche" value={form.date_embauche} onChange={(e) => champ('date_embauche', e.target.value)}
                     error={err('date_embauche')} placeholder="jj/mm/aaaa" />
              <Input label="Année scolaire" value={form.annee_code} onChange={(e) => champ('annee_code', e.target.value)} error={err('annee_code')} />
            </div>
            <p className="mt-3 text-xs text-slate-400">
              Le salaire reste géré dans ECONOMAT et n’est jamais modifié depuis NEXORA.
            </p>
          </div>

          {/* 4 — Administration */}
          <div className={visible(3) ? '' : 'hidden'}>
            <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Rattachement administratif</p>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
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
            </div>
          </div>

          {/* 5 — Départ (modification seulement) */}
          {enEdition && (
            <div className={visible(4) ? '' : 'hidden'}>
              <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Départ</p>
              <p className="mb-3 text-xs text-slate-400">
                Un enseignant n’est jamais supprimé : son départ se renseigne ici.
              </p>
              <div className="grid grid-cols-2 gap-3">
                <Input label="Date de départ" value={form.date_depart} onChange={(e) => champ('date_depart', e.target.value)}
                       error={err('date_depart')} placeholder="jj/mm/aaaa" />
                <Input label="Établissement d’accueil" value={form.etab_accueil}
                       onChange={(e) => champ('etab_accueil', e.target.value)} error={err('etab_accueil')} />
              </div>
              <div className="mt-3">
                <Textarea label="Motif" rows={2} value={form.motif_depart}
                          onChange={(e) => champ('motif_depart', e.target.value)} error={err('motif_depart')} />
              </div>
            </div>
          )}

          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}

          <div className="flex items-center justify-between gap-2 border-t border-slate-100 pt-4">
            {etape > 0 ? (
              <Button type="button" variant="outline" onClick={() => { setErreurs({}); setEtape((e) => e - 1) }}>
                Précédent
              </Button>
            ) : (
              <Button type="button" variant="outline" onClick={() => navigate('/enseignants')}>Annuler</Button>
            )}

            <div className="flex items-center gap-2">
              <span className="text-xs text-slate-400">Étape {etape + 1} sur {etapes.length}</span>
              {!derniere ? (
                <Button type="button" onClick={suivant}>Suivant</Button>
              ) : (
                <Button type="submit" disabled={enregistrer.isPending}>
                  {enregistrer.isPending ? 'Enregistrement…' : enEdition ? 'Mettre à jour' : 'Enregistrer'}
                </Button>
              )}
            </div>
          </div>
        </form>
      </div>
    </div>
  )
}
