import { useEffect, useState } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, User, Phone, GraduationCap, LogOut } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import FormSection from '../../components/ui/FormSection'
import { fetchEnseignant, createEnseignant, updateEnseignant } from './enseignantsApi'

const VIDE = {
  matricule: '', nom: '', prenom: '', sexe: '', date_naissance: '', lieu_naissance: '',
  situation_matrimoniale: '', adresse: '', ville: '', telephone: '', cellulaire: '', email: '',
  statut: '', grade: '', diplome: '', matiere: '', date_embauche: '', annee_code: '',
  date_depart: '', motif_depart: '', etab_accueil: '',
}

const STATUTS = ['TITULAIRE', 'VACATAIRE', 'CONTRACTUEL', 'STAGIAIRE']

export default function EnseignantFormPage() {
  const { id } = useParams()
  const enEdition = Boolean(id)
  const navigate = useNavigate()
  const qc = useQueryClient()

  const [form, setForm] = useState(VIDE)
  const [erreurs, setErreurs] = useState({})

  const { data: enseignant } = useQuery({
    queryKey: ['enseignants', id],
    queryFn: () => fetchEnseignant(id),
    enabled: enEdition,
  })

  useEffect(() => {
    if (enseignant) {
      setForm({ ...VIDE, ...Object.fromEntries(Object.entries(enseignant).map(([k, v]) => [k, v ?? ''])) })
    }
  }, [enseignant])

  const enregistrer = useMutation({
    mutationFn: () => (enEdition ? updateEnseignant(id, form) : createEnseignant(form)),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['enseignants'] })
      navigate('/enseignants')
    },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))
  const err = (k) => erreurs[k]?.[0]

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

      <form
        onSubmit={(e) => { e.preventDefault(); setErreurs({}); enregistrer.mutate() }}
        className="space-y-8 rounded-xl border border-slate-200 bg-white p-6"
      >
        <FormSection icon={User} title="État civil">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <Input label="Matricule" value={form.matricule} onChange={(e) => champ('matricule', e.target.value)} error={err('matricule')} />
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
              <option value="CELIBATAIRE">Célibataire</option>
              <option value="MARIE">Marié(e)</option>
              <option value="DIVORCE">Divorcé(e)</option>
              <option value="VEUF">Veuf / Veuve</option>
            </Select>
          </div>
        </FormSection>

        <FormSection icon={Phone} title="Coordonnées">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <Input label="Adresse" value={form.adresse} onChange={(e) => champ('adresse', e.target.value)} error={err('adresse')} />
            <Input label="Ville" value={form.ville} onChange={(e) => champ('ville', e.target.value)} error={err('ville')} />
            <Input label="Téléphone" value={form.telephone} onChange={(e) => champ('telephone', e.target.value)} error={err('telephone')} />
            <Input label="Cellulaire" value={form.cellulaire} onChange={(e) => champ('cellulaire', e.target.value)} error={err('cellulaire')} />
            <Input label="Email" value={form.email} onChange={(e) => champ('email', e.target.value)} error={err('email')} />
          </div>
        </FormSection>

        <FormSection icon={GraduationCap} title="Carrière" description="Le salaire reste géré dans ECONOMAT et n’est pas modifiable ici.">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <Select label="Statut" value={form.statut} onChange={(e) => champ('statut', e.target.value)} error={err('statut')}>
              <option value="">—</option>
              {STATUTS.map((s) => <option key={s} value={s}>{s.charAt(0) + s.slice(1).toLowerCase()}</option>)}
            </Select>
            <Input label="Grade" value={form.grade} onChange={(e) => champ('grade', e.target.value)} error={err('grade')} />
            <Input label="Diplôme / titre" value={form.diplome} onChange={(e) => champ('diplome', e.target.value)} error={err('diplome')} />
            <Input label="Matière enseignée" value={form.matiere} onChange={(e) => champ('matiere', e.target.value)} error={err('matiere')} />
            <Input label="Date d’embauche" value={form.date_embauche} onChange={(e) => champ('date_embauche', e.target.value)}
                   error={err('date_embauche')} placeholder="jj/mm/aaaa" />
            <Input label="Année scolaire" value={form.annee_code} onChange={(e) => champ('annee_code', e.target.value)} error={err('annee_code')} />
          </div>
        </FormSection>

        {enEdition && (
          <FormSection icon={LogOut} title="Départ" description="Un enseignant n’est jamais supprimé : renseignez son départ.">
            <div className="grid grid-cols-2 gap-3">
              <Input label="Date de départ" value={form.date_depart} onChange={(e) => champ('date_depart', e.target.value)}
                     error={err('date_depart')} placeholder="jj/mm/aaaa" />
              <Input label="Établissement d’accueil" value={form.etab_accueil} onChange={(e) => champ('etab_accueil', e.target.value)} error={err('etab_accueil')} />
            </div>
            <Textarea label="Motif" rows={2} value={form.motif_depart} onChange={(e) => champ('motif_depart', e.target.value)} error={err('motif_depart')} />
          </FormSection>
        )}

        {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}

        <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
          <Button type="button" variant="outline" onClick={() => navigate('/enseignants')}>Annuler</Button>
          <Button type="submit" disabled={enregistrer.isPending}>
            {enregistrer.isPending ? 'Enregistrement…' : enEdition ? 'Mettre à jour' : 'Enregistrer'}
          </Button>
        </div>
      </form>
    </div>
  )
}
