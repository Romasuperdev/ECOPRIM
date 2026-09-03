import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Trash2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import MessageRefus from '../../components/ui/MessageRefus'
import {
  createAnneeScolaire,
  deleteAnneeScolaire,
  fetchAnneesScolairesList,
  updateAnneeScolaire,
} from './anneesScolairesApi'

const VIDE = { code: '', libelle: '', date_debut: '', date_fin: '', active: false, cloturee: false }

/**
 * Années scolaires — ECONOMAT.T_ANNEEACADEMIQUE.
 *
 * La fiche d'une année reste modifiable même clôturée : le verrou de clôture protège les
 * données DANS l'année, pas sa définition. On peut donc rouvrir une année clôturée par
 * erreur, ce qui serait impossible autrement.
 */
export default function AnneeScolaireListPage() {
  const qc = useQueryClient()
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data: annees, isLoading } = useQuery({
    queryKey: ['annees-scolaires'],
    queryFn: fetchAnneesScolairesList,
  })

  // L'année de travail dépend de ce référentiel : on invalide tout le cache, comme au
  // changement d'année dans l'en-tête.
  const rafraichir = () => qc.invalidateQueries()
  const fermer = () => { setForm(null); setErreurs({}) }

  const enregistrer = useMutation({
    mutationFn: (v) => (v.id ? updateAnneeScolaire(v.id, v) : createAnneeScolaire(v)),
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const supprimer = useMutation({
    mutationFn: deleteAnneeScolaire,
    onSuccess: () => { rafraichir(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Suppression impossible.'),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Années scolaires</h1>
          <p className="mt-1 text-sm text-slate-500">Source : ECONOMAT.T_ANNEEACADEMIQUE.</p>
        </div>
        <Button onClick={() => { setErreurs({}); setForm({ ...VIDE }) }}>+ Nouvelle année</Button>
      </div>

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Libellé</th>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Début</th>
              <th className="px-4 py-3 font-medium">Fin</th>
              <th className="px-4 py-3 font-medium">Statut</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && annees?.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Aucune année scolaire.</td></tr>
            )}
            {annees?.map((annee) => (
              <tr key={annee.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{annee.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{annee.code_annee ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{annee.date_debut ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{annee.date_fin ?? '—'}</td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-1">
                    {annee.active && (
                      <span className="rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Active</span>
                    )}
                    {annee.cloturee && (
                      <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">Clôturée</span>
                    )}
                    {annee.cloture_partielle && !annee.cloturee && (
                      <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Clôture partielle</span>
                    )}
                  </div>
                </td>
                <td className="px-4 py-3 text-right">
                  <Button
                    variant="outline"
                    className="!px-3 !py-1 mr-2"
                    onClick={() => {
                      setErreurs({})
                      setForm({
                        id: annee.id,
                        code: annee.code_annee ?? '',
                        libelle: annee.libelle ?? '',
                        date_debut: annee.date_debut ?? '',
                        date_fin: annee.date_fin ?? '',
                        active: Boolean(annee.active),
                        cloturee: Boolean(annee.cloturee),
                      })
                    }}
                  >
                    Éditer
                  </Button>
                  <Button
                    variant="outline"
                    className="!px-3 !py-1"
                    title="Supprimer — refusé si des données sont rattachées à cette année"
                    onClick={() => supprimer.mutate(annee.id)}
                  >
                    <Trash2 size={14} />
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p className="mt-3 text-xs text-slate-400">
        Une année ne peut être supprimée que si rien ne s’y rattache (élèves, classes,
        niveaux, emplois du temps, affectations, documents). Une année clôturée reste
        modifiable, pour pouvoir être rouverte.
      </p>

      {form && (
        <ModaleFormulaire
          titre={form.id ? `Modifier ${form.libelle}` : 'Nouvelle année scolaire'}
          onFermer={fermer}
          onValider={() => enregistrer.mutate(form)}
          enCours={enregistrer.isPending}
          valideDesactive={!form.libelle || !form.code}
        >
          <Input label="Libellé" placeholder="2026-2027" value={form.libelle}
                 error={erreurs.libelle?.[0]} onChange={(e) => champ('libelle', e.target.value)} />
          <Input label="Code" placeholder="2026" value={form.code}
                 error={erreurs.code?.[0]} onChange={(e) => champ('code', e.target.value)} />
          <div className="grid grid-cols-2 gap-3">
            <Input label="Début" type="date" value={form.date_debut}
                   error={erreurs.date_debut?.[0]} onChange={(e) => champ('date_debut', e.target.value)} />
            <Input label="Fin" type="date" value={form.date_fin}
                   error={erreurs.date_fin?.[0]} onChange={(e) => champ('date_fin', e.target.value)} />
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" checked={form.active} onChange={(e) => champ('active', e.target.checked)} />
            Année active
            <span className="text-xs text-slate-400">(désactive les autres)</span>
          </label>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" checked={form.cloturee} onChange={(e) => champ('cloturee', e.target.checked)} />
            Clôturée
            <span className="text-xs text-slate-400">(consultation seule)</span>
          </label>
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}
    </div>
  )
}
