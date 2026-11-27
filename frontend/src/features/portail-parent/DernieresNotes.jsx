import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchNotesEnfant } from './portailParentApi'

/*
 * Espace Parent, onglet Résultats : les notes publiées par les enseignants, évaluation par
 * évaluation (« Dictée n°3 — 15/20 »), les plus récentes d'abord. Une évaluation encore en
 * brouillon chez l'enseignant n'apparaît pas.
 */
const virgule = (n) => String(n).replace('.', ',')

function dateCourte(iso) {
  if (!iso) return ''
  const d = new Date(`${iso}T12:00:00`)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
}

export default function DernieresNotes({ matricule }) {
  const [tout, setTout] = useState(false)
  const { data, isLoading } = useQuery({
    queryKey: ['portail-notes-enfant', matricule],
    queryFn: () => fetchNotesEnfant(matricule),
  })
  const notes = data?.notes ?? []
  const visibles = tout ? notes : notes.slice(0, 6)

  if (isLoading) return null

  return (
    <section>
      <h2 className="mb-3 text-base font-bold text-heading">Dernières notes</h2>
      {notes.length === 0 && <p className="card px-4 py-6 text-center text-sm text-muted">Aucune note publiée pour le moment.</p>}
      <ul className="space-y-2">
        {visibles.map((n) => {
          const sur20 = n.note !== null && n.bareme ? (n.note / n.bareme) * 20 : null
          return (
            <li key={n.entete} className="card flex items-center gap-3 px-4 py-3">
              <div className="min-w-0 flex-1">
                <p className="truncate font-bold text-heading">{n.titre}</p>
                <p className="text-sm text-muted">{n.matiere}{n.date ? ` · ${dateCourte(n.date)}` : ''}</p>
              </div>
              {n.absent ? (
                <span className="rounded-full px-3 py-1 text-sm font-extrabold" style={{ background: 'var(--warning-bg)', color: 'var(--warning-text)' }}>
                  Absent
                </span>
              ) : (
                <span
                  className="rounded-full px-3 py-1 text-base font-extrabold"
                  style={sur20 === null
                    ? { background: 'var(--surface-2)', color: 'var(--muted-text)' }
                    : sur20 >= 10
                      ? { background: 'var(--success-bg)', color: 'var(--success-text)' }
                      : { background: 'var(--danger-bg)', color: 'var(--danger-text)' }}
                >
                  {n.note !== null ? virgule(n.note) : '—'}<span className="text-xs font-bold">/{virgule(n.bareme)}</span>
                </span>
              )}
            </li>
          )
        })}
      </ul>
      {notes.length > 6 && (
        <button type="button" onClick={() => setTout(!tout)} className="mt-2 text-sm font-bold" style={{ color: 'var(--brand-accent)' }}>
          {tout ? 'Voir moins' : `Voir les ${notes.length} notes`}
        </button>
      )}
    </section>
  )
}
