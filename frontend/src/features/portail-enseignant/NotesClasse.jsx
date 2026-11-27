import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Plus, X } from 'lucide-react'
import { apiEvaluationsNotees, fetchMesMatieres } from './portailEnseignantApi'

/*
 * Onglet Notes de la classe : les évaluations récentes (brouillon ou publiée, moyenne de
 * la classe sur 20) et le bouton « + » pour en créer une. La saisie se fait sur sa propre
 * page, au pavé numérique (SaisieEvaluationPage).
 */
const SESSIONS = ['S1', 'S2', 'S3']

function aujourdhui() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function dateCourte(iso) {
  if (!iso) return ''
  const d = new Date(`${iso}T12:00:00`)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
}

const virgule = (n) => (n === null || n === undefined ? '—' : String(n).replace('.', ','))

export function PastilleStatutEvaluation({ statut }) {
  const brouillon = statut === 'brouillon'
  return (
    <span
      className="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-extrabold"
      style={brouillon
        ? { background: 'var(--warning-bg)', color: 'var(--warning-text)' }
        : { background: 'var(--success-bg)', color: 'var(--success-text)' }}
    >
      {brouillon ? 'Brouillon' : 'Publiée'}
    </span>
  )
}

export default function NotesClasse({ classe }) {
  const navigate = useNavigate()
  const [creation, setCreation] = useState(false)
  const { data, isLoading } = useQuery({
    queryKey: ['evaluations-notees', classe],
    queryFn: () => apiEvaluationsNotees.lister(classe),
  })
  const evaluations = data?.evaluations ?? []
  const ouvrir = (entete) => navigate(`/mon-espace/notes/${entete}`)

  return (
    <div>
      {creation ? (
        <NouvelleEvaluation
          classe={classe}
          types={data?.types ?? ['Devoir', 'Interrogation', 'Composition', 'Examen']}
          sessionProposee={evaluations[0]?.session ?? 'S1'}
          onFermer={() => setCreation(false)}
          onCree={(feuille) => ouvrir(feuille.entete)}
        />
      ) : (
        <button
          type="button" onClick={() => setCreation(true)}
          className="mb-4 flex w-full items-center justify-center gap-2 rounded-2xl px-4 py-3.5 text-base font-extrabold shadow-md transition active:scale-[.99]"
          style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}
        >
          <Plus size={20} /> Nouvelle évaluation
        </button>
      )}

      {isLoading && <p className="text-muted">Chargement…</p>}
      {!isLoading && evaluations.length === 0 && (
        <p className="card px-4 py-10 text-center text-sm text-muted">Aucune évaluation pour cette classe.</p>
      )}

      <ul className="space-y-2">
        {evaluations.map((e) => (
          <li key={e.entete}>
            <button
              type="button" onClick={() => ouvrir(e.entete)}
              className="card flex w-full items-center gap-3 p-4 text-left transition active:scale-[.99]"
            >
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <span className="truncate font-bold text-heading">{e.titre}</span>
                  <PastilleStatutEvaluation statut={e.statut} />
                </div>
                <p className="text-sm text-muted">
                  {e.matiere_libelle} · {dateCourte(e.date)} · sur {virgule(e.bareme)}
                  {e.session ? ` · ${e.session}` : ''}
                </p>
              </div>
              <div className="shrink-0 text-right">
                <p className="text-lg font-extrabold text-heading">
                  {e.moyenne_sur_20 !== null ? `${virgule(e.moyenne_sur_20)}` : '—'}
                  <span className="text-xs font-bold text-muted">/20</span>
                </p>
                <p className="text-xs text-muted">{e.notes} note{e.notes > 1 ? 's' : ''}</p>
              </div>
              <ChevronRight size={18} className="shrink-0 text-muted" />
            </button>
          </li>
        ))}
      </ul>
    </div>
  )
}

function NouvelleEvaluation({ classe, types, sessionProposee, onFermer, onCree }) {
  const { data: matieres } = useQuery({ queryKey: ['mes-matieres', classe], queryFn: () => fetchMesMatieres(classe) })
  const [v, setV] = useState({
    matiere: '', titre: '', type: types[0] ?? 'Devoir', date: aujourdhui(), session: sessionProposee, bareme: '',
  })
  const champ = (cle) => (e) => setV((p) => ({ ...p, [cle]: e.target.value }))
  const matiere = v.matiere || matieres?.[0]?.code || ''

  const creer = useMutation({
    mutationFn: () => apiEvaluationsNotees.creer({
      classe, ...v, matiere, titre: v.titre.trim(), bareme: v.bareme === '' ? null : Number(String(v.bareme).replace(',', '.')),
    }),
    onSuccess: onCree,
  })
  const erreurs = creer.error?.response?.data?.errors
  const message = erreurs ? Object.values(erreurs).flat()[0] : creer.error?.response?.data?.message

  return (
    <form
      className="card mb-4 space-y-3 p-4"
      onSubmit={(e) => { e.preventDefault(); creer.mutate() }}
    >
      <div className="flex items-center justify-between">
        <h3 className="font-bold text-heading">Nouvelle évaluation</h3>
        <button type="button" onClick={onFermer} aria-label="Fermer" className="p-1 text-muted"><X size={18} /></button>
      </div>
      <input className="field" placeholder="Titre (ex. Dictée n°3, Calcul mental)" value={v.titre} onChange={champ('titre')} maxLength={150} required />
      <div className="grid grid-cols-2 gap-2">
        <select className="field col-span-2" value={matiere} onChange={champ('matiere')} aria-label="Matière" required>
          {(matieres ?? []).map((m) => <option key={m.code} value={m.code}>{m.libelle}</option>)}
        </select>
        <select className="field" value={v.type} onChange={champ('type')} aria-label="Type">
          {types.map((t) => <option key={t} value={t}>{t}</option>)}
        </select>
        <select className="field" value={v.session} onChange={champ('session')} aria-label="Session">
          {SESSIONS.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <input type="date" className="field" value={v.date} onChange={champ('date')} aria-label="Date" required />
        <input
          className="field" inputMode="decimal" placeholder="Noté sur (barème de l’école)"
          value={v.bareme} onChange={champ('bareme')} aria-label="Noté sur"
        />
      </div>
      {message && <p className="text-sm" style={{ color: 'var(--danger-text)' }}>{message}</p>}
      <button
        type="submit" disabled={creer.isPending || !v.titre.trim() || !matiere}
        className="w-full rounded-xl px-4 py-3 text-sm font-extrabold disabled:opacity-50"
        style={{ background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}
      >
        {creer.isPending ? 'Création…' : 'Créer et saisir les notes'}
      </button>
    </form>
  )
}
