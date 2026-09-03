import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Trash2 } from 'lucide-react'
import Select from '../../components/ui/Select'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import MessageRefus from '../../components/ui/MessageRefus'
import { fetchAllClasses } from '../reference/referenceApi'
import { fetchContexte } from '../contexte/contexteApi'
import {
  createAbsence,
  deleteAbsence,
  fetchAbsences,
  fetchEffectifClasse,
  updateAbsence,
} from './absencesApi'

const aujourdhui = () => new Date().toISOString().slice(0, 10)

/**
 * Absences élèves — ECONOMAT.T_ABSENCEELEVE.
 *
 * La saisie part de l'effectif d'une classe : on choisit l'élève dans la liste plutôt
 * qu'en tapant un matricule, ce qui évite d'inscrire une absence au mauvais dossier. La
 * classe et l'année sont déduites de l'élève et du contexte, jamais saisies.
 */
export default function AbsenceListPage() {
  const qc = useQueryClient()
  const [classeCode, setClasseCode] = useState('')
  const [dateFiltre, setDateFiltre] = useState('')
  const [page, setPage] = useState(1)
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data: contexte } = useQuery({ queryKey: ['contexte'], queryFn: fetchContexte, retry: false })
  const { data, isLoading } = useQuery({
    queryKey: ['absences', classeCode, dateFiltre, page],
    queryFn: () => fetchAbsences({ classe_code: classeCode, date: dateFiltre, page }),
  })

  // L'effectif suit la classe choisie dans le formulaire, pas celle du filtre.
  const classeSaisie = form?.classe ?? ''
  const { data: effectif } = useQuery({
    queryKey: ['effectif-classe', classeSaisie],
    queryFn: () => fetchEffectifClasse(classeSaisie),
    enabled: Boolean(classeSaisie),
  })

  const anneeCourante = contexte?.annees?.find((a) => a.libelle === contexte?.annee)
  const verrouille = Boolean(anneeCourante?.cloturee)

  const rafraichir = () => qc.invalidateQueries({ queryKey: ['absences'] })
  const fermer = () => { setForm(null); setErreurs({}) }

  const enregistrer = useMutation({
    mutationFn: (v) => {
      const charge = { matricule: v.matricule, date: v.date, heure: v.heure, motif: v.motif, justifiee: v.justifiee }
      return v.id ? updateAbsence(v.id, charge) : createAbsence(charge)
    },
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })

  const supprimer = useMutation({
    mutationFn: deleteAbsence,
    onSuccess: () => { rafraichir(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Retrait impossible.'),
  })

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-800">Absences</h1>
          <p className="mt-1 text-sm text-slate-500">
            Année {contexte?.annee ?? 'en cours'} (source : ECONOMAT.T_ABSENCEELEVE).
          </p>
        </div>
        <Button
          disabled={verrouille}
          title={verrouille ? 'Année clôturée : consultation seule.' : undefined}
          onClick={() => {
            setErreurs({})
            setForm({ classe: classeCode, matricule: '', date: aujourdhui(), heure: '', motif: '', justifiee: false })
          }}
        >
          + Saisir une absence
        </Button>
      </div>

      {verrouille && (
        <div className="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <Lock size={16} className="mt-0.5 shrink-0" />
          <span>L’année <strong>{contexte?.annee}</strong> est clôturée : les absences sont en consultation seule.</span>
        </div>
      )}

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      <div className="mb-6 flex flex-wrap items-end gap-3">
        <div className="min-w-[220px]">
          <Select label="Classe" value={classeCode} onChange={(e) => { setClasseCode(e.target.value); setPage(1) }}>
            <option value="">Toutes les classes</option>
            {classes?.map((classe) => (
              <option key={classe.id} value={classe.code}>{classe.nom}</option>
            ))}
          </Select>
        </div>
        <div className="min-w-[170px]">
          <Input label="Jour" type="date" value={dateFiltre}
                 onChange={(e) => { setDateFiltre(e.target.value); setPage(1) }} />
        </div>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Élève</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Heure</th>
              <th className="px-4 py-3 font-medium">Motif</th>
              <th className="px-4 py-3 font-medium">Justifiée</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">Aucune absence.</td></tr>
            )}
            {data?.data?.map((a) => (
              <tr key={a.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">
                  {a.eleve ? `${a.eleve.prenom ?? ''} ${a.eleve.nom ?? ''}`.trim() : a.matricule}
                </td>
                <td className="px-4 py-3 text-slate-600">{a.classe_code ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{a.date ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{a.heure ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{a.motif ?? '—'}</td>
                <td className="px-4 py-3">
                  {a.justifiee
                    ? <span className="rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Oui</span>
                    : <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">Non</span>}
                </td>
                <td className="px-4 py-3 text-right">
                  <Button
                    variant="outline" className="!px-3 !py-1 mr-2" disabled={verrouille}
                    onClick={() => {
                      setErreurs({})
                      setForm({
                        id: a.id,
                        classe: a.classe_code ?? '',
                        matricule: a.matricule ?? '',
                        date: a.date ?? aujourdhui(),
                        heure: a.heure ?? '',
                        motif: a.motif ?? '',
                        justifiee: Boolean(a.justifiee),
                      })
                    }}
                  >
                    Éditer
                  </Button>
                  <Button
                    variant="outline" className="!px-3 !py-1" disabled={verrouille}
                    title="Retirer cette absence"
                    onClick={() => supprimer.mutate(a.id)}
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

      <p className="mt-3 text-xs text-slate-400">
        La classe et l’année sont déduites de l’élève et de l’année de travail : elles ne se
        saisissent pas. Un même élève ne peut avoir qu’une absence par jour et par heure.
      </p>

      {form && (
        <ModaleFormulaire
          titre={form.id ? 'Corriger l’absence' : 'Saisir une absence'}
          sousTitre={`Année ${contexte?.annee ?? 'en cours'}`}
          onFermer={fermer}
          onValider={() => enregistrer.mutate(form)}
          enCours={enregistrer.isPending}
          valideDesactive={!form.matricule || !form.date}
        >
          <Select label="Classe" value={form.classe}
                  onChange={(e) => setForm((f) => ({ ...f, classe: e.target.value, matricule: '' }))}>
            <option value="">— Choisir une classe —</option>
            {classes?.map((c) => <option key={c.id} value={c.code}>{c.nom}</option>)}
          </Select>
          <Select label="Élève" value={form.matricule} error={erreurs.matricule?.[0]}
                  onChange={(e) => champ('matricule', e.target.value)}>
            <option value="">
              {form.classe ? '— Choisir un élève —' : '— Choisissez d’abord une classe —'}
            </option>
            {effectif?.map((e) => (
              <option key={e.id} value={e.matricule}>
                {`${e.prenom ?? ''} ${e.nom ?? ''}`.trim()} ({e.matricule})
              </option>
            ))}
          </Select>
          <div className="grid grid-cols-2 gap-3">
            <Input label="Date" type="date" max={aujourdhui()} value={form.date}
                   error={erreurs.date?.[0]} onChange={(e) => champ('date', e.target.value)} />
            <Input label="Heure" placeholder="08:00" value={form.heure}
                   error={erreurs.heure?.[0]} onChange={(e) => champ('heure', e.target.value)} />
          </div>
          <Input label="Motif" placeholder="Maladie" value={form.motif}
                 error={erreurs.motif?.[0]} onChange={(e) => champ('motif', e.target.value)} />
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" checked={Boolean(form.justifiee)}
                   onChange={(e) => champ('justifiee', e.target.checked)} />
            Absence justifiée
          </label>
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}
    </div>
  )
}
