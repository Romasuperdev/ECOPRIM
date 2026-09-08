import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ChevronRight } from 'lucide-react'
import { fetchMesEnfants } from './portailParentApi'

/** Accueil du portail Parent : mes enfants rattachés, pour l'année en cours. */
export default function ParentAccueilPage() {
  const { data, isLoading } = useQuery({ queryKey: ['mes-enfants'], queryFn: fetchMesEnfants })

  const enfants = data?.enfants ?? []

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-heading">Mes enfants</h1>
        <p className="mt-1 text-sm text-muted">Année {data?.annee ?? 'en cours'}.</p>
      </div>

      {isLoading && <p className="text-muted">Chargement…</p>}

      {!isLoading && enfants.length === 0 && (
        <p className="card px-4 py-10 text-center text-sm text-muted">
          Aucun enfant ne vous est encore rattaché. Rapprochez-vous de l'administration.
        </p>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        {enfants.map((e) => (
          <Link
            key={e.matricule}
            to={`/mon-espace/enfants/${e.matricule}`}
            className="card flex items-center justify-between px-4 py-4 transition hover:shadow-md"
          >
            <div>
              <div className="font-semibold text-heading">{`${e.prenom ?? ''} ${e.nom ?? ''}`.trim()}</div>
              <div className="text-sm text-muted">{e.classe_libelle ?? e.classe ?? '—'}</div>
            </div>
            <ChevronRight size={18} className="text-muted" />
          </Link>
        ))}
      </div>
    </div>
  )
}
