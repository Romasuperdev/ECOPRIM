import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Lock } from 'lucide-react'
import { definirSocieteConsole, fetchConsoleContexte } from './consoleContexteApi'

/**
 * Société administrée, affichée en permanence dans l'en-tête de la console.
 *
 * Le Super Admin la choisit librement — « Toutes les sociétés » étant la console
 * générale. L'Admin Société la voit figée sur la sienne : le sélecteur devient un simple
 * libellé cadenassé, plutôt qu'une liste déroulante qui lui ferait espérer un choix.
 */
export default function SocieteBarre() {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['console-contexte'], queryFn: fetchConsoleContexte, retry: false })

  const changer = useMutation({
    mutationFn: definirSocieteConsole,
    onSuccess: () => {
      // La société borne les établissements, les utilisateurs et les affectations :
      // on invalide tout le cache de la console plutôt que d'énumérer les écrans.
      qc.invalidateQueries()
    },
  })

  if (!data) return null

  if (data.societe_verrouillee) {
    return (
      <span
        className="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium"
        style={{ borderColor: 'var(--border)' }}
        title="Vous administrez uniquement cette société"
      >
        <Lock size={12} />
        {data.societe_nom ?? data.societe_code}
      </span>
    )
  }

  return (
    <label
      className="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium"
      style={{ borderColor: 'var(--border)' }}
      title="Société administrée"
    >
      <Building2 size={13} />
      <select
        className="bg-transparent text-xs font-medium outline-none"
        value={data.societe_code ?? ''}
        disabled={changer.isPending}
        onChange={(e) => changer.mutate(e.target.value)}
      >
        <option value="">Toutes les sociétés</option>
        {data.societes?.map((s) => (
          <option key={s.code} value={s.code}>{s.nom}</option>
        ))}
      </select>
    </label>
  )
}
