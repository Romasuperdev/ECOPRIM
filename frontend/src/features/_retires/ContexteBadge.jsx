import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Building2 } from 'lucide-react'
import { fetchContexte } from './contexteApi'

/** Rappel permanent de l'établissement de travail, affiché dans l'en-tête. */
export default function ContexteBadge() {
  const { data } = useQuery({ queryKey: ['contexte'], queryFn: fetchContexte, retry: false })

  if (!data) return null

  return (
    <Link
      to="/choisir-etablissement"
      className="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium transition hover:opacity-80"
      style={{ borderColor: 'var(--border)' }}
      title="Changer d'établissement"
    >
      <Building2 size={13} />
      {data.etablissement_code
        ? <span>{data.etablissement_nom}</span>
        : <span className="text-muted">Aucun établissement sélectionné</span>}
    </Link>
  )
}
