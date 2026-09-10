import { useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Plus, Trash2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import Input from '../../components/ui/Input'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import MessageRefus from '../../components/ui/MessageRefus'
import {
  creerAbsence,
  creerCahier,
  enregistrerLigneCahier,
  fetchCahierReferentiels,
  fetchCahiers,
  fetchMesAbsences,
  fetchMesEleves,
  fetchMonEmploi,
  fetchMonEmploiReferentiels,
  modifierAbsence,
  modifierCahier,
  supprimerAbsence,
  supprimerCahier,
  supprimerLigneCahier,
} from './portailEnseignantApi'

const JOURS_CAHIER = [
  { cle: 'lundi', libelle: 'Lundi' }, { cle: 'mardi', libelle: 'Mardi' },
  { cle: 'mercredi', libelle: 'Mercredi' }, { cle: 'jeudi', libelle: 'Jeudi' },
  { cle: 'vendredi', libelle: 'Vendredi' },
]

const TABS = [
  { cle: 'cahier', libelle: 'Cahier de textes' },
  { cle: 'absences', libelle: 'Absences' },
  { cle: 'eleves', libelle: 'Élèves' },
  { cle: 'emploi', libelle: 'Emploi du temps' },
]

export default function EnseignantClassePage() {
  const { classe } = useParams()
  const [onglet, setOnglet] = useState('cahier')

  return (
    <div>
      <Link to="/mon-espace" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-heading">
        <ArrowLeft size={15} /> Mes classes
      </Link>
      <h1 className="mb-4 text-2xl font-bold text-heading">{classe}</h1>

      <div className="mb-6 flex gap-1 border-b border-[var(--border)]">
        {TABS.map((t) => (
          <button
            key={t.cle}
            type="button"
            onClick={() => setOnglet(t.cle)}
            className={`px-3 py-2 text-sm font-medium ${
              onglet === t.cle
                ? 'border-b-2 border-[var(--brand-accent)] text-heading'
                : 'text-muted hover:text-heading'
            }`}
          >
            {t.libelle}
          </button>
        ))}
      </div>

      {onglet === 'cahier' && <OngletCahier classe={classe} />}
      {onglet === 'absences' && <OngletAbsences classe={classe} />}
      {onglet === 'eleves' && <OngletEleves classe={classe} />}
      {onglet === 'emploi' && <OngletEmploi classe={classe} />}
    </div>
  )
}

// --- Cahier de textes ---

function LigneCahier({ entete, ligne, onSaved, onRemoved }) {
  const initial = { lundi: ligne.lundi, mardi: ligne.mardi, mercredi: ligne.mercredi, jeudi: ligne.jeudi, vendredi: ligne.vendredi }
  const [valeurs, setValeurs] = useState(initial)
  const [erreur, setErreur] = useState(null)
  const dirty = JOURS_CAHIER.some((j) => (valeurs[j.cle] ?? '') !== (ligne[j.cle] ?? ''))

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
      <td className="whitespace-nowrap px-3 py-2 font-medium text-heading">{ligne.matiere_libelle}</td>
      {JOURS_CAHIER.map((j) => (
        <td key={j.cle} className="px-1.5 py-2">
          <input
            type="text" maxLength={50} value={valeurs[j.cle] ?? ''}
            onChange={(e) => setValeurs((v) => ({ ...v, [j.cle]: e.target.value }))}
            placeholder="—" className="field w-full !py-1.5 text-sm"
          />
        </td>
      ))}
      <td className="whitespace-nowrap px-2 py-2 text-right">
        <div className="flex items-center justify-end gap-1.5">
          {erreur && <span className="text-xs text-red-600">{erreur}</span>}
          <Button variant="outline" className="!px-2 !py-1 text-xs" disabled={!dirty || enregistrer.isPending}
                  onClick={() => enregistrer.mutate()}>
            {enregistrer.isPending ? '…' : 'Enregistrer'}
          </Button>
          {ligne.id && (
            <button type="button" title="Retirer cette matière" onClick={() => retirer.mutate()}
                    className="text-muted hover:text-red-500">
              <Trash2 size={15} />
            </button>
          )}
        </div>
      </td>
    </tr>
  )
}

function OngletCahier({ classe }) {
  const qc = useQueryClient()
  const [enteteId, setEnteteId] = useState(null)
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})

  const { data: ref } = useQuery({ queryKey: ['portail-cahier-ref', classe], queryFn: () => fetchCahierReferentiels(classe) })
  const { data, isLoading } = useQuery({ queryKey: ['portail-cahier', classe], queryFn: () => fetchCahiers(classe) })

  const entetes = data?.entetes ?? []
  const entete = entetes.find((e) => e.id === enteteId) ?? entetes[0] ?? null

  const rafraichir = () => qc.invalidateQueries({ queryKey: ['portail-cahier', classe] })
  const fermer = () => { setForm(null); setErreurs({}) }

  const creer = useMutation({
    mutationFn: (v) => creerCahier({ classe, mois: v.mois, semaine: v.semaine, prof: v.prof || null }),
    onSuccess: (nouveau) => { rafraichir(); setEnteteId(nouveau.id); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? {}),
  })
  const modifier = useMutation({
    mutationFn: (v) => modifierCahier(v.id, { mois: v.mois, semaine: v.semaine, prof: v.prof || null }),
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? {}),
  })
  const supprimer = useMutation({
    mutationFn: (id) => supprimerCahier(id),
    onSuccess: () => { setEnteteId(null); rafraichir() },
  })

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-end gap-3">
        {entetes.length > 0 && (
          <div className="min-w-[220px]">
            <Select label="Semaine" value={entete?.id ?? ''} onChange={(e) => setEnteteId(Number(e.target.value))}>
              {entetes.map((e) => (
                <option key={e.id} value={e.id}>{e.mois} — semaine {e.semaine}</option>
              ))}
            </Select>
          </div>
        )}
        <Button variant="outline" onClick={() => { setErreurs({}); setForm({ mois: '', semaine: '', prof: '' }) }}>
          <Plus size={16} className="mr-1.5 inline" /> Nouvelle semaine
        </Button>
      </div>

      {isLoading && <p className="text-muted">Chargement…</p>}

      {!isLoading && entetes.length === 0 && (
        <p className="card px-4 py-10 text-center text-sm text-muted">
          Aucune semaine enregistrée. Commencez par « Nouvelle semaine ».
        </p>
      )}

      {entete && (
        <div className="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--surface)]">
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="text-muted">
                <th className="px-3 py-2 text-left font-medium">Matière</th>
                {JOURS_CAHIER.map((j) => <th key={j.cle} className="px-1.5 py-2 text-left font-medium">{j.libelle}</th>)}
                <th className="px-2 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y" style={{ borderColor: 'var(--border)' }}>
              {entete.lignes.map((ligne) => (
                <LigneCahier
                  key={ligne.matiere ?? `x-${ligne.id}`}
                  entete={entete} ligne={ligne}
                  onSaved={rafraichir} onRemoved={rafraichir}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}

      {entete && (
        <div className="mt-3 text-right">
          <button type="button" onClick={() => supprimer.mutate(entete.id)} className="text-xs text-muted hover:text-red-500">
            Supprimer cette semaine
          </button>
        </div>
      )}

      {form && (
        <ModaleFormulaire
          titre="Nouvelle semaine" onFermer={fermer}
          onValider={() => (form.id ? modifier.mutate(form) : creer.mutate(form))}
          enCours={creer.isPending || modifier.isPending}
          valideDesactive={!form.mois || !form.semaine}
        >
          <Select label="Mois *" value={form.mois} error={erreurs?.mois?.[0]}
                  onChange={(e) => setForm((f) => ({ ...f, mois: e.target.value }))}>
            <option value="">— Choisir —</option>
            {ref?.mois?.map((m) => <option key={m} value={m}>{m}</option>)}
          </Select>
          <Input label="Semaine *" value={form.semaine} error={erreurs?.semaine?.[0]}
                 onChange={(e) => setForm((f) => ({ ...f, semaine: e.target.value }))} />
        </ModaleFormulaire>
      )}
    </div>
  )
}

// --- Absences ---

const aujourdhui = () => new Date().toISOString().slice(0, 10)

function OngletAbsences({ classe }) {
  const qc = useQueryClient()
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data: eleves } = useQuery({ queryKey: ['portail-eleves', classe], queryFn: () => fetchMesEleves(classe) })
  const { data: absences, isLoading } = useQuery({ queryKey: ['portail-absences', classe], queryFn: () => fetchMesAbsences(classe) })

  const rafraichir = () => qc.invalidateQueries({ queryKey: ['portail-absences', classe] })
  const fermer = () => { setForm(null); setErreurs({}) }

  const enregistrer = useMutation({
    mutationFn: (v) => (v.id
      ? modifierAbsence(v.id, { matricule: v.matricule, date: v.date, motif: v.motif, justifiee: v.justifiee })
      : creerAbsence({ matricule: v.matricule, date: v.date, motif: v.motif, justifiee: v.justifiee })),
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })
  const supprimer = useMutation({
    mutationFn: supprimerAbsence,
    onSuccess: () => { rafraichir(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Retrait impossible.'),
  })

  return (
    <div>
      <div className="mb-4 flex justify-end">
        <Button onClick={() => { setErreurs({}); setForm({ matricule: '', date: aujourdhui(), motif: '', justifiee: false }) }}>
          + Saisir une absence
        </Button>
      </div>

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      <div className="overflow-hidden rounded-xl border border-[var(--border)] bg-[var(--surface)]">
        <table className="w-full text-left text-sm">
          <thead className="border-b" style={{ borderColor: 'var(--border)' }}>
            <tr className="text-muted">
              <th className="px-4 py-3 font-medium">Élève</th>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Motif</th>
              <th className="px-4 py-3 font-medium">Justifiée</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y" style={{ borderColor: 'var(--border)' }}>
            {isLoading && <tr><td colSpan={5} className="px-4 py-6 text-center text-muted">Chargement…</td></tr>}
            {!isLoading && (absences?.length ?? 0) === 0 && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-muted">Aucune absence.</td></tr>
            )}
            {absences?.map((a) => (
              <tr key={a.id}>
                <td className="px-4 py-3 font-medium text-heading">
                  {a.eleve ? `${a.eleve.prenom ?? ''} ${a.eleve.nom ?? ''}`.trim() : a.matricule}
                </td>
                <td className="px-4 py-3 text-muted">{a.date ?? '—'}</td>
                <td className="px-4 py-3 text-muted">{a.motif ?? '—'}</td>
                <td className="px-4 py-3 text-muted">{a.justifiee ? 'Oui' : 'Non'}</td>
                <td className="px-4 py-3 text-right">
                  <Button variant="outline" className="!px-3 !py-1 mr-2"
                          onClick={() => { setErreurs({}); setForm({ id: a.id, matricule: a.matricule, date: a.date ?? aujourdhui(), motif: a.motif ?? '', justifiee: Boolean(a.justifiee) }) }}>
                    Éditer
                  </Button>
                  <Button variant="outline" className="!px-3 !py-1" onClick={() => supprimer.mutate(a.id)}>
                    <Trash2 size={14} />
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {form && (
        <ModaleFormulaire
          titre={form.id ? 'Corriger l’absence' : 'Saisir une absence'} onFermer={fermer}
          onValider={() => enregistrer.mutate(form)} enCours={enregistrer.isPending}
          valideDesactive={!form.matricule || !form.date}
        >
          <Select label="Élève" value={form.matricule} error={erreurs.matricule?.[0]}
                  onChange={(e) => setForm((f) => ({ ...f, matricule: e.target.value }))}>
            <option value="">— Choisir —</option>
            {eleves?.map((e) => (
              <option key={e.matricule} value={e.matricule}>{`${e.prenom ?? ''} ${e.nom ?? ''}`.trim()}</option>
            ))}
          </Select>
          <Input label="Date" type="date" max={aujourdhui()} value={form.date} error={erreurs.date?.[0]}
                 onChange={(e) => setForm((f) => ({ ...f, date: e.target.value }))} />
          <Input label="Motif" value={form.motif} error={erreurs.motif?.[0]}
                 onChange={(e) => setForm((f) => ({ ...f, motif: e.target.value }))} />
          <label className="flex items-center gap-2 text-sm text-heading">
            <input type="checkbox" checked={Boolean(form.justifiee)}
                   onChange={(e) => setForm((f) => ({ ...f, justifiee: e.target.checked }))} />
            Absence justifiée
          </label>
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}
    </div>
  )
}

// --- Élèves ---

function OngletEleves({ classe }) {
  const { data: eleves, isLoading } = useQuery({ queryKey: ['portail-eleves', classe], queryFn: () => fetchMesEleves(classe) })

  return (
    <div className="overflow-hidden rounded-xl border border-[var(--border)] bg-[var(--surface)]">
      <table className="w-full text-left text-sm">
        <thead className="border-b" style={{ borderColor: 'var(--border)' }}>
          <tr className="text-muted">
            <th className="px-4 py-3 font-medium">Nom</th>
            <th className="px-4 py-3 font-medium">Matricule</th>
          </tr>
        </thead>
        <tbody className="divide-y" style={{ borderColor: 'var(--border)' }}>
          {isLoading && <tr><td colSpan={2} className="px-4 py-6 text-center text-muted">Chargement…</td></tr>}
          {!isLoading && (eleves?.length ?? 0) === 0 && (
            <tr><td colSpan={2} className="px-4 py-6 text-center text-muted">Aucun élève.</td></tr>
          )}
          {eleves?.map((e) => (
            <tr key={e.matricule}>
              <td className="px-4 py-3 font-medium text-heading">{`${e.prenom ?? ''} ${e.nom ?? ''}`.trim()}</td>
              <td className="px-4 py-3 text-muted">{e.matricule}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

// --- Emploi du temps (lecture seule) ---

function OngletEmploi({ classe }) {
  const { data: ref } = useQuery({ queryKey: ['portail-emploi-ref'], queryFn: fetchMonEmploiReferentiels })
  const { data, isLoading } = useQuery({ queryKey: ['portail-emploi', classe], queryFn: () => fetchMonEmploi(classe) })

  const jours = ref?.jours ?? []
  const heures = ref?.heures ?? []
  const creneauDe = (jour, heure) => data?.creneaux?.find((c) => c.jour === jour && c.heure === heure)

  if (isLoading) return <p className="text-muted">Chargement…</p>
  if (jours.length === 0 || heures.length === 0) {
    return <p className="card px-4 py-10 text-center text-sm text-muted">Aucun emploi du temps disponible.</p>
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--surface)]">
      <table className="w-full border-collapse text-sm">
        <thead>
          <tr className="text-muted">
            <th className="border-b border-r px-3 py-2 text-left font-medium" style={{ borderColor: 'var(--border)' }}>Horaire</th>
            {jours.map((j) => <th key={j.code} className="border-b px-3 py-2 text-left font-medium" style={{ borderColor: 'var(--border)' }}>{j.libelle}</th>)}
          </tr>
        </thead>
        <tbody>
          {heures.map((h) => (
            <tr key={h.code}>
              <th className="whitespace-nowrap border-b border-r px-3 py-2 text-left text-xs font-medium text-muted" style={{ borderColor: 'var(--border)' }}>
                {h.libelle || h.code}
              </th>
              {jours.map((j) => {
                const c = creneauDe(j.code, h.code)
                return (
                  <td key={j.code} className="border-b p-1.5 align-top" style={{ borderColor: 'var(--border)' }}>
                    {c && (
                      <div className="rounded-lg bg-[var(--brand-accent)]/10 px-2 py-1.5">
                        <div className="text-xs font-semibold text-heading">{c.matiere_libelle}</div>
                        {c.salle_libelle && <div className="text-[11px] text-muted">{c.salle_libelle}</div>}
                      </div>
                    )}
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
