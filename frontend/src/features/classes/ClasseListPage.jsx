import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Trash2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import MessageRefus from '../../components/ui/MessageRefus'
import { fetchContexte } from '../contexte/contexteApi'
import { fetchNiveaux } from '../reference/referenceApi'
import { createClasse, deleteClasse, fetchClasses, updateClasse } from './classesApi'

const VIDE = { code: '', nom: '', niveau_code: '', serie: '' }

/**
 * Classes — ECONOMAT.T_CLASSE.
 *
 * Une classe appartient à l'année de travail : elle y est créée sans qu'on saisisse
 * l'année, et une année clôturée passe la page en consultation seule. La suppression est
 * refusée dès qu'un élève, un créneau, une affectation, une absence ou une note s'y
 * rattache — sans quoi ces dossiers pointeraient vers un code inexistant.
 *
 * L'API adresse une classe par son CODE (et non par un identifiant numérique) : c'est la
 * clé de T_CLASSE côté ECONOMAT.
 */
export default function ClasseListPage() {
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data, isLoading } = useQuery({ queryKey: ['classes', page], queryFn: () => fetchClasses(page) })
  const { data: niveaux } = useQuery({ queryKey: ['niveaux'], queryFn: fetchNiveaux })
  const { data: contexte } = useQuery({ queryKey: ['contexte'], queryFn: fetchContexte, retry: false })

  const anneeCourante = contexte?.annees?.find((a) => a.libelle === contexte?.annee)
  const verrouille = Boolean(anneeCourante?.cloturee)

  const rafraichir = () => qc.invalidateQueries({ queryKey: ['classes'] })
  const fermer = () => { setForm(null); setErreurs({}) }

  const enregistrer = useMutation({
    mutationFn: (v) => (v.code_initial ? updateClasse(v.code_initial, v) : createClasse(v)),
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const supprimer = useMutation({
    mutationFn: deleteClasse,
    onSuccess: () => { rafraichir(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Suppression impossible.'),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Classes</h1>
          <p className="mt-1 text-sm text-slate-500">
            Classes de l’année {contexte?.annee ?? 'en cours'} (source : ECONOMAT.T_CLASSE).
          </p>
        </div>
        <Button
          disabled={verrouille}
          title={verrouille ? 'Année clôturée : consultation seule.' : undefined}
          onClick={() => { setErreurs({}); setForm({ ...VIDE }) }}
        >
          + Nouvelle classe
        </Button>
      </div>

      {verrouille && (
        <div className="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <Lock size={16} className="mt-0.5 shrink-0" />
          <span>L’année <strong>{contexte?.annee}</strong> est clôturée : les classes sont en consultation seule.</span>
        </div>
      )}

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Niveau</th>
              <th className="px-4 py-3 font-medium">Année</th>
              <th className="px-4 py-3 font-medium">Série</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Aucune classe.</td></tr>
            )}
            {data?.data?.map((classe) => (
              <tr key={classe.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{classe.code}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{classe.nom}</td>
                <td className="px-4 py-3 text-slate-600">{classe.niveau?.libelle ?? classe.niveau_code ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{classe.annee ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{classe.serie ?? '—'}</td>
                <td className="px-4 py-3 text-right">
                  <Button
                    variant="outline" className="!px-3 !py-1 mr-2" disabled={verrouille}
                    onClick={() => {
                      setErreurs({})
                      setForm({
                        code_initial: classe.code,
                        code: classe.code ?? '',
                        nom: classe.nom ?? '',
                        niveau_code: classe.niveau_code ?? '',
                        serie: classe.serie ?? '',
                      })
                    }}
                  >
                    Éditer
                  </Button>
                  <Button
                    variant="outline" className="!px-3 !py-1" disabled={verrouille}
                    title="Supprimer — refusé si des élèves, créneaux, notes ou affectations s’y rattachent"
                    onClick={() => supprimer.mutate(classe.code)}
                  >
                    <Trash2 size={14} />
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {data && (
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="outline" disabled={!data.prev_page_url} onClick={() => setPage((p) => p - 1)}>Précédent</Button>
          <Button variant="outline" disabled={!data.next_page_url} onClick={() => setPage((p) => p + 1)}>Suivant</Button>
        </div>
      )}

      {form && (
        <ModaleFormulaire
          titre={form.code_initial ? `Modifier ${form.nom}` : 'Nouvelle classe'}
          sousTitre={`Année ${contexte?.annee ?? 'en cours'}`}
          onFermer={fermer}
          onValider={() => enregistrer.mutate(form)}
          enCours={enregistrer.isPending}
          valideDesactive={!form.code || !form.nom || !form.niveau_code}
        >
          <Input label="Code" placeholder="CP1A" value={form.code}
                 error={erreurs.code?.[0]} onChange={(e) => champ('code', e.target.value)} />
          <Input label="Nom" placeholder="CP1 A" value={form.nom}
                 error={erreurs.nom?.[0]} onChange={(e) => champ('nom', e.target.value)} />
          <Select label="Niveau" value={form.niveau_code} error={erreurs.niveau_code?.[0]}
                  onChange={(e) => champ('niveau_code', e.target.value)}>
            <option value="">— Choisir —</option>
            {niveaux?.map((n) => <option key={n.id} value={n.code}>{n.libelle}</option>)}
          </Select>
          <Input label="Série (facultatif)" value={form.serie}
                 error={erreurs.serie?.[0]} onChange={(e) => champ('serie', e.target.value)} />
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}
    </div>
  )
}
