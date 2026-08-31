import { useQuery } from '@tanstack/react-query'
import { fetchAnneesScolairesList } from './anneesScolairesApi'

// Lecture seule : les années académiques proviennent d'ECONOMAT (T_ANNEEACADEMIQUE).
export default function AnneeScolaireListPage() {
  const { data: annees, isLoading } = useQuery({
    queryKey: ['annees-scolaires'],
    queryFn: fetchAnneesScolairesList,
  })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Années scolaires</h1>
        <p className="mt-1 text-sm text-slate-500">Consultation (source : ECONOMAT).</p>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Libellé</th>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Début</th>
              <th className="px-4 py-3 font-medium">Fin</th>
              <th className="px-4 py-3 font-medium">Statut</th>
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
            {!isLoading && annees?.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                  Aucune année scolaire.
                </td>
              </tr>
            )}
            {annees?.map((annee) => (
              <tr key={annee.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">{annee.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{annee.code_annee ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{annee.date_debut ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{annee.date_fin ?? '—'}</td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-1">
                    {annee.active && (
                      <span className="rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">
                        Active
                      </span>
                    )}
                    {annee.cloturee && (
                      <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
                        Clôturée
                      </span>
                    )}
                    {annee.cloture_partielle && !annee.cloturee && (
                      <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
                        Clôture partielle
                      </span>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
