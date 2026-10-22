import { useQuery } from '@tanstack/react-query'
import { Bell } from 'lucide-react'
import MessageErreur from '../../components/ui/MessageErreur'
import VersionCarte from './VersionCarte'
import { CLES_NOUVEAUTES, fetchNouveautes } from './nouveautesApi'

/** Nouveautés — toutes les versions publiées, la plus récente d'abord. Lecture seule. */
export default function NouveautesPage() {
  const { data, isLoading, error } = useQuery({ queryKey: CLES_NOUVEAUTES.liste, queryFn: fetchNouveautes })

  return (
    <div>
      <header className="mb-5">
        <h1 className="flex items-center gap-2.5 text-2xl font-bold text-heading">
          <Bell size={24} strokeWidth={2.5} style={{ color: 'var(--brand-accent)' }} />
          Nouveautés
        </h1>
        <p className="mt-1 max-w-3xl text-sm font-medium text-muted">
          Ce qui a changé dans l’application, version après version.
        </p>
      </header>

      {isLoading && <p className="font-medium text-muted">Chargement…</p>}
      {error && <MessageErreur erreur={error} />}
      {data?.length === 0 && (
        <p className="card rounded-2xl px-5 py-8 text-center text-sm font-medium text-muted">
          Aucune note de version publiée pour l’instant.
        </p>
      )}
      <div className="space-y-4">
        {data?.map((v) => <VersionCarte key={v.id} version={v} />)}
      </div>
    </div>
  )
}
