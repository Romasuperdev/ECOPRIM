import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ChevronRight, Star } from 'lucide-react'
import { fetchMesClasses } from './portailEnseignantApi'

/** Accueil du portail Enseignant : mes classes, pour l'année en cours. */
export default function EnseignantAccueilPage() {
  const { data, isLoading } = useQuery({ queryKey: ['mes-classes'], queryFn: fetchMesClasses })

  const classes = data?.classes ?? []

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-heading">Mes classes</h1>
        <p className="mt-1 text-sm text-muted">
          Année {data?.annee ?? 'en cours'} — cahier de textes, absences et emploi du temps de
          vos classes.
        </p>
      </div>

      {isLoading && <p className="text-muted">Chargement…</p>}

      {!isLoading && classes.length === 0 && (
        <p className="card px-4 py-10 text-center text-sm text-muted">
          Aucune classe ne vous est encore affectée. Rapprochez-vous de l'administration.
        </p>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        {classes.map((c) => (
          <Link
            key={`${c.classe}-${c.matiere}`}
            to={`/mon-espace/classes/${c.classe}`}
            className="card flex items-center justify-between px-4 py-4 transition hover:shadow-md"
          >
            <div>
              <div className="flex items-center gap-1.5 font-semibold text-heading">
                {c.classe_libelle ?? c.classe}
                {c.principale && <Star size={14} className="text-amber-500" fill="currentColor" />}
              </div>
              <div className="text-sm text-muted">{c.matiere_libelle ?? c.matiere}</div>
            </div>
            <ChevronRight size={18} className="text-muted" />
          </Link>
        ))}
      </div>
    </div>
  )
}
