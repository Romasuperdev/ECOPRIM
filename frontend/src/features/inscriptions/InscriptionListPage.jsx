import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import Select from '../../components/ui/Select'
import { fetchInscriptions } from './inscriptionsApi'

const TYPE_LABELS = {
  inscription: 'Inscription',
  reinscription: 'Réinscription',
  transfert_entrant: 'Transfert entrant',
  transfert_sortant: 'Transfert sortant',
}

export default function InscriptionListPage() {
  const [type, setType] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['inscriptions-list', type],
    queryFn: () => fetchInscriptions(type ? { type } : {}),
  })

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Inscriptions, réinscriptions & transferts</h1>
      </div>

      <div className="mb-6 max-w-xs">
        <Select label="Filtrer par type" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">Tous les mouvements</option>
          {Object.entries(TYPE_LABELS).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </Select>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Élève</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Année scolaire</th>
              <th className="px-4 py-3 font-medium">Classe</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {!isLoading && data?.data.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                  Aucun mouvement enregistré.
                </td>
              </tr>
            )}
            {data?.data.map((mouvement) => (
              <tr key={mouvement.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">
                  {mouvement.eleve?.prenom} {mouvement.eleve?.nom}
                </td>
                <td className="px-4 py-3">
                  <span className="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
                    {TYPE_LABELS[mouvement.type] ?? mouvement.type}
                  </span>
                </td>
                <td className="px-4 py-3 text-slate-600">{mouvement.date_mouvement}</td>
                <td className="px-4 py-3 text-slate-600">{mouvement.annee_scolaire?.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{mouvement.classe?.nom ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
