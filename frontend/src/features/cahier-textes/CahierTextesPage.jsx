import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Plus, Trash2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import Input from '../../components/ui/Input'
import {
  creerCahier,
  enregistrerLigneCahier,
  fetchCahiers,
  fetchClasses,
  fetchReferentielsCahier,
  modifierCahier,
  supprimerCahier,
  supprimerLigneCahier,
} from './cahierTextesApi'

const JOURS = [
  { cle: 'lundi', libelle: 'Lundi' },
  { cle: 'mardi', libelle: 'Mardi' },
  { cle: 'mercredi', libelle: 'Mercredi' },
  { cle: 'jeudi', libelle: 'Jeudi' },
  { cle: 'vendredi', libelle: 'Vendredi' },
]

function LigneCahier({ entete, ligne, verrouille, onSaved, onRemoved }) {
  const initial = { lundi: ligne.lundi, mardi: ligne.mardi, mercredi: ligne.mercredi, jeudi: ligne.jeudi, vendredi: ligne.vendredi }
  const [valeurs, setValeurs] = useState(initial)
  const [erreur, setErreur] = useState(null)

  const dirty = JOURS.some((j) => (valeurs[j.cle] ?? '') !== (ligne[j.cle] ?? ''))

  const enregistrer = useMutation({
    mutationFn: () => enregistrerLigneCahier(entete.id, { matiere: ligne.matiere, ...valeurs }),
    onSuccess: (data) => { setErreur(null); onSaved(data) },
    onError: (e) => setErreur(e?.response?.data?.errors?.matiere?.[0] ?? e?.response?.data?.message ?? 'Erreur'),
  })

  const retirer = useMutation({
    mutationFn: () => supprimerLigneCahier(entete.id, ligne.matiere),
    onSuccess: () => onRemoved(ligne.matiere),
  })

  return (
    <tr className="align-top">
      <td className="whitespace-nowrap px-3 py-2 font-medium text-slate-700">
        {ligne.matiere_libelle}
        {!ligne.id && <span className="ml-1 text-xs text-slate-300">·</span>}
      </td>
      {JOURS.map((j) => (
        <td key={j.cle} className="px-1.5 py-2">
          <input
            type="text"
            maxLength={50}
            disabled={verrouille}
            value={valeurs[j.cle] ?? ''}
            onChange={(e) => setValeurs((v) => ({ ...v, [j.cle]: e.target.value }))}
            placeholder="—"
            className="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm focus:border-primary-400 focus:outline-none disabled:bg-slate-50 disabled:text-slate-400"
          />
        </td>
      ))}
      <td className="whitespace-nowrap px-2 py-2 text-right">
        <div className="flex items-center justify-end gap-1.5">
          {erreur && <span className="text-xs text-red-600">{erreur}</span>}
          <Button
            variant="outline" className="!px-2 !py-1 text-xs"
            disabled={verrouille || !dirty || enregistrer.isPending}
            onClick={() => enregistrer.mutate()}
          >
            {enregistrer.isPending ? '…' : 'Enregistrer'}
          </Button>
          {ligne.id && (
            <button
              type="button" disabled={verrouille}
              title="Retirer cette matière du cahier"
              onClick={() => retirer.mutate()}
              className="text-slate-300 hover:text-red-500 disabled:cursor-default disabled:hover:text-slate-300"
            >
              <Trash2 size={15} />
            </button>
          )}
        </div>
      </td>
    </tr>
  )
}

function FormEntete({ initial, referentiels, erreurs, onSubmit, onClose, enCours }) {
  const [form, setForm] = useState(initial)
  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div className="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <h2 className="mb-4 text-lg font-bold text-slate-800">
          {initial.id ? 'Modifier la semaine' : 'Nouvelle semaine'}
        </h2>
        <form onSubmit={(e) => { e.preventDefault(); onSubmit(form) }} className="space-y-3">
          <Select label="Mois *" value={form.mois} error={erreurs?.mois?.[0]}
                  onChange={(e) => champ('mois', e.target.value)}>
            <option value="">— Choisir —</option>
            {referentiels?.mois?.map((m) => <option key={m} value={m}>{m}</option>)}
          </Select>
          <Input label="Semaine *" value={form.semaine} error={erreurs?.semaine?.[0]}
                 placeholder="Ex. : 1, ou n° de semaine"
                 onChange={(e) => champ('semaine', e.target.value)} />
          <Select label="Enseignant" value={form.prof} error={erreurs?.prof?.[0]}
                  onChange={(e) => champ('prof', e.target.value)}>
            <option value="">— Non renseigné —</option>
            {referentiels?.enseignants?.map((p) => <option key={p.code} value={p.code}>{p.nom}</option>)}
          </Select>
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={onClose}>Annuler</Button>
            <Button type="submit" disabled={enCours || !form.mois || !form.semaine}>
              {enCours ? 'Enregistrement…' : 'Enregistrer'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}

export default function CahierTextesPage() {
  const qc = useQueryClient()
  const [classe, setClasse] = useState('')
  const [enteteId, setEnteteId] = useState(null)
  const [form, setForm] = useState(null) // null | { id?, mois, semaine, prof }
  const [erreurs, setErreurs] = useState({})

  const { data: classes } = useQuery({ queryKey: ['classes-cahier'], queryFn: fetchClasses })
  const { data: ref } = useQuery({
    queryKey: ['cahier-referentiels', classe], queryFn: () => fetchReferentielsCahier(classe), enabled: Boolean(classe),
  })
  const { data, isLoading } = useQuery({
    queryKey: ['cahier-textes', classe], queryFn: () => fetchCahiers(classe), enabled: Boolean(classe),
  })

  const entetes = data?.entetes ?? []
  // Pas de choix explicite (nouvelle classe, ou l'entête choisie a été supprimée) : la plus
  // récente par défaut — dérivé au rendu, pas besoin d'effet pour resynchroniser.
  const entete = entetes.find((e) => e.id === enteteId) ?? entetes[0] ?? null

  const rafraichir = () => qc.invalidateQueries({ queryKey: ['cahier-textes', classe] })
  const fermerForm = () => { setForm(null); setErreurs({}) }

  const creer = useMutation({
    mutationFn: (v) => creerCahier({ classe, mois: v.mois, semaine: v.semaine, prof: v.prof || null }),
    onSuccess: (nouveau) => { rafraichir(); setEnteteId(nouveau.id); fermerForm() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? {}),
  })

  const modifier = useMutation({
    mutationFn: (v) => modifierCahier(v.id, { mois: v.mois, semaine: v.semaine, prof: v.prof || null }),
    onSuccess: () => { rafraichir(); fermerForm() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? {}),
  })

  const supprimer = useMutation({
    mutationFn: (id) => supprimerCahier(id),
    onSuccess: () => { setEnteteId(null); rafraichir() },
  })

  const verrouille = Boolean(entete?.annee_cloturee)

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Cahier de textes</h1>
        <p className="mt-1 text-sm text-slate-500">
          Ce qui a été vu, jour par jour et matière par matière, pour l’année {data?.annee ?? 'en cours'}
          {' '}(source : ECONOMAT.T_ENTETE_JOURNAL / T_CAHIER_JOURNAL).
        </p>
      </div>

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="max-w-sm flex-1">
          <Select label="Classe" value={classe} onChange={(e) => { setClasse(e.target.value); setEnteteId(null) }}>
            <option value="">— Choisir une classe —</option>
            {classes?.map((c) => <option key={c.code} value={c.code}>{c.nom ?? c.code}</option>)}
          </Select>
        </div>
        {classe && entetes.length > 0 && (
          <div className="min-w-[220px]">
            <Select label="Semaine" value={entete?.id ?? ''} onChange={(e) => setEnteteId(Number(e.target.value))}>
              {entetes.map((e) => (
                <option key={e.id} value={e.id}>
                  {e.mois} — semaine {e.semaine} (n° {e.num_sem}){e.annee_cloturee ? ' · clôturée' : ''}
                </option>
              ))}
            </Select>
          </div>
        )}
        <Button
          variant="outline" disabled={!classe}
          onClick={() => { setErreurs({}); setForm({ mois: '', semaine: '', prof: '' }) }}
        >
          <Plus size={16} className="mr-1.5 inline" />
          Nouvelle semaine
        </Button>
      </div>

      {!classe && (
        <p className="rounded-xl border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-400">
          Choisissez une classe pour consulter ou tenir son cahier de textes.
        </p>
      )}

      {classe && isLoading && <p className="text-slate-400">Chargement…</p>}

      {classe && !isLoading && entetes.length === 0 && (
        <p className="rounded-xl border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-400">
          Aucune semaine enregistrée pour cette classe. Commencez par « Nouvelle semaine ».
        </p>
      )}

      {entete && (
        <div>
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3">
            <div className="text-sm text-slate-600">
              <span className="font-semibold text-slate-800">{entete.mois}</span>
              {' · semaine '}{entete.semaine}
              {entete.prof_nom && <span className="ml-2 text-slate-400">— {entete.prof_nom}</span>}
            </div>
            <div className="flex gap-2">
              <Button
                variant="outline" className="!px-3 !py-1 text-xs" disabled={verrouille}
                onClick={() => {
                  setErreurs({})
                  setForm({ id: entete.id, mois: entete.mois, semaine: entete.semaine, prof: entete.prof ?? '' })
                }}
              >
                Modifier
              </Button>
              <button
                type="button" disabled={verrouille}
                title="Supprimer cette semaine et son contenu"
                onClick={() => supprimer.mutate(entete.id)}
                className="text-slate-300 hover:text-red-500 disabled:cursor-default disabled:hover:text-slate-300"
              >
                <Trash2 size={16} />
              </button>
            </div>
          </div>

          {verrouille && (
            <div className="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
              <Lock size={16} className="mt-0.5 shrink-0" />
              <span>L’année <strong>{entete.annee}</strong> est clôturée : ce cahier est en consultation seule.</span>
            </div>
          )}

          <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr className="bg-slate-50 text-slate-500">
                  <th className="px-3 py-2 text-left font-medium">Matière</th>
                  {JOURS.map((j) => (
                    <th key={j.cle} className="px-1.5 py-2 text-left font-medium">{j.libelle}</th>
                  ))}
                  <th className="px-2 py-2" />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {entete.lignes.length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-4 py-6 text-center text-slate-400">
                      Aucune matière affectée à cette classe : voir « Affectation enseignant – classe ».
                    </td>
                  </tr>
                )}
                {entete.lignes.map((ligne) => (
                  <LigneCahier
                    key={ligne.matiere ?? `sans-matiere-${ligne.id}`}
                    entete={entete}
                    ligne={ligne}
                    verrouille={verrouille}
                    onSaved={rafraichir}
                    onRemoved={rafraichir}
                  />
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {form && (
        <FormEntete
          initial={form}
          referentiels={ref}
          erreurs={erreurs}
          enCours={creer.isPending || modifier.isPending}
          onClose={fermerForm}
          onSubmit={(v) => (form.id ? modifier.mutate({ id: form.id, ...v }) : creer.mutate(v))}
        />
      )}
    </div>
  )
}
